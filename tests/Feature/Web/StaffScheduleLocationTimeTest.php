<?php

namespace Tests\Feature\Web;

use App\Enums\DepartureStatus;
use App\Livewire\Staff\Schedule;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Refund;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffScheduleLocationTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_fall_back_day_names_both_one_o_clock_hours_and_keeps_money_unchanged(): void
    {
        $alpha = $this->workspace('alpha.test');
        $dock = $this->location($alpha['tenant'], 'Hudson dock', 'America/New_York');
        $boat = $this->asset($dock, $this->category($alpha['tenant'], 'Boats'), 'Hudson skiff');
        $this->service($alpha['tenant'], $dock, $boat, 'Hudson rental');
        $payments = Payment::query()->count();
        $fees = PlatformFee::query()->count();
        $refunds = Refund::query()->count();
        $bookings = Booking::query()->count();

        $this->actingAs($alpha['user']);
        $page = $this->get($this->tenantUrl('alpha.test', '/staff/schedule?view=day&date=2026-11-01'));

        $page->assertOk()
            ->assertSee('EDT / EST · America/New_York')
            ->assertSee('data-minutes="1500"', false)
            ->assertSee('data-start="01:00-04:00"', false)
            ->assertSee('data-start="01:00-05:00"', false)
            ->assertSee('1am EDT')
            ->assertSee('1am EST');
        $this->assertSame($payments, Payment::query()->count());
        $this->assertSame($fees, PlatformFee::query()->count());
        $this->assertSame($refunds, Refund::query()->count());
        $this->assertSame($bookings, Booking::query()->count());

        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$boat->id, '2026-11-01', '01:00-04:00')
            ->assertSee('Sun, Nov 1, 1:00 AM EDT')
            ->assertDontSee('Sun, Nov 1, 1:00 AM EST');
        Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$boat->id, '2026-11-01', '01:00-05:00')
            ->assertSee('Sun, Nov 1, 1:00 AM EST')
            ->assertDontSee('Sun, Nov 1, 1:00 AM EDT');
        Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$boat->id, '2026-11-01', '01:00-07:00')
            ->assertStatus(422);
        $this->assertSame($payments, Payment::query()->count());
        $this->assertSame($fees, PlatformFee::query()->count());
        $this->assertSame($refunds, Refund::query()->count());
        $this->assertSame($bookings, Booking::query()->count());

        $css = (string) file_get_contents(resource_path('ui/app.css'));
        $this->assertMatchesRegularExpression('/\.move-panel p,\s*\.schedule-booking p,\s*\.schedule-location span \{\s*overflow-wrap:\s*anywhere;/s', $css);
    }

    public function test_a_spring_forward_day_leaves_out_the_missing_hour(): void
    {
        $alpha = $this->workspace('alpha.test');
        $dock = $this->location($alpha['tenant'], 'Hudson dock', 'America/New_York');
        $boat = $this->asset($dock, $this->category($alpha['tenant'], 'Boats'), 'Hudson skiff');
        $this->service($alpha['tenant'], $dock, $boat, 'Hudson rental');

        $this->actingAs($alpha['user']);
        $page = $this->get($this->tenantUrl('alpha.test', '/staff/schedule?view=day&date=2026-03-08'));

        $page->assertOk()
            ->assertSee('data-minutes="1380"', false)
            ->assertSee('2026-03-08 1am EST', false)
            ->assertSee('2026-03-08 3am EDT', false)
            ->assertDontSee('2026-03-08 2am EST', false)
            ->assertDontSee('2026-03-08 2am EDT', false)
            ->assertDontSee('data-start="02:00"', false);
    }

    public function test_schedule_departures_use_the_location_local_day(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $dock = $this->location($alpha['tenant'], 'Hudson dock', 'America/New_York');
        $boat = $this->asset($dock, $this->category($alpha['tenant'], 'Boats'), 'Hudson skiff');
        $tour = $this->service($alpha['tenant'], $dock, $boat, 'Sunset tour', 'tour');
        $kept = $this->departure($alpha['tenant'], $tour, '2026-11-01 23:30:00', 'America/New_York');
        $nextMorning = $this->departure($alpha['tenant'], $tour, '2026-11-02 00:30:00', 'America/New_York');
        $cancelled = $this->departure($alpha['tenant'], $tour, '2026-11-01 18:00:00', 'America/New_York', DepartureStatus::Cancelled);
        $betaDock = $this->location($beta['tenant'], 'Beta dock', 'America/New_York');
        $betaBoat = $this->asset($betaDock, $this->category($beta['tenant'], 'Boats'), 'Beta skiff');
        $betaTour = $this->service($beta['tenant'], $betaDock, $betaBoat, 'Beta sunset', 'tour');
        $betaDeparture = $this->departure($beta['tenant'], $betaTour, '2026-11-01 16:15:00', 'America/New_York');

        $this->actingAs($alpha['user']);
        app(TenantManager::class)->set($alpha['tenant']);
        $panel = Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$boat->id, '2026-11-01', '09:00');

        $panel->assertSee('Sun, Nov 1, 11:30 PM – Mon, Nov 2, 1:30 AM EST')
            ->assertSee('data-departure="'.$kept->id.'"', false)
            ->assertDontSee('data-departure="'.$nextMorning->id.'"', false)
            ->assertDontSee('data-departure="'.$cancelled->id.'"', false)
            ->assertDontSee('data-departure="'.$betaDeparture->id.'"', false)
            ->assertDontSee('Mon, Nov 2, 12:30 AM – 2:30 AM EST')
            ->assertDontSee('Sun, Nov 1, 4:15 PM – 6:15 PM EST');

        $springTour = $this->service($alpha['tenant'], $dock, $boat, 'Morning tour', 'tour');
        $springKept = $this->departure($alpha['tenant'], $springTour, '2026-03-08 23:30:00', 'America/New_York');
        $springNext = $this->departure($alpha['tenant'], $springTour, '2026-03-09 00:30:00', 'America/New_York');
        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$boat->id, '2026-03-08', '09:00')
            ->call('chooseService', $springTour->id)
            ->assertSee('Sun, Mar 8, 11:30 PM – Mon, Mar 9, 1:30 AM EDT')
            ->assertSee('data-departure="'.$springKept->id.'"', false)
            ->assertDontSee('data-departure="'.$springNext->id.'"', false)
            ->assertDontSee('Mon, Mar 9, 12:30 AM – 2:30 AM EDT');
    }

    public function test_an_invalid_location_zone_uses_the_business_zone(): void
    {
        $alpha = $this->workspace('alpha.test');
        $dock = $this->location($alpha['tenant'], 'Hudson dock', 'Mars/Phobos');
        $boat = $this->asset($dock, $this->category($alpha['tenant'], 'Boats'), 'Hudson skiff');
        $this->service($alpha['tenant'], $dock, $boat, 'Hudson rental');

        $this->actingAs($alpha['user']);
        $this->get($this->tenantUrl('alpha.test', '/staff/schedule?view=day&date=2026-11-01'))
            ->assertOk()
            ->assertSee('EDT / EST · America/New_York')
            ->assertDontSee('Mars/Phobos');
    }

    public function test_blocks_on_the_repeated_hour_name_which_one_they_are(): void
    {
        $alpha = $this->workspace('alpha.test');
        $dock = $this->location($alpha['tenant'], 'Hudson dock', 'America/New_York');
        $boats = $this->category($alpha['tenant'], 'Boats');
        $earlyBoat = $this->asset($dock, $boats, 'Early skiff');
        $lateBoat = $this->asset($dock, $boats, 'Late skiff');
        $earlyService = $this->service($alpha['tenant'], $dock, $earlyBoat, 'Early rental');
        $lateService = $this->service($alpha['tenant'], $dock, $lateBoat, 'Late rental');
        $early = $this->hold($alpha, $earlyService, '2026-11-01 05:30:00', 'Early Guest', '2026-11-01 06:30:00');
        $late = $this->hold($alpha, $lateService, '2026-11-01 06:30:00', 'Late Guest', '2026-11-01 07:30:00');

        $this->actingAs($alpha['user']);
        $page = $this->get($this->tenantUrl('alpha.test', '/staff/schedule?view=day&date=2026-11-01'));

        $page->assertOk()
            ->assertSee('Early Guest')
            ->assertSee('Late Guest')
            ->assertSee('1:30 AM EDT–1:30 AM EST')
            ->assertSee('1:30–2:30 AM EST')
            ->assertSee('data-local-start="2026-11-01T01:30"', false);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, Refund::query()->count());

        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Schedule::class)
            ->call('openMove', $early->id, 'asset:'.$earlyBoat->id)
            ->assertSee('Sun, Nov 1, 1:30 AM EDT – 1:30 AM EST');
        Livewire::test(Schedule::class)
            ->call('openMove', $late->id, 'asset:'.$lateBoat->id)
            ->assertSee('Sun, Nov 1, 1:30 AM – 2:30 AM EST');
    }

    private function location(Tenant $tenant, string $name, string $timezone): Location
    {
        return Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => $timezone,
        ]);
    }

    private function category(Tenant $tenant, string $name): ResourceCategory
    {
        return ResourceCategory::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
        ]);
    }

    private function asset(Location $location, ResourceCategory $category, string $name): Asset
    {
        return Asset::factory()->create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => $name,
        ]);
    }

    private function service(Tenant $tenant, Location $location, Asset $resource, string $name, string $kind = 'rental'): Service
    {
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => $kind,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $resource->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return $service;
    }

    private function departure(Tenant $tenant, Service $service, string $localStart, string $zone, DepartureStatus $status = DepartureStatus::Scheduled): Departure
    {
        app(TenantManager::class)->set($tenant);
        $starts = CarbonImmutable::parse($localStart, $zone);

        $departure = Departure::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'location_id' => $service->location_id,
            'starts_at' => $starts->utc(),
            'ends_at' => $starts->addHours(2)->utc(),
            'private' => false,
            'seats' => 8,
            'status' => $status,
        ]);
        app(TenantManager::class)->forget();

        return $departure;
    }

    /**
     * @param  array{tenant: Tenant, user: mixed}  $workspace
     */
    private function hold(array $workspace, Service $service, string $startUtc, string $name, string $endUtc): Booking
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $booking = app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $service->id,
            CarbonImmutable::parse($startUtc, 'UTC'),
            CarbonImmutable::parse($endUtc, 'UTC'),
            1,
            null,
            $name,
            strtolower(str_replace(' ', '.', $name)).'@example.com',
        ));
        app(TenantManager::class)->forget();

        return $booking;
    }
}
