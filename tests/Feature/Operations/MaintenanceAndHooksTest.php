<?php

namespace Tests\Feature\Operations;

use App\Models\Asset;
use App\Models\Domain;
use App\Models\IntegrationOutbox;
use App\Models\Location;
use App\Models\MaintenanceRecord;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class MaintenanceAndHooksTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_asset_inspection_is_stored_and_listed_for_that_asset_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $asset] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $other = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $asset->location_id,
            'name' => 'Boat B',
        ]);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id.'/maintenance-records'), [
            'inspected_at' => '2026-06-16T11:00:00Z',
            'summary' => 'Hull is sound.',
        ])->assertCreated()
            ->assertJsonPath('data.asset_id', $asset->id)
            ->assertJsonPath('data.pool_id', null)
            ->assertJsonPath('data.summary', 'Hull is sound.');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id.'/maintenance-records'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.summary', 'Hull is sound.');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$other->id.'/maintenance-records'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, MaintenanceRecord::query()->count());
        $this->assertSame('active', $asset->fresh()->status->value);
        app(TenantManager::class)->forget();

        $window = $this->postJson($this->tenantUrl('dock.test', '/api/v1/maintenance-windows'), [
            'asset_id' => $asset->id,
            'starts_at' => '2026-06-17T15:00:00Z',
            'ends_at' => '2026-06-17T16:00:00Z',
            'reason' => 'Oil change',
        ])->assertCreated();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/maintenance-windows'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $window->json('data.id'))
            ->assertJsonPath('data.0.reason', 'Oil change');
    }

    public function test_booking_actions_queue_hooks_and_overdue_is_recorded_once(): void
    {
        Http::fake();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $asset] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T18:00:00Z',
            'ends_at' => '2026-06-16T19:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'hooks-1',
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout'))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-16 19:05:00', 'UTC'));
        $this->artisan('rentals:flag-overdue')->assertSuccessful();
        $this->artisan('rentals:flag-overdue')->assertSuccessful();
        $this->artisan('integrations:dispatch')->assertSuccessful();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/return'))->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/incidents'), [
            'summary' => 'Scraped the dock.',
            'occurred_at' => '2026-06-16T19:10:00Z',
            'asset_id' => $asset->id,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/cancel'))->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.confirmed')->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.checked_out')->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'rental.overdue')->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.returned')->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.cancelled')->count());
        $this->assertSame(1, IntegrationOutbox::query()->where('kind', 'rental.overdue')->count());
        $overdue = NotificationHook::query()->where('kind', 'rental.overdue')->firstOrFail();
        $this->assertSame('skipped', $overdue->delivery_status);
        $this->assertSame('No transactional email provider is configured.', $overdue->delivery_error);
        app(TenantManager::class)->forget();

        Mail::assertNothingSent();
        Http::assertNothingSent();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/notification-hooks?booking_id='.$bookingId))
            ->assertOk()
            ->assertJsonFragment(['kind' => 'booking.confirmed'])
            ->assertJsonFragment(['kind' => 'rental.overdue']);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/incidents'))
            ->assertOk()
            ->assertJsonPath('data.0.summary', 'Scraped the dock.')
            ->assertJsonPath('data.0.asset_id', $asset->id);
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset}
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

        return [$workspace, $service, $asset];
    }
}
