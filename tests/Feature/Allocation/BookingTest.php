<?php

namespace Tests\Feature\Allocation;

use App\Enums\BookingStatus;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_hold_blocks_the_asset_until_it_expires_and_confirm_allocates(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $body = [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'hold-1',
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ];

        $first = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $body)
            ->assertCreated()
            ->json('data');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $body)
            ->assertCreated()
            ->assertJsonPath('data.id', $first['id']);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            ...$body,
            'idempotency_key' => 'hold-2',
            'participants' => 1,
        ])->assertStatus(409);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            ...$body,
            'participants' => 3,
        ])->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, Hold::query()->count());
        $this->assertSame(0, Allocation::query()->count());

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first['id'].'/confirm'))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Hold::query()->count());
        $this->assertSame(1, Allocation::query()->count());

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/calendar?'.http_build_query([
            'view' => 'day',
            'on' => '2026-06-15',
            'asset_id' => $service->requirements->first()->resource_id,
        ])))->assertOk()->assertJsonPath('data.bookings.0.id', $first['id']);

        app(TenantManager::class)->forget();
    }

    public function test_an_expired_hold_does_not_block_and_cannot_be_revived(): void
    {
        [$workspace, $service, $asset] = $this->rental();
        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);

        $booking = Booking::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'status' => 'held',
            'payment_status' => 'unpaid',
            'operational_status' => 'not_arrived',
            'starts_at' => '2026-06-15 15:00:00',
            'ends_at' => '2026-06-15 16:00:00',
            'participants' => 1,
            'payload_hash' => str_repeat('a', 64),
        ]);
        Hold::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'starts_at' => '2026-06-15 15:00:00',
            'ends_at' => '2026-06-15 16:00:00',
            'expires_at' => now()->subMinute(),
        ]);

        $replacement = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'fresh',
        ])->assertCreated()->json('data');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/confirm'))
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'payment_exception');

        $this->assertSame(BookingStatus::PaymentException, $booking->fresh()->status);
        $this->assertNotSame($booking->id, $replacement['id']);

        Artisan::call('holds:purge-expired');
        $this->assertSame(0, Hold::withoutGlobalScope(TenantScope::class)->where('expires_at', '<=', now())->count());
        app(TenantManager::class)->forget();
    }

    public function test_an_extension_conflict_keeps_the_booking_and_offers_a_shorter_end(): void
    {
        [$workspace, $service, $asset] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $first = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'base',
        ])->assertCreated()->json('data');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first['id'].'/confirm'))->assertOk();

        $later = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Later',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $later->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        $second = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $later->id,
            'starts_at' => '2026-06-15T17:00:00Z',
            'ends_at' => '2026-06-15T18:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'later',
        ])->assertCreated()->json('data');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second['id'].'/confirm'))->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first['id'].'/extend'), [
            'ends_at' => '2026-06-15T17:30:00Z',
        ])->assertStatus(409)
            ->assertJsonPath('offers.shorter_ends_at', '2026-06-15T16:45:00Z')
            ->assertJsonPath('data.ends_at', '2026-06-15T16:00:00Z');
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
        $service->load('requirements');
        app(TenantManager::class)->forget();

        return [$workspace, $service, $asset];
    }
}
