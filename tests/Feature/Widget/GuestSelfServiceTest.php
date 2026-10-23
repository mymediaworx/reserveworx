<?php

namespace Tests\Feature\Widget;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GuestSelfServiceTest extends TestCase
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

    public function test_a_guest_can_look_up_and_cancel_only_inside_the_published_window(): void
    {
        [$workspace, $service, $token] = $this->bookable();
        $bookingId = $this->hold($token, '15:00', 'guest-1');

        $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/lookup'), [
            'reference' => $bookingId,
            'email' => 'other@example.com',
        ])->assertNotFound()->assertSee('No booking matches that reference and email.');

        $found = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/lookup'), [
            'reference' => $bookingId,
            'email' => 'Ada@example.com',
        ]);
        $found->assertRedirect();
        $manage = (string) $found->headers->get('Location');
        $this->get($this->tenantUrl('dock.test', '/book/'.$token.'/manage/'.$bookingId))->assertForbidden();
        $this->get($manage)->assertOk()->assertSee('held')->assertSee('Online cancellation is not available');

        app(TenantManager::class)->set($workspace['tenant']);
        TenantSetting::query()->update(['guest_cancel_before_minutes' => 60]);
        app(TenantManager::class)->forget();

        $page = $this->get($manage)->assertOk();
        preg_match('/action="([^"]*cancel[^"]*)"/', (string) $page->getContent(), $cancel);
        $this->post(html_entity_decode($cancel[1]))->assertOk()->assertSee('cancelled');
        $this->post(html_entity_decode($cancel[1]))->assertStatus(409)->assertSee('can no longer be cancelled');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('cancelled', Booking::query()->findOrFail($bookingId)->status->value);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.guest_cancelled')->count());
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.cancelled')->count());
        app(TenantManager::class)->forget();

        $soon = $this->hold($token, '12:30', 'guest-soon');
        $soonPage = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/lookup'), [
            'reference' => $soon,
            'email' => 'ada@example.com',
        ])->assertRedirect();
        $soonManage = $this->get((string) $soonPage->headers->get('Location'))->assertOk();
        preg_match('/action="([^"]*cancel[^"]*)"/', (string) $soonManage->getContent(), $soonCancel);
        $this->assertArrayNotHasKey(1, $soonCancel);
    }

    public function test_a_guest_can_reschedule_a_confirmed_booking(): void
    {
        [$workspace, $service, $token] = $this->bookable();
        $bookingId = $this->hold($token, '15:00', 'guest-move');
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        $found = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/lookup'), [
            'reference' => $bookingId,
            'email' => 'ada@example.com',
        ])->assertRedirect();
        $manage = $this->get((string) $found->headers->get('Location'))->assertOk()->assertSee('Reschedule');
        preg_match('/action="([^"]*reschedule[^"]*)"/', (string) $manage->getContent(), $action);

        $this->post(html_entity_decode($action[1]), [
            'date' => '2026-06-15',
            'start' => '17:00',
            'duration_minutes' => 60,
        ])->assertOk()->assertSee('2026-06-15T17:00:00');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.guest_rescheduled')->count());
        app(TenantManager::class)->forget();
    }

    private function hold(string $token, string $start, string $key): string
    {
        $response = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$this->serviceId), [
            'date' => '2026-06-15',
            'start' => $start,
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ])->assertRedirect();
        preg_match('#/checkout/([^?]+)#', (string) $response->headers->get('Location'), $matches);

        return $matches[1];
    }

    private string $serviceId = '';

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: string}
     */
    private function bookable(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
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
        $this->serviceId = $service->id;

        return [$workspace, $service, $token];
    }
}
