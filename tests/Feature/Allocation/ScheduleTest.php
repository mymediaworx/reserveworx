<?php

namespace Tests\Feature\Allocation;

use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_party_over_the_maximum_is_rejected_and_a_short_party_is_flagged(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        $service->min_participants = 4;
        $service->max_participants = 6;
        $service->save();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 8,
            'idempotency_key' => 'too-big',
        ])->assertStatus(409)->assertJsonPath('message', 'That party is larger than this service allows.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'short',
        ])->assertCreated()
            ->assertJsonPath('data.below_minimum', true)
            ->assertJsonPath('data.created_by', $workspace['user']->id);

        app(TenantManager::class)->forget();
    }

    public function test_calendar_views_filter_by_window_and_staff_member(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $ids = [];

        foreach ([
            ['2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z'],
            ['2026-06-20T15:00:00Z', '2026-06-20T16:00:00Z'],
            ['2026-07-05T15:00:00Z', '2026-07-05T16:00:00Z'],
        ] as $index => [$start, $end]) {
            $ids[] = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
                'service_id' => $service->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'participants' => 1,
                'idempotency_key' => 'cal-'.$index,
            ])->assertCreated()->json('data.id');
        }

        $week = $this->getJson($this->tenantUrl('dock.test', '/api/v1/calendar?'.http_build_query([
            'view' => 'week',
            'on' => '2026-06-15',
        ])))->assertOk();
        $this->assertEqualsCanonicalizing([$ids[0], $ids[1]], array_column($week->json('data.bookings'), 'id'));
        $this->assertSame('2026-06-15T00:00:00Z', $week->json('data.starts_at'));
        $this->assertSame('2026-06-22T00:00:00Z', $week->json('data.ends_at'));

        $agenda = $this->getJson($this->tenantUrl('dock.test', '/api/v1/calendar?'.http_build_query([
            'view' => 'agenda',
            'on' => '2026-06-15',
        ])))->assertOk();
        $this->assertEqualsCanonicalizing([$ids[0], $ids[1]], array_column($agenda->json('data.bookings'), 'id'));

        $month = $this->getJson($this->tenantUrl('dock.test', '/api/v1/calendar?'.http_build_query([
            'view' => 'month',
            'on' => '2026-06-15',
        ])))->assertOk();
        $this->assertEqualsCanonicalizing([$ids[0], $ids[1]], array_column($month->json('data.bookings'), 'id'));
        $this->assertSame('2026-06-01T00:00:00Z', $month->json('data.starts_at'));
        $this->assertSame('2026-07-01T00:00:00Z', $month->json('data.ends_at'));

        $day = $this->getJson($this->tenantUrl('dock.test', '/api/v1/calendar?'.http_build_query([
            'view' => 'day',
            'on' => '2026-06-15',
        ])))->assertOk();
        $this->assertSame([$ids[0]], array_column($day->json('data.bookings'), 'id'));

        $outsider = User::factory()->create();
        app(TenantManager::class)->set($workspace['tenant']);
        Booking::query()->whereKey($ids[1])->update(['created_by' => $outsider->id]);
        app(TenantManager::class)->forget();

        $staff = $this->getJson($this->tenantUrl('dock.test', '/api/v1/calendar?'.http_build_query([
            'view' => 'month',
            'on' => '2026-06-15',
            'staff_user_id' => $workspace['user']->id,
        ])))->assertOk();
        $this->assertSame([$ids[0]], array_column($staff->json('data.bookings'), 'id'));
    }

    public function test_reschedule_and_reassign_keep_the_original_when_the_target_is_taken(): void
    {
        [$workspace, $service, $asset, $other] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $first = $this->hold($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'first');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/confirm'))->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/reschedule'), [
            'starts_at' => '2026-06-15T18:00:00Z',
            'ends_at' => '2026-06-15T19:00:00Z',
        ])->assertOk()
            ->assertJsonPath('data.starts_at', '2026-06-15T18:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-06-15T19:00:00Z');

        $blocker = $this->hold($service, '2026-06-15T20:00:00Z', '2026-06-15T21:00:00Z', 'blocker');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$blocker.'/confirm'))->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/reschedule'), [
            'starts_at' => '2026-06-15T20:30:00Z',
            'ends_at' => '2026-06-15T21:30:00Z',
        ])->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('2026-06-15 18:00:00', Booking::query()->findOrFail($first)->starts_at->utc()->format('Y-m-d H:i:s'));

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/reassign'), [
            'from_asset_id' => $asset->id,
            'to_asset_id' => $other->id,
        ])->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($other->id, Allocation::query()->where('booking_id', $first)->value('asset_id'));

        $taken = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Other boat',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $taken->id,
            'resource_type' => 'asset',
            'resource_id' => $other->id,
            'quantity' => 1,
        ]);
        $occupied = $this->hold($taken, '2026-06-16T15:00:00Z', '2026-06-16T16:00:00Z', 'occupied');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$occupied.'/confirm'))->assertOk();

        $movable = $this->hold($service, '2026-06-16T15:00:00Z', '2026-06-16T16:00:00Z', 'movable');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$movable.'/confirm'))->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$movable.'/reassign'), [
            'from_asset_id' => $asset->id,
            'to_asset_id' => $other->id,
        ])->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($asset->id, Allocation::withoutGlobalScope(TenantScope::class)->where('booking_id', $movable)->value('asset_id'));
        app(TenantManager::class)->forget();
    }

    private function hold(Service $service, string $start, string $end, string $key): string
    {
        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => 1,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset}
     */
    private function rental(): array
    {
        [$workspace, $service, $asset] = $this->pair();

        return [$workspace, $service, $asset];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset, 3: Asset}
     */
    private function pair(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Boats',
            'slug' => 'boats',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat A',
        ]);
        $other = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat B',
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

        return [$workspace, $service, $asset, $other];
    }
}
