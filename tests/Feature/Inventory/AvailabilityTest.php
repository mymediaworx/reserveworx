<?php

namespace Tests\Feature\Inventory;

use App\Models\Asset;
use App\Models\Blackout;
use App\Models\Location;
use App\Models\Pool;
use App\Models\Service;
use App\Services\Inventory\OpeningHours;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_hours_blackouts_and_requirements_follow_the_location(): void
    {
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
        ]);
        $asset = Asset::factory()->create([
            'location_id' => $location->id,
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Guide',
        ]);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/schedules'), [
            'owner_type' => 'location',
            'owner_id' => $location->id,
            'weekday' => 1,
            'opens_local' => '09:00',
            'closes_local' => '17:00',
        ])->assertCreated();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/blackouts'), [
            'location_id' => $location->id,
            'starts_on' => '2026-03-08',
            'ends_on' => '2026-03-08',
            'reason' => 'Closed for the time change',
        ])->assertCreated();

        $service = $this->postJson($this->tenantUrl('dock.test', '/api/v1/services'), [
            'location_id' => $location->id,
            'name' => 'Guided paddle',
            'kind' => 'tour',
        ])->assertCreated()->json('data');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service['id'].'/requirements'), [
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
            'per_participants' => 8,
        ])->assertCreated();

        $open = $this->getJson($this->tenantUrl('dock.test', '/api/v1/availability?'.http_build_query([
            'service_id' => $service['id'],
            'starts_at' => '2026-03-09T14:00:00Z',
            'ends_at' => '2026-03-09T16:00:00Z',
        ])))->assertOk()->json('data');

        $this->assertFalse($open['authoritative']);
        $this->assertTrue($open['open']);
        $this->assertFalse($open['blackout']);

        $closed = $this->getJson($this->tenantUrl('dock.test', '/api/v1/availability?'.http_build_query([
            'service_id' => $service['id'],
            'starts_at' => '2026-03-09T22:00:00Z',
            'ends_at' => '2026-03-09T23:00:00Z',
        ])))->assertOk()->json('data');
        $this->assertFalse($closed['open']);

        $blackout = $this->getJson($this->tenantUrl('dock.test', '/api/v1/availability?'.http_build_query([
            'service_id' => $service['id'],
            'starts_at' => '2026-03-08T14:00:00Z',
            'ends_at' => '2026-03-08T16:00:00Z',
        ])))->assertOk()->json('data');
        $this->assertTrue($blackout['blackout']);
    }

    public function test_a_foreign_location_cannot_own_a_blackout_or_service(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $foreign = Location::factory()->create(['tenant_id' => $beta['tenant']->id]);
        $this->actingAsWorkspace($alpha);

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/blackouts'), [
            'location_id' => $foreign->id,
            'starts_on' => '2026-07-04',
            'ends_on' => '2026-07-04',
        ])->assertUnprocessable();

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/services'), [
            'location_id' => $foreign->id,
            'name' => 'Sneaky',
            'kind' => 'rental',
        ])->assertUnprocessable();

        $this->assertSame(0, Blackout::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Service::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_new_york_transitions_and_multi_day_spans_use_local_dates(): void
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);

        Blackout::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'starts_on' => '2026-03-08',
            'ends_on' => '2026-03-08',
        ]);

        $hours = app(OpeningHours::class);
        $springSpan = $hours->assess(
            $location,
            CarbonImmutable::parse('2026-03-07 10:00:00', 'America/New_York')->utc(),
            CarbonImmutable::parse('2026-03-09 10:00:00', 'America/New_York')->utc(),
        );
        $this->assertTrue($springSpan['blackout']);

        $springUtc = CarbonImmutable::parse('2026-03-07 10:00:00', 'America/New_York')->utc();
        $springEnd = CarbonImmutable::parse('2026-03-09 10:00:00', 'America/New_York')->utc();
        $this->assertSame('2026-03-07 15:00:00', $springUtc->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 14:00:00', $springEnd->format('Y-m-d H:i:s'));

        Blackout::query()->delete();
        Blackout::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
        ]);

        $fallSpan = $hours->assess(
            $location,
            CarbonImmutable::parse('2026-10-31 10:00:00', 'America/New_York')->utc(),
            CarbonImmutable::parse('2026-11-02 10:00:00', 'America/New_York')->utc(),
        );
        $this->assertTrue($fallSpan['blackout']);

        $fallStart = CarbonImmutable::parse('2026-10-31 10:00:00', 'America/New_York')->utc();
        $fallEnd = CarbonImmutable::parse('2026-11-02 10:00:00', 'America/New_York')->utc();
        $this->assertSame('2026-10-31 14:00:00', $fallStart->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 15:00:00', $fallEnd->format('Y-m-d H:i:s'));

        $pool = Pool::factory()->create([
            'location_id' => $location->id,
            'tenant_id' => $workspace['tenant']->id,
        ]);
        $this->assertNotSame($location->id, $pool->id);
        app(TenantManager::class)->forget();
    }
}
