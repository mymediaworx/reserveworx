<?php

namespace Tests\Feature\EndToEnd;

use App\Enums\FeePayer;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

/**
 * Spec Section 15 phase 7. One guest books on a workspace's public page.
 * The other workspace never sees that visit, and pay at pickup writes no card fee.
 */
class CustomerBookingJourneyTest extends TestCase
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

    public function test_a_guest_books_on_one_workspace_and_the_other_never_sees_it(): void
    {
        [$dock, $dockService] = $this->rental('dock.test', 'Dock Works', 'Hourly boat', 'Boat A');
        [$inland, $inlandService] = $this->rental('inland.test', 'Inland Paddle', 'Creek canoe', 'Canoe A');
        $this->enablePickup($dock);
        $this->setFeePayer($inland, FeePayer::OperatorAbsorbed);
        $this->price($dock, 'dock.test', $dockService, 10_000, 'weekday');
        $this->price($inland, 'inland.test', $inlandService, 8_000, 'always');

        $dockHome = $this->get($this->tenantUrl('dock.test', '/'));
        $dockHome->assertOk()
            ->assertSee('<h1>Dock Works</h1>', false)
            ->assertSee('Hourly boat')
            ->assertSee('$103.00')
            ->assertSee('Booking fee included')
            ->assertDontSee('Inland Paddle')
            ->assertDontSee('Creek canoe')
            ->assertDontSee('$80.00');
        $this->assertStringNotContainsString('laravel_session', (string) $dockHome->headers->get('set-cookie'));

        $dockToken = $this->hostedToken((string) $dockHome->getContent());
        $this->get($this->tenantUrl('inland.test', '/book/'.$dockToken))->assertNotFound();

        $inlandHome = $this->get($this->tenantUrl('inland.test', '/'));
        $inlandHome->assertOk()
            ->assertSee('<h1>Inland Paddle</h1>', false)
            ->assertSee('Creek canoe')
            ->assertSee('$80.00')
            ->assertDontSee('Booking fee included')
            ->assertDontSee('Dock Works')
            ->assertDontSee('Hourly boat')
            ->assertDontSee('$103.00')
            ->assertDontSee('Ada Dock');

        $book = $this->tenantUrl('dock.test', '/book/'.$dockToken.'/services/'.$dockService->id);
        $this->get($book.'?date=2026-06-15&duration_minutes=60&participants=1')
            ->assertOk()
            ->assertSee('$103.00')
            ->assertSee('Booking fee included')
            ->assertDontSee('Creek canoe');

        $held = $this->post($book, [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada Dock',
            'customer_email' => 'ada.dock@example.com',
            'idempotency_key' => 'journey-dock',
        ])->assertRedirect();
        $checkoutUrl = (string) $held->headers->get('Location');
        $bookingId = $this->bookingId($checkoutUrl);

        $checkout = $this->get($checkoutUrl)->assertOk();
        $checkout->assertSee('Booking fee')
            ->assertSee('$3.00')
            ->assertSee('$100.00')
            ->assertSee('$103.00')
            ->assertSee('Pay at pickup')
            ->assertDontSee('Creek canoe');

        $pickup = $this->formAction((string) $checkout->getContent(), 'Pay at pickup');
        $confirmed = $this->post($pickup)->assertRedirect();
        $confirmation = (string) $confirmed->headers->get('Location');
        $this->get($confirmation)
            ->assertOk()
            ->assertSee('Your booking is confirmed.')
            ->assertSee('Balance due at pickup: $100.00')
            ->assertSee('Pay at pickup does not include the online booking fee.')
            ->assertSee('Full reference: '.$bookingId)
            ->assertSee('Hourly boat')
            ->assertDontSee('Inland Paddle')
            ->assertDontSee('Creek canoe')
            ->assertDontSee('ada.dock@example.com');
        $this->post($pickup)->assertRedirect();
        $this->get($this->tenantUrl('inland.test', (string) parse_url($confirmation, PHP_URL_PATH)))->assertNotFound();

        app(TenantManager::class)->set($dock['tenant']);
        $booking = Booking::query()->findOrFail($bookingId);
        $this->assertSame('confirmed', $booking->status->value);
        $this->assertSame('due_at_pickup', $booking->payment_status->value);
        $this->assertSame($dock['tenant']->id, $booking->tenant_id);
        app(TenantManager::class)->forget();

        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(1, Booking::withoutGlobalScope(TenantScope::class)->count());

        $this->actingAs($dock['user']);
        $this->get($this->tenantUrl('dock.test', '/staff/bookings'))
            ->assertOk()
            ->assertSee('Ada Dock')
            ->assertSee('Hourly boat')
            ->assertDontSee('Creek canoe');
        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$bookingId))
            ->assertOk()
            ->assertSee('Ada Dock')
            ->assertDontSee('Creek canoe');

        $this->actingAs($inland['user']);
        $this->get($this->tenantUrl('inland.test', '/staff/bookings'))
            ->assertOk()
            ->assertDontSee('Ada Dock')
            ->assertDontSee('ada.dock@example.com')
            ->assertDontSee('Hourly boat');
        $this->get($this->tenantUrl('inland.test', '/staff/bookings/'.$bookingId))->assertNotFound();
        $this->get($this->tenantUrl('inland.test', '/'))
            ->assertOk()
            ->assertDontSee('Ada Dock')
            ->assertDontSee('Hourly boat');
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function enablePickup(array $workspace): void
    {
        $this->actingAsWorkspace($workspace);
        $this->patchJson($this->tenantUrl($workspace['domain']->hostname, '/api/v1/settings'), [
            'pay_at_pickup' => true,
        ])->assertOk()->assertJsonPath('data.pay_at_pickup', true);
        app(TenantManager::class)->forget();
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function setFeePayer(array $workspace, FeePayer $payer): void
    {
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['fee_payer' => $payer->value]);
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function price(array $workspace, string $host, Service $service, int $cents, string $window): void
    {
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Standard',
            'kind' => 'base',
            'amount_cents' => $cents,
            'window' => $window,
        ])->assertCreated();
        app(TenantManager::class)->forget();
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(string $host, string $workspaceName, string $serviceName, string $assetName): array
    {
        $workspace = $this->workspace($host, tenantAttributes: ['name' => $workspaceName]);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => $assetName,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => $serviceName,
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

    private function hostedToken(string $html): string
    {
        $this->assertSame(1, preg_match('#/book/(hosted_[a-f0-9]{64})/#', $html, $matches));

        return $matches[1];
    }

    private function bookingId(string $location): string
    {
        $this->assertSame(1, preg_match('#/checkout/([^?]+)#', $location, $matches));

        return $matches[1];
    }

    private function formAction(string $html, string $button): string
    {
        $pattern = '/<form method="post" action="([^"]+)"[^>]*>\s*<button[^>]*>'.preg_quote($button, '/').'/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Missing the '.$button.' form.');

        return html_entity_decode($matches[1]);
    }
}
