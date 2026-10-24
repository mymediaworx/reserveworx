<?php

namespace Tests\Feature\Widget;

use App\Domain\Payments\StripeGateway;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
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
        URL::forceRootUrl(null);
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
            ->assertSee('That time is no longer available.')
            ->assertSee('Other times and equipment')
            ->assertSee('Jun 15, 4:00 PM')
            ->assertSee('Jun 16, 3:00 PM');
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

    public function test_pay_at_pickup_stays_hidden_until_the_workspace_allows_it(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $token = $this->token($workspace);
        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('widget-pickup-off'));
        $held->assertRedirect();
        $bookingId = $this->bookingId((string) $held->headers->get('Location'));

        $this->get((string) $held->headers->get('Location'))
            ->assertOk()
            ->assertSee('Pay with card')
            ->assertDontSee('Pay at pickup');

        URL::forceRootUrl('http://dock.test');
        $url = URL::temporarySignedRoute('widget.pickup', now()->addMinutes(30), [
            'token' => $token,
            'booking' => $bookingId,
        ]);
        URL::forceRootUrl(null);

        $this->post($url)->assertNotFound();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('held', Booking::query()->findOrFail($bookingId)->status->value);
        $this->assertSame(0, Payment::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_pay_at_pickup_confirms_without_a_card_or_the_online_booking_fee(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/settings'), [
            'pay_at_pickup' => true,
        ])->assertOk()->assertJsonPath('data.pay_at_pickup', true);

        $token = $this->token($workspace);
        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('widget-pickup'));
        $held->assertRedirect();
        $bookingId = $this->bookingId((string) $held->headers->get('Location'));
        $checkout = $this->get((string) $held->headers->get('Location'))->assertOk();
        $checkout->assertSee('Pay with card')
            ->assertSee('Pay at pickup')
            ->assertSee('Pay $100.00 when you arrive')
            ->assertSee('$103.00')
            ->assertSee('Booking fee');

        preg_match_all('/action="([^"]+)"/', (string) $checkout->getContent(), $actions);
        $this->assertCount(2, $actions[1]);
        $pickup = html_entity_decode($actions[1][1]);

        $confirmed = $this->post($pickup)->assertRedirect();
        $this->get((string) $confirmed->headers->get('Location'))
            ->assertOk()
            ->assertSee('Your booking is confirmed.')
            ->assertSee('Balance due at pickup: $100.00')
            ->assertSee('Pay at pickup does not include the online booking fee.')
            ->assertDontSee('Balance due at pickup: $103.00')
            ->assertSee('Status: Confirmed')
            ->assertDontSee('offline_paid')
            ->assertDontSee('due_at_pickup');

        $this->post($pickup)->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($bookingId);
        $this->assertSame('confirmed', $booking->status->value);
        $this->assertSame('due_at_pickup', $booking->payment_status->value);
        $this->assertSame(0, Payment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, PlatformFee::query()->count());
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(10_300, $snapshot->payload['customer_total_cents']);
        $this->assertSame(300, $snapshot->payload['customer_fee_line_cents']);
        app(TenantManager::class)->forget();
    }

    public function test_an_expired_hold_cannot_be_paid_at_pickup(): void
    {
        [$workspace, $service] = $this->pricedRental('dock.test');
        app(TenantManager::class)->set($workspace['tenant']);
        TenantSetting::query()->update(['pay_at_pickup' => true]);
        app(TenantManager::class)->forget();
        $token = $this->token($workspace);
        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), $this->party('widget-pickup-late'));
        $bookingId = $this->bookingId((string) $held->headers->get('Location'));

        URL::forceRootUrl('http://dock.test');
        $url = URL::temporarySignedRoute('widget.pickup', now()->addHour(), [
            'token' => $token,
            'booking' => $bookingId,
        ]);
        URL::forceRootUrl(null);

        $this->travel(20)->minutes();

        $this->post($url)->assertStatus(409)->assertSee('This hold has expired.');
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('held', Booking::query()->findOrFail($bookingId)->status->value);
        $this->assertSame(0, Payment::query()->count());
        app(TenantManager::class)->forget();
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
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function token(array $workspace): string
    {
        $this->actingAsWorkspace($workspace);

        return (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function pricedRental(string $host): array
    {
        [$workspace, $service] = $this->rental($host);
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();

        return [$workspace, $service];
    }

    private function bookingId(string $location): string
    {
        preg_match('#/checkout/([^?]+)#', $location, $matches);

        return $matches[1];
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
