<?php

namespace Tests\Feature\Widget;

use App\Domain\Payments\StripeGateway;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class BookingWidgetTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_guest_sees_the_booking_fee_before_payment_and_does_not_use_a_session_cookie(): void
    {
        [$workspace, $service] = $this->rental('dock.test');
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();
        $token = $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        $catalog = $this->get($this->tenantUrl('dock.test', '/book/'.$token));
        $catalog->assertOk()->assertSee('Hourly boat')->assertSee('$103.00')->assertSee('Booking fee included');
        $this->assertStringNotContainsString('laravel_session', (string) $catalog->headers->get('set-cookie'));

        $this->get($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id))
            ->assertOk()
            ->assertSee('Duration in minutes');

        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('widget-1'));
        $held->assertRedirect();
        $location = $held->headers->get('Location');
        $this->assertIsString($location);
        preg_match('#/checkout/([^?]+)#', $location, $matches);
        $bookingId = $matches[1];

        $this->get($this->tenantUrl('dock.test', '/book/'.$token.'/checkout/'.$bookingId))->assertForbidden();

        $checkout = $this->get($location);
        $checkout->assertOk()
            ->assertSee('Booking fee')
            ->assertSee('$100.00')
            ->assertSee('$3.00')
            ->assertSee('$103.00')
            ->assertSee('Pay with card');

        preg_match('/action="([^"]+)"/', (string) $checkout->getContent(), $action);
        $pay = $this->post(html_entity_decode($action[1]));
        $pay->assertStatus(409)->assertSee('Online payments are not enabled for this workspace.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('held', Booking::query()->findOrFail($bookingId)->status->value);
        app(TenantManager::class)->forget();

        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('widget-2'))
            ->assertStatus(409)
            ->assertSee('That time is no longer available.');
    }

    public function test_card_checkout_redirects_to_stripe_without_confirming_the_hold(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        [$workspace, $service] = $this->rental('dock.test');
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();
        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();
        $token = $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('widget-pay'));
        $held->assertRedirect();
        $checkout = $this->get((string) $held->headers->get('Location'))->assertOk();
        preg_match('/action="([^"]+)"/', (string) $checkout->getContent(), $action);

        $this->post(html_entity_decode($action[1]))
            ->assertRedirect('https://checkout.stripe.test/pay/cs_test_123');
        $this->assertSame(300, $fake->sessions[0]['params']['payment_intent_data']['application_fee_amount']);
        $this->assertArrayNotHasKey('payment_method_types', $fake->sessions[0]['params']);
    }

    public function test_a_token_works_only_on_its_own_host_and_stops_when_revoked(): void
    {
        [$workspace, $service] = $this->rental('dock.test');
        $this->workspace('beta.test');
        $this->actingAsWorkspace($workspace);
        $issued = $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data');

        $this->get($this->tenantUrl('beta.test', '/book/'.$issued['token']))->assertNotFound();
        $this->get('http://localhost/book/'.$issued['token'])->assertNotFound();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens/'.$issued['id'].'/revoke'))->assertOk();
        $this->get($this->tenantUrl('dock.test', '/book/'.$issued['token']))->assertNotFound();

        $this->get($this->tenantUrl('dock.test', '/book/'.$issued['token'].'/services/'.$service->id))->assertNotFound();
    }

    /**
     * @return array<string, string|int>
     */
    private function party(string $key): array
    {
        return [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ];
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
