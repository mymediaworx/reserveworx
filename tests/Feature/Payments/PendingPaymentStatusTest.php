<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\StripeGateway;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\FakeStripeGateway;
use App\Services\Pricing\BookingPricer;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PendingPaymentStatusTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        Http::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_card_checkout_sets_pending_payment_and_leaves_the_price(): void
    {
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);
        [$workspace, $service] = $this->rental('dock.test');
        $other = $this->otherHold();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 'mina@example.com', 'Mina Checkout', 'pending-hold');
        $this->price($service, $bookingId);
        $this->connect($workspace['tenant']);

        app(TenantManager::class)->set($workspace['tenant']);
        $before = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $services = $before->payload['services_cents'];
        $fee = $before->payload['fee_cents'];
        $total = $before->payload['customer_total_cents'];
        $rules = PriceRule::query()->count();
        app(TenantManager::class)->forget();

        $started = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'card-pending',
        ])->assertOk()->json('data');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment');

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($bookingId);
        $snapshot = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $payment = Payment::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame($services, $snapshot->payload['services_cents']);
        $this->assertSame($fee, $snapshot->payload['fee_cents']);
        $this->assertSame($total, $snapshot->payload['customer_total_cents']);
        $this->assertSame($before->id, $snapshot->id);
        $this->assertSame($rules, PriceRule::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('pending', $payment->status);
        $this->assertSame('stripe', $payment->method);
        $this->assertSame($started['amount_cents'], $payment->amount_cents);
        $this->assertSame($started['fee_cents'], $payment->fee_cents);
        $this->assertTrue(Hold::query()->where('booking_id', $bookingId)->where('expires_at', '>', now())->exists());
        $audits = AuditLog::query()->where('action', 'booking.pending_payment')->get();
        $this->assertCount(1, $audits);
        $this->assertSame($bookingId, $audits->first()?->subject_id);
        $this->assertSame('pending_payment', $audits->first()?->properties['status'] ?? null);
        $this->assertSame('held', Booking::withoutGlobalScope(TenantScope::class)->findOrFail($other)->status->value);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'card-pending',
        ])->assertOk()->assertJsonPath('data.checkout_url', null);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('pending_payment', $booking->fresh()?->status->value);
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.pending_payment')->count());
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 3,
        ])->assertStatus(409)->assertJsonPath('message', 'Only a held or confirmed booking can change party size.');

        app(TenantManager::class)->set($workspace['tenant']);
        PromoCode::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'code' => 'SAVE10',
            'percent_off' => 10,
            'active' => true,
        ]);
        $fresh = Booking::query()->findOrFail($bookingId);
        $this->assertSame(2, $fresh->participants);

        try {
            app(BookingPricer::class)->applyPromo($fresh, $workspace['tenant'], 'SAVE10');
            $this->fail('A pending payment accepted a promo.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('This booking can no longer take a promo code.', $exception->getMessage());
        }

        $kept = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame($services, $kept->payload['services_cents']);
        $this->assertSame($fee, $kept->payload['fee_cents']);
        $this->assertSame($total, $kept->payload['customer_total_cents']);
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, Refund::query()->count());
        app(TenantManager::class)->forget();

        $heldId = $this->hold($service, 'held@example.com', 'Held Guest', 'still-held', '2026-06-15T16:00:00Z', '2026-06-15T17:00:00Z');
        $this->actingAs($workspace['user']);

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$bookingId))
            ->assertOk()
            ->assertSee('Pending payment')
            ->assertSee('Waiting for payment until 12:36 PM')
            ->assertSee('Confirm without payment');

        $this->get($this->tenantUrl('dock.test', '/staff'))
            ->assertOk()
            ->assertSee('href="/staff/bookings?status=waiting&when=all"', false)
            ->assertSee('2 bookings waiting on payment');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings?status=waiting&when=all'))
            ->assertOk()
            ->assertSee('Mina Checkout')
            ->assertSee('Held Guest')
            ->assertDontSee('Other Harbor');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame('pending', Payment::query()->where('booking_id', $bookingId)->firstOrFail()->status);
        $confirmed = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame($total, $confirmed->payload['customer_total_cents']);
        $this->assertSame('held', Booking::query()->findOrFail($heldId)->status->value);
        $this->assertSame('held', Booking::withoutGlobalScope(TenantScope::class)->findOrFail($other)->status->value);
        app(TenantManager::class)->forget();
    }

    public function test_pay_at_pickup_confirms_a_pending_payment_with_no_fee(): void
    {
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);
        [$workspace, $service] = $this->rental('dock.test');
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 'ada@example.com', 'Ada', 'offline-hold');
        $this->price($service, $bookingId);
        $this->connect($workspace['tenant']);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/offline-payment'))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.fee_cents', 0);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, (int) Payment::query()->where('method', 'offline')->sum('fee_cents'));
        app(TenantManager::class)->forget();
    }

    public function test_a_failed_checkout_stays_pending_payment(): void
    {
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);
        [$workspace, $service] = $this->rental('dock.test');
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 'ada@example.com', 'Ada', 'failed-hold');
        $this->price($service, $bookingId);
        $this->connect($workspace['tenant']);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk();

        $this->postWebhook('evt_fail_pending', 'checkout.session.async_payment_failed', [
            'id' => 'cs_test_123',
            'payment_status' => 'unpaid',
            'metadata' => [
                'booking_id' => $bookingId,
                'tenant_id' => $workspace['tenant']->id,
            ],
        ], 'acct_test_123')->assertOk();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('pending', Payment::query()->firstOrFail()->status);
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, Refund::query()->count());
        app(TenantManager::class)->forget();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function postWebhook(string $id, string $type, array $object, ?string $account = null): TestResponse
    {
        return $this->postJson('http://localhost/api/v1/stripe/webhook', [
            'id' => $id,
            'type' => $type,
            'account' => $account,
            'data' => ['object' => $object],
        ], ['Stripe-Signature' => 'test-signature']);
    }

    private function hold(Service $service, string $email, string $name, string $key, string $starts = '2026-06-15T15:00:00Z', string $ends = '2026-06-15T16:00:00Z'): string
    {
        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => 2,
            'idempotency_key' => $key,
            'customer_name' => $name,
            'customer_email' => $email,
        ])->assertCreated()->json('data.id');
    }

    private function price(Service $service, string $bookingId): void
    {
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();
    }

    private function connect(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        StripeAccount::query()->create([
            'tenant_id' => $tenant->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();
    }

    private function otherHold(): string
    {
        [$workspace, $service] = $this->rental('other.test');
        $this->actingAsWorkspace($workspace);

        return $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'other-hold',
            'customer_name' => 'Other Harbor',
            'customer_email' => 'other@example.com',
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(string $host): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat A',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service];
    }
}
