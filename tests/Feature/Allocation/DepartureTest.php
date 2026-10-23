<?php

namespace Tests\Feature\Allocation;

use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class DepartureTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_group_departure_caps_seats_and_a_full_one_can_waitlist(): void
    {
        [$workspace, $service] = $this->tour(seats: 4, minimum: 4);
        $this->actingAsWorkspace($workspace);

        $first = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'seats' => 4,
        ])->assertCreated()->json('data');

        $second = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T15:00:00Z',
            'ends_at' => '2026-06-16T17:00:00Z',
            'seats' => 4,
        ])->assertCreated()->json('data');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/departures?service_id='.$service->id))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $party = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $first['id'],
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'party-a',
        ])->assertCreated()->assertJsonPath('data.departure_id', $first['id'])->json('data');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/departures/'.$first['id']))
            ->assertOk()
            ->assertJsonPath('data.participants', 2)
            ->assertJsonPath('data.below_minimum', true);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $first['id'],
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'party-b',
        ])->assertCreated();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/departures/'.$first['id']))
            ->assertOk()
            ->assertJsonPath('data.participants', 4)
            ->assertJsonPath('data.below_minimum', false);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $first['id'],
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'party-c',
            'join_waitlist' => true,
        ])->assertStatus(202)->assertJsonPath('data.status', 'waiting');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$party['id'].'/confirm'))->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, Booking::query()->count());
        $this->assertSame(1, WaitlistEntry::query()->count());
        $this->assertSame(1, Allocation::query()->count());

        $calendar = $this->getJson($this->tenantUrl('dock.test', '/api/v1/calendar?'.http_build_query([
            'view' => 'week',
            'on' => '2026-06-15',
        ])))->assertOk();
        $this->assertEqualsCanonicalizing(
            [$first['id'], $second['id']],
            array_column($calendar->json('data.departures'), 'id'),
        );

        app(TenantManager::class)->forget();
    }

    public function test_a_private_departure_takes_one_booking_and_a_no_show_frees_it(): void
    {
        [$workspace, $service, $asset] = $this->tour(seats: 6, minimum: null);
        $this->actingAsWorkspace($workspace);
        $departure = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'private' => true,
            'seats' => 6,
        ])->assertCreated()->json('data');

        $booking = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $departure['id'],
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'private-1',
        ])->assertCreated()->json('data');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $departure['id'],
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'private-2',
        ])->assertStatus(409)->assertJsonPath('message', 'That private departure already has a booking.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking['id'].'/confirm'))->assertOk();
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking['id']), [
            'operational_status' => 'checked_in',
        ])->assertOk()->assertJsonPath('data.operational_status', 'checked_in');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking['id'].'/no-show'))
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Allocation::query()->where('asset_id', $asset->id)->count());

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $departure['id'],
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'private-3',
        ])->assertCreated();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures/'.$departure['id'].'/cancel'))
            ->assertStatus(409);

        app(TenantManager::class)->forget();
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset}
     */
    private function tour(int $seats, ?int $minimum): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Guide',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Harbor tour',
            'kind' => 'tour',
            'min_participants' => $minimum,
            'max_participants' => $seats,
            'waitlist_enabled' => true,
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
