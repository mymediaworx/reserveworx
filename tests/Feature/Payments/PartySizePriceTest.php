<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\StripeGateway;
use App\Models\Asset;
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

class PartySizePriceTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_larger_party_is_charged_through_increase_checkout(): void
    {
        Http::fake();
        Mail::fake();
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);

        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 1, 'up');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Each person',
            'kind' => 'per_person',
            'amount_cents' => 10_000,
            'window' => 'always',
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))
            ->assertOk()
            ->assertJsonPath('data.payload.services_cents', 10_000)
            ->assertJsonPath('data.payload.priced_participants', 1);

        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk();
        $this->postWebhook('evt_party_paid', 'checkout.session.completed', [
            'id' => 'cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => 10_300,
            'payment_intent' => 'pi_party',
            'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
        ], 'acct_test_123')->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 2,
        ])->assertOk()->assertJsonPath('data.participants', 2);

        app(TenantManager::class)->set($workspace['tenant']);
        $before = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame(1, $before->payload['priced_participants']);
        $this->assertSame(10_000, $before->payload['services_cents']);
        app(TenantManager::class)->forget();

        $fake->sessionId = 'cs_test_party_increase';
        $fake->sessionUrl = 'https://checkout.stripe.test/pay/cs_test_party_increase';
        $started = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/increase-checkout'), [
            'success_url' => 'https://dock.test/increase',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'party-increase',
        ])->assertOk();
        $this->assertSame(10_300, $started->json('data.amount_cents'));
        $this->assertSame(300, $started->json('data.fee_cents'));

        $this->postWebhook('evt_party_increase', 'checkout.session.completed', [
            'id' => 'cs_test_party_increase',
            'payment_status' => 'paid',
            'amount_total' => 10_300,
            'payment_intent' => 'pi_party_increase',
            'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
        ], 'acct_test_123')->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $snapshot = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame(2, $snapshot->payload['priced_participants']);
        $this->assertSame(20_000, $snapshot->payload['services_cents']);
        $this->assertSame(600, $snapshot->payload['fee_cents']);
        $this->assertSame(20_600, $snapshot->payload['customer_total_cents']);
        $this->assertSame(600, (int) PlatformFee::query()->sum('amount_cents'));
        app(TenantManager::class)->forget();

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_a_smaller_party_is_refunded_through_the_decrease_path(): void
    {
        Http::fake();
        Mail::fake();
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);

        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 2, 'down');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Each person',
            'kind' => 'per_person',
            'amount_cents' => 10_000,
            'window' => 'always',
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))
            ->assertOk()
            ->assertJsonPath('data.payload.services_cents', 20_000)
            ->assertJsonPath('data.payload.priced_participants', 2);

        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk();
        $this->postWebhook('evt_party_down', 'checkout.session.completed', [
            'id' => 'cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => 20_600,
            'payment_intent' => 'pi_party_down',
            'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
        ], 'acct_test_123')->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 1,
        ])->assertOk()->assertJsonPath('data.participants', 1);

        app(TenantManager::class)->set($workspace['tenant']);
        PlatformFee::query()->where('booking_id', $bookingId)->update(['stripe_fee_id' => 'fee_party']);
        app(TenantManager::class)->forget();

        $refunded = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/decrease-refund'), [
            'idempotency_key' => 'party-decrease',
        ])->assertCreated();
        $this->assertSame(10_300, $refunded->json('data.customer_refund_cents'));
        $this->assertSame(300, $refunded->json('data.customer_fee_refund_cents'));
        $this->assertSame(300, $refunded->json('data.application_fee_refund_cents'));
        $this->assertSame(10_000, $refunded->json('data.refund_subtotal_cents'));
        $this->assertSame('partially_refunded', $refunded->json('data.payment_status'));

        app(TenantManager::class)->set($workspace['tenant']);
        $snapshot = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame(1, $snapshot->payload['priced_participants']);
        $this->assertSame(10_000, $snapshot->payload['services_cents']);
        $this->assertSame(20_000, $snapshot->payload['charged_basis_cents']);
        app(TenantManager::class)->forget();

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$bookingId.'/decrease-refund'), [
            'idempotency_key' => 'party-decrease-foreign',
        ])->assertNotFound();

        Mail::assertNothingSent();
        Http::assertNothingSent();
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

    private function hold(Service $service, int $participants, string $key): string
    {
        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => $participants,
            'idempotency_key' => $key,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ])->assertCreated()->json('data.id');
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
