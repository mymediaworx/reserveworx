<?php

namespace Tests\Feature\Fleet;

use App\Livewire\Staff\Setup\Locations;
use App\Models\Booking;
use App\Models\FleetItem;
use App\Models\Location;
use App\Models\LocationFleetReserve;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Booking\SlotFinder;
use App\Services\Fleet\FleetCapacity;
use App\Services\Fleet\FleetLoadSheet;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class FleetDeliveryTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_midnight_return_does_not_take_the_next_fleet_day(): void
    {
        $location = new Location(['timezone' => 'America/New_York']);
        $dates = app(FleetCapacity::class)->dates(
            $location,
            CarbonImmutable::parse('2026-07-04 18:00:00', 'America/New_York')->utc(),
            CarbonImmutable::parse('2026-07-05 00:00:00', 'America/New_York')->utc(),
        );

        $this->assertSame(['2026-07-04'], $dates);

        $across = app(FleetCapacity::class)->dates(
            $location,
            CarbonImmutable::parse('2026-07-04 18:00:00', 'America/New_York')->utc(),
            CarbonImmutable::parse('2026-07-05 00:01:00', 'America/New_York')->utc(),
        );

        $this->assertSame(['2026-07-04', '2026-07-05'], $across);
    }

    public function test_fleet_bookings_share_one_daily_count_across_locations(): void
    {
        $workspace = $this->workspace('fleet.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->beaches($workspace['tenant'], 10);

        $this->book($workspace['tenant'], $shop['northService'], '2026-07-04 10:00:00', 6);
        $this->book($workspace['tenant'], $shop['southService'], '2026-07-04 10:00:00', 4);

        $this->expectException(AllocationUnavailableException::class);
        $this->expectExceptionMessage('That day is fully booked.');
        $this->book($workspace['tenant'], $shop['northService'], '2026-07-04 15:00:00', 1);
    }

    public function test_the_same_fleet_unit_is_not_booked_twice_later_the_same_day(): void
    {
        $workspace = $this->workspace('fleet.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->beaches($workspace['tenant'], 4);

        $this->book($workspace['tenant'], $shop['northService'], '2026-07-04 09:00:00', 4);

        try {
            $this->book($workspace['tenant'], $shop['southService'], '2026-07-04 14:00:00', 1);
            $this->fail('A later booking used a kayak that was already out for the day.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That day is fully booked.', $exception->getMessage());
        }

        $next = $this->book($workspace['tenant'], $shop['southService'], '2026-07-05 09:00:00', 4);
        $this->assertSame(4, $next->participants);
    }

    public function test_a_multi_day_rental_holds_the_fleet_on_every_date_it_covers(): void
    {
        $workspace = $this->workspace('fleet.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->beaches($workspace['tenant'], 2);
        $start = CarbonImmutable::parse('2026-07-04 10:00:00', 'America/New_York')->utc();

        app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $shop['northService']->id,
            $start,
            CarbonImmutable::parse('2026-07-05 10:00:00', 'America/New_York')->utc(),
            2,
            (string) Str::uuid(),
            'Guest',
            'guest@example.com',
        ));

        try {
            $this->book($workspace['tenant'], $shop['southService'], '2026-07-05 15:00:00', 1);
            $this->fail('The next morning still had a kayak that was out overnight.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That day is fully booked.', $exception->getMessage());
        }

        $later = $this->book($workspace['tenant'], $shop['southService'], '2026-07-06 10:00:00', 2);
        $this->assertSame(2, $later->participants);
    }

    public function test_an_expired_hold_releases_the_fleet_day(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-04 08:00:00', 'America/New_York'));
        $workspace = $this->workspace('fleet.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->beaches($workspace['tenant'], 2);
        $this->book($workspace['tenant'], $shop['northService'], '2026-07-04 10:00:00', 2);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-04 08:20:00', 'America/New_York'));
        $again = $this->book($workspace['tenant'], $shop['southService'], '2026-07-04 11:00:00', 2);

        $this->assertSame(2, $again->participants);
    }

    public function test_locations_with_their_own_gear_keep_the_existing_count(): void
    {
        $workspace = $this->workspace('fleet.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $fixed = $this->rentalShop($workspace['tenant'], 2, 'America/New_York');
        $shop = $this->beaches($workspace['tenant'], 2);

        $this->book($workspace['tenant'], $fixed['service'], '2026-07-04 10:00:00', 2);
        $this->book($workspace['tenant'], $fixed['service'], '2026-07-04 14:00:00', 2);
        $this->book($workspace['tenant'], $shop['northService'], '2026-07-04 10:00:00', 2);

        try {
            $this->book($workspace['tenant'], $fixed['service'], '2026-07-04 10:30:00', 1);
            $this->fail('A fixed pool accepted an overlapping booking.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertNotSame('That day is fully booked.', $exception->getMessage());
        }

        try {
            $this->book($workspace['tenant'], $shop['southService'], '2026-07-04 16:00:00', 1);
            $this->fail('Fleet inventory ignored a booking at the other beach.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That day is fully booked.', $exception->getMessage());
        }
    }

    public function test_a_fixed_location_cannot_draw_from_the_fleet(): void
    {
        $workspace = $this->workspace('fleet.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $fixed = $this->rentalShop($workspace['tenant'], 4, 'America/New_York');
        $item = FleetItem::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Touring kayak',
            'quantity' => 4,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $fixed['service']->id,
            'resource_type' => 'fleet',
            'resource_id' => $item->id,
            'quantity' => 1,
            'per_participants' => 1,
        ]);

        $this->expectException(AllocationUnavailableException::class);
        $this->expectExceptionMessage('A required resource is not available.');
        $this->book($workspace['tenant'], $fixed['service'], '2026-07-04 10:00:00', 1);
    }

    public function test_walk_in_units_stay_at_that_location_for_the_counter(): void
    {
        $workspace = $this->workspace('fleet.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->beaches($workspace['tenant'], 10);
        $shop['north']->walk_ins_enabled = true;
        $shop['north']->save();
        LocationFleetReserve::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['north']->id,
            'fleet_item_id' => $shop['item']->id,
            'quantity' => 3,
        ]);

        $this->book($workspace['tenant'], $shop['southService'], '2026-07-04 10:00:00', 7);

        try {
            $this->book($workspace['tenant'], $shop['northService'], '2026-07-04 11:00:00', 1);
            $this->fail('A public booking took a walk-in unit.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That day is fully booked.', $exception->getMessage());
        }

        try {
            $this->book($workspace['tenant'], $shop['southService'], '2026-07-04 12:00:00', 1, true);
            $this->fail('Another location sold the walk-in hold.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That day is fully booked.', $exception->getMessage());
        }

        $notBefore = CarbonImmutable::parse('2026-07-01 00:00:00', 'UTC');
        $public = app(SlotFinder::class)->rentalSlots($shop['northService'], '2026-07-04', 60, 1, $notBefore);
        $counter = app(SlotFinder::class)->rentalSlots($shop['northService'], '2026-07-04', 60, 1, $notBefore, true);
        $this->assertNotEmpty($public);
        $this->assertTrue(collect($public)->every(fn (array $slot): bool => $slot['available'] === false));
        $this->assertTrue(collect($counter)->contains(fn (array $slot): bool => $slot['available'] === true));

        $sold = $this->book($workspace['tenant'], $shop['northService'], '2026-07-04 12:00:00', 3, true);
        $confirmed = app(Allocator::class)->confirm($sold->fresh());
        $this->assertSame('confirmed', $confirmed->status->value);
        $this->assertTrue($confirmed->counter_sale);
    }

    public function test_walk_ins_off_closes_the_day_when_the_fleet_is_committed(): void
    {
        $workspace = $this->workspace('fleet.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->beaches($workspace['tenant'], 2);
        $this->assertFalse($shop['north']->fresh()->walk_ins_enabled);

        $this->book($workspace['tenant'], $shop['northService'], '2026-07-04 10:00:00', 2, true);

        $this->expectException(AllocationUnavailableException::class);
        $this->expectExceptionMessage('That day is fully booked.');
        $this->book($workspace['tenant'], $shop['southService'], '2026-07-04 15:00:00', 1, true);
    }

    public function test_a_limited_member_cannot_book_past_another_locations_fleet_use(): void
    {
        $workspace = $this->workspace('fleet.test', 'staff');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->beaches($workspace['tenant'], 2);
        $this->book($workspace['tenant'], $shop['southService'], '2026-07-04 10:00:00', 2);

        $this->limit($workspace['membership'], $shop['north']->id);
        $this->actingAs($workspace['user']);

        $this->expectException(AllocationUnavailableException::class);
        $this->expectExceptionMessage('That day is fully booked.');
        $this->book($workspace['tenant'], $shop['northService'], '2026-07-04 14:00:00', 1);
    }

    public function test_the_load_sheet_is_built_from_bookings_and_prints_on_a_phone(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->beaches($alpha['tenant'], 10);
        $fixed = $this->rentalShop($alpha['tenant'], 4, 'America/New_York');
        $shop['north']->walk_ins_enabled = true;
        $shop['north']->save();
        LocationFleetReserve::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['north']->id,
            'fleet_item_id' => $shop['item']->id,
            'quantity' => 1,
        ]);
        $this->book($alpha['tenant'], $shop['northService'], '2026-07-04 10:00:00', 4);
        $this->book($alpha['tenant'], $shop['southService'], '2026-07-04 11:00:00', 2);
        $this->book($alpha['tenant'], $fixed['service'], '2026-07-04 10:00:00', 1);

        app(TenantManager::class)->set($beta['tenant']);
        $other = FleetItem::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta chairs',
            'quantity' => 8,
        ]);
        $betaDock = Location::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta beach',
            'timezone' => 'America/New_York',
            'delivered_from_fleet' => true,
        ]);

        $this->actingAs($alpha['user']);
        $page = $this->get($this->tenantUrl('alpha.test', '/staff/load-sheet?date=2026-07-04'))
            ->assertOk()
            ->assertSee('Daily load sheet')
            ->assertSee('Touring kayak')
            ->assertSee('North beach')
            ->assertSee('South beach')
            ->assertSee('Fleet count 10.')
            ->assertSee('Load 7.')
            ->assertSee('Still on the truck 3.')
            ->assertSee('window.print()', false)
            ->assertSee('@media print', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('Beta chairs')
            ->assertDontSee('Beta beach');

        $html = (string) $page->getContent();
        $this->assertStringContainsString('data-location="'.$shop['north']->id.'"', $html);
        $this->assertStringContainsString('data-location="'.$shop['south']->id.'"', $html);
        $this->assertStringNotContainsString($other->id, $html);
        $this->assertStringNotContainsString($betaDock->id, $html);

        app(TenantManager::class)->set($alpha['tenant']);
        $sheet = app(FleetLoadSheet::class)->forDate('2026-07-04', false);
        $stops = collect($sheet['items'][0]['locations'])->keyBy('name');
        $this->assertSame(4, $stops['North beach']['booked']);
        $this->assertSame(1, $stops['North beach']['walk_in']);
        $this->assertSame(5, $stops['North beach']['load']);
        $this->assertSame(2, $stops['South beach']['booked']);
        $this->assertSame(0, $stops['South beach']['walk_in']);
        $this->assertSame(2, $stops['South beach']['load']);

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Shared fleet')
            ->assertSee('Daily load sheet')
            ->assertSee('href="/staff/load-sheet"', false)
            ->assertSee('Single kayak');
        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('href="/staff/load-sheet"', false);
        $this->get($this->tenantUrl('alpha.test', '/staff/setup'))
            ->assertOk()
            ->assertSee('daily load sheet');
    }

    public function test_a_limited_member_sees_only_their_locations_on_the_load_sheet(): void
    {
        $workspace = $this->workspace('alpha.test', 'staff');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->beaches($workspace['tenant'], 10);
        $this->book($workspace['tenant'], $shop['northService'], '2026-07-04 10:00:00', 4);
        $this->book($workspace['tenant'], $shop['southService'], '2026-07-04 10:00:00', 3);
        $this->limit($workspace['membership'], $shop['north']->id);
        $this->actingAs($workspace['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/load-sheet?date=2026-07-04'))
            ->assertOk()
            ->assertSee('North beach')
            ->assertDontSee('South beach')
            ->assertDontSee('Still on the truck');

        app(TenantManager::class)->set($workspace['tenant']);
        $sheet = app(FleetLoadSheet::class)->forDate('2026-07-04', true);
        $this->assertSame(['North beach'], array_column($sheet['items'][0]['locations'], 'name'));
        $this->assertNull($sheet['items'][0]['on_truck']);
        $this->assertSame(4, $sheet['items'][0]['locations'][0]['booked']);
    }

    public function test_an_owner_marks_a_location_delivered_from_fleet_and_holds_walk_ins(): void
    {
        $workspace = $this->workspace('alpha.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'North beach',
            'timezone' => 'America/New_York',
        ]);
        $item = FleetItem::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Touring kayak',
            'quantity' => 8,
        ]);

        Livewire::test(Locations::class)
            ->call('edit', $location->id)
            ->set('editDelivered', true)
            ->set('editWalkIns', true)
            ->set('editReserves.'.$item->id, 2)
            ->call('save')
            ->assertHasNoErrors();

        $location->refresh();
        $this->assertTrue($location->delivered_from_fleet);
        $this->assertTrue($location->walk_ins_enabled);
        $this->assertSame(2, (int) LocationFleetReserve::query()->where('location_id', $location->id)->where('fleet_item_id', $item->id)->value('quantity'));

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/locations'))
            ->assertOk()
            ->assertSee('delivered from fleet')
            ->assertSee('walk-ins held');
    }

    /**
     * @return array{north: Location, south: Location, item: FleetItem, northService: Service, southService: Service}
     */
    private function beaches(Tenant $tenant, int $kayaks): array
    {
        $north = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'North beach',
            'timezone' => 'America/New_York',
            'delivered_from_fleet' => true,
        ]);
        $south = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'South beach',
            'timezone' => 'America/New_York',
            'delivered_from_fleet' => true,
        ]);
        $item = FleetItem::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Touring kayak',
            'quantity' => $kayaks,
        ]);

        return [
            'north' => $north,
            'south' => $south,
            'item' => $item,
            'northService' => $this->fleetService($tenant, $north, $item),
            'southService' => $this->fleetService($tenant, $south, $item),
        ];
    }

    private function fleetService(Tenant $tenant, Location $location, FleetItem $item): Service
    {
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Kayak rental '.$location->name,
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => 'fleet',
            'resource_id' => $item->id,
            'quantity' => 1,
            'per_participants' => 1,
        ]);

        return $service;
    }

    private function book(Tenant $tenant, Service $service, string $startLocal, int $participants, bool $counterSale = false): Booking
    {
        $start = CarbonImmutable::parse($startLocal, 'America/New_York')->utc();

        return app(Allocator::class)->hold(new AllocationIntent(
            $tenant->id,
            $service->id,
            $start,
            $start->addHour(),
            $participants,
            (string) Str::uuid(),
            'Guest',
            'guest@example.com',
            counterSale: $counterSale,
        ));
    }

    private function limit(Membership $membership, string ...$locationIds): void
    {
        $membership->locations_restricted = true;
        $membership->save();

        foreach ($locationIds as $locationId) {
            MembershipLocation::query()->create([
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'location_id' => $locationId,
            ]);
        }

        app(StaffLocationAccess::class)->forget();
    }
}
