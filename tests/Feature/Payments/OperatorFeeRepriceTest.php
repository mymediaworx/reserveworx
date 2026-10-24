<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\StripeGateway;
use App\Models\Asset;
use App\Models\BookingAddOn;
use App\Models\BookingOperatorFee;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class OperatorFeeRepriceTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_larger_party_reprices_per_person_and_percent_fees_from_the_snapshot(): void
    {
        Http::fake();
        Mail::fake();
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        [$cleaning, $addOn] = $this->prepare($service);

        $bookingId = $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'grow', 2, $addOn))
            ->assertCreated()
            ->json('data.id');
        $this->assertIsString($bookingId);
        $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/pricing'), [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 1_000,
        ])->assertOk()
            ->assertJsonPath('data.payload.basis_cents', 11_700)
            ->assertJsonPath('data.payload.mandatory_operator_fees_cents', 2_300)
            ->assertJsonPath('data.payload.fee_cents', 351)
            ->assertJsonPath('data.payload.customer_total_cents', 12_951)
            ->assertJsonPath('data.payload.add_ons_cents', 400);

        $this->enableCard($workspace);
        $this->pay($workspace, $bookingId, 'evt_fee_paid', 'pi_fee', 12_951);

        $this->patchJson($this->url('/api/v1/operator-fees/'.$cleaning), ['amount_cents' => 900])->assertOk();
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Dock',
            'pricing' => 'flat',
            'amount_cents' => 700,
            'service_id' => $service->id,
        ])->assertCreated();

        $resized = $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 4,
        ])->assertOk();
        $this->assertSame(4, $resized->json('data.participants'));
        $this->assertSame(2_000, $resized->json('data.operator_fees.1.total_cents'));
        $this->assertSame(800, $resized->json('data.add_ons.0.total_cents'));
        $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/pricing'))
            ->assertOk()
            ->assertJsonPath('data.payload.basis_cents', 11_700)
            ->assertJsonPath('data.payload.mandatory_operator_fees_cents', 2_300)
            ->assertJsonPath('data.payload.add_ons_cents', 400);

        $fake->sessionId = 'cs_test_fee_increase';
        $fake->sessionUrl = 'https://checkout.stripe.test/pay/cs_test_fee_increase';
        $started = $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/increase-checkout'), [
            'success_url' => 'https://dock.test/increase',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'fee-increase',
        ])->assertOk();
        $this->assertSame(12_642, $started->json('data.amount_cents'));
        $this->assertSame(342, $started->json('data.fee_cents'));

        $this->postWebhook('evt_fee_increase', 'checkout.session.completed', [
            'id' => 'cs_test_fee_increase',
            'payment_status' => 'paid',
            'amount_total' => 12_230,
            'payment_intent' => 'pi_fee_increase',
            'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
        ], 'acct_test_123')->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $snapshot = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame(20_000, $snapshot->payload['services_cents']);
        $this->assertSame(2_000, $snapshot->payload['discount_cents']);
        $this->assertSame(800, $snapshot->payload['add_ons_cents']);
        $this->assertSame(4_300, $snapshot->payload['mandatory_operator_fees_cents']);
        $this->assertSame(23_100, $snapshot->payload['basis_cents']);
        $this->assertSame(1_800, $snapshot->payload['tax_cents']);
        $this->assertSame(693, $snapshot->payload['fee_cents']);
        $this->assertSame(25_593, $snapshot->payload['customer_total_cents']);
        $this->assertSame(351, $snapshot->payload['application_fee_cents']);
        $this->assertSame(4, $snapshot->payload['priced_participants']);
        $lines = BookingOperatorFee::query()->where('booking_id', $bookingId)->orderBy('name')->get();
        $this->assertSame(['Admin', 'Cleaning', 'Fuel'], $lines->pluck('name')->all());
        $this->assertSame(500, $lines[1]->amount_cents);
        $this->assertSame(2_000, $lines[1]->total_cents);
        $this->assertSame(800, BookingAddOn::query()->where('booking_id', $bookingId)->firstOrFail()->total_cents);
        app(TenantManager::class)->forget();
    }

    public function test_a_smaller_party_refunds_the_fee_drop_and_retotals_the_add_on(): void
    {
        Http::fake();
        Mail::fake();
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        [$cleaning, $addOn] = $this->prepare($service);

        $bookingId = $this->postJson($this->url('/api/v1/bookings'), $this->holdBody($service, 'shrink', 4, $addOn))
            ->assertCreated()
            ->json('data.id');
        $this->assertIsString($bookingId);
        $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/pricing'), [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 1_000,
        ])->assertOk()
            ->assertJsonPath('data.payload.basis_cents', 23_100)
            ->assertJsonPath('data.payload.add_ons_cents', 800)
            ->assertJsonPath('data.payload.mandatory_operator_fees_cents', 4_300)
            ->assertJsonPath('data.payload.fee_cents', 693)
            ->assertJsonPath('data.payload.customer_total_cents', 25_593);

        $this->enableCard($workspace);
        $this->pay($workspace, $bookingId, 'evt_fee_shrink', 'pi_shrink', 25_593);

        $this->patchJson($this->url('/api/v1/operator-fees/'.$cleaning), ['amount_cents' => 900])->assertOk();
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Dock',
            'pricing' => 'flat',
            'amount_cents' => 700,
            'service_id' => $service->id,
        ])->assertCreated();

        $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 2,
        ])->assertOk()->assertJsonPath('data.add_ons.0.total_cents', 400);

        app(TenantManager::class)->set($workspace['tenant']);
        PlatformFee::query()->where('booking_id', $bookingId)->update(['stripe_fee_id' => 'fee_shrink']);
        app(TenantManager::class)->forget();

        $refund = $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/decrease-refund'), [
            'idempotency_key' => 'fee-decrease',
        ])->assertCreated();
        $this->assertSame(12_642, $refund->json('data.customer_refund_cents'));
        $this->assertSame(342, $refund->json('data.customer_fee_refund_cents'));
        $this->assertSame(342, $refund->json('data.application_fee_refund_cents'));
        $this->assertSame(11_400, $refund->json('data.refund_subtotal_cents'));
        $this->assertSame(900, $refund->json('data.refund_tax_cents'));

        app(TenantManager::class)->set($workspace['tenant']);
        $snapshot = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame(11_700, $snapshot->payload['basis_cents']);
        $this->assertSame(23_100, $snapshot->payload['charged_basis_cents']);
        $this->assertSame(351, $snapshot->payload['fee_cents']);
        $this->assertSame(12_951, $snapshot->payload['customer_total_cents']);
        $this->assertSame(693, $snapshot->payload['application_fee_cents']);
        $this->assertSame(400, $snapshot->payload['add_ons_cents']);
        $this->assertSame(2_300, $snapshot->payload['mandatory_operator_fees_cents']);
        $lines = BookingOperatorFee::query()->where('booking_id', $bookingId)->orderBy('name')->get();
        $this->assertSame(['Admin', 'Cleaning', 'Fuel'], $lines->pluck('name')->all());
        $this->assertSame(1_000, $lines[1]->total_cents);
        $this->assertSame(500, $lines[1]->amount_cents);
        $this->assertSame(400, BookingAddOn::query()->where('booking_id', $bookingId)->firstOrFail()->total_cents);
        app(TenantManager::class)->forget();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function prepare(Service $service): array
    {
        $this->postJson($this->url('/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'per_person',
            'amount_cents' => 5_000,
            'window' => 'weekday',
        ])->assertCreated();
        $this->postJson($this->url('/api/v1/promo-codes'), [
            'code' => 'save10',
            'percent_off' => 10,
        ])->assertCreated();
        $addOn = $this->postJson($this->url('/api/v1/add-ons'), [
            'name' => 'Dry bag',
            'kind' => 'addon',
            'pricing' => 'per_person',
            'amount_cents' => 200,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($addOn);

        return [$this->catalogFees($service), $addOn];
    }

    private function catalogFees(Service $service): string
    {
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Admin',
            'pricing' => 'flat',
            'amount_cents' => 300,
            'service_id' => $service->id,
        ])->assertCreated();
        $cleaning = $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Cleaning',
            'pricing' => 'per_person',
            'amount_cents' => 500,
            'service_id' => $service->id,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($cleaning);
        $this->postJson($this->url('/api/v1/operator-fees'), [
            'name' => 'Fuel',
            'pricing' => 'percent',
            'percent_bps' => 1_000,
            'location_id' => $service->location_id,
        ])->assertCreated();

        return $cleaning;
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function enableCard(array $workspace): void
    {
        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function pay(array $workspace, string $bookingId, string $event, string $intent, int $amount): void
    {
        $this->postJson($this->url('/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk();
        $this->postWebhook($event, 'checkout.session.completed', [
            'id' => 'cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => $amount,
            'payment_intent' => $intent,
            'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
        ], 'acct_test_123')->assertOk();
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

    /**
     * @return array<string, mixed>
     */
    private function holdBody(Service $service, string $key, int $participants, string $addOnId): array
    {
        return [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => $participants,
            'idempotency_key' => $key,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'add_ons' => [['id' => $addOnId]],
        ];
    }

    private function url(string $path): string
    {
        return $this->tenantUrl('dock.test', $path);
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(): array
    {
        $workspace = $this->workspace('dock.test');
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
