<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Pool;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffCalendarTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_day_week_month_and_agenda_each_keep_their_own_window(): void
    {
        $alpha = $this->workspace('alpha.test');
        $dock = $this->location($alpha['tenant'], 'North dock');
        $this->booking($alpha, $dock, 'Early hour', '2026-06-15 15:00:00', customerName: 'Early Guest');
        $this->booking($alpha, $dock, 'Mid hour', '2026-06-17 15:00:00', customerName: 'Mid Guest');
        $this->booking($alpha, $dock, 'Later hour', '2026-06-22 15:00:00', customerName: 'Later Guest');
        $this->booking($alpha, $dock, 'July hour', '2026-07-15 15:00:00', customerName: 'July Guest');

        $this->actingAs($alpha['user']);

        $week = $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view=week&date=2026-06-17'))
            ->assertOk()
            ->assertSee('Calendar')
            ->assertSee('Jun 15')
            ->assertSee('Jun 21, 2026')
            ->assertSee('Early Guest')
            ->assertSee('Mid Guest')
            ->assertDontSee('Later Guest')
            ->assertDontSee('July Guest');
        $this->assertStringContainsString('wire:model.live="view"', (string) $week->getContent());

        $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view=day&date=2026-06-17'))
            ->assertOk()
            ->assertSee('Wednesday, June 17')
            ->assertSee('Mid Guest')
            ->assertDontSee('Early Guest')
            ->assertDontSee('Later Guest');

        $month = $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view=month&date=2026-06-17'))
            ->assertOk()
            ->assertSee('June 2026')
            ->assertSee('Early Guest')
            ->assertSee('Mid Guest')
            ->assertSee('Later Guest')
            ->assertDontSee('July Guest');
        $monthHtml = (string) $month->getContent();
        $this->assertStringContainsString('calendar-weekdays', $monthHtml);
        $this->assertSame(35, preg_match_all('/class="calendar-day/', $monthHtml));

        $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view=month&date=2026-07-15'))
            ->assertOk()
            ->assertSee('July 2026')
            ->assertSee('July Guest')
            ->assertDontSee('Early Guest')
            ->assertDontSee('Later Guest');

        $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view=agenda&date=2026-06-17'))
            ->assertOk()
            ->assertSee('Jun 17')
            ->assertSee('Jun 23, 2026')
            ->assertSee('Mid Guest')
            ->assertSee('Later Guest')
            ->assertDontSee('Early Guest')
            ->assertDontSee('July Guest')
            ->assertDontSee('Jun 18');

        $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view=year&date=2026-06-17'))
            ->assertOk()
            ->assertSee('Jun 15')
            ->assertSee('Jun 21, 2026')
            ->assertSee('Early Guest')
            ->assertSee('Mid Guest')
            ->assertDontSee('Later Guest');

        $css = (string) file_get_contents(resource_path('ui/app.css'));
        $this->assertStringContainsString('.calendar.is-month', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(7, minmax(0, 1fr))', $css);
        $this->assertStringContainsString('@media (max-width: 390px)', $css);
        $this->assertStringContainsString('@media (max-width: 390px)', $monthHtml);
    }

    public function test_filters_for_service_resource_location_staff_and_status_come_from_the_url(): void
    {
        $alpha = $this->workspace('alpha.test');
        $guide = User::factory()->create(['name' => 'Guide Gwen']);
        Membership::factory()->role('staff')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $guide->id,
        ]);
        $north = $this->location($alpha['tenant'], 'North dock');
        $south = $this->location($alpha['tenant'], 'South dock');
        $pool = $this->pool($north, 'North kayaks');
        $boat = $this->asset($north, 'Harbor boat');
        $southBoat = $this->asset($south, 'South boat');
        $kayak = $this->service($alpha['tenant'], $north, 'Kayak hour', ServiceKind::Rental);
        $harbor = $this->service($alpha['tenant'], $north, 'Harbor hour', ServiceKind::Rental);
        $southHour = $this->service($alpha['tenant'], $south, 'South hour', ServiceKind::Rental);
        $sunrise = $this->service($alpha['tenant'], $north, 'Sunrise tour', ServiceKind::Tour, 4);
        $sunset = $this->service($alpha['tenant'], $north, 'Sunset tour', ServiceKind::Tour, 4);
        $this->requireResource($alpha['tenant'], $sunrise, 'pool', $pool->id);
        $this->requireResource($alpha['tenant'], $sunset, 'asset', $boat->id);

        $poolBooking = $this->booking($alpha, $north, 'Kayak hour', '2026-07-20 15:00:00', $kayak, 'Pool Guest', [
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::CheckedIn,
            'created_by' => $alpha['user']->id,
        ]);
        $this->allocate($alpha['tenant'], $poolBooking, null, $pool->id);
        $assetBooking = $this->booking($alpha, $north, 'Harbor hour', '2026-07-20 16:00:00', $harbor, 'Asset Guest', [
            'status' => BookingStatus::Held,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'created_by' => $guide->id,
            'guide_user_id' => $guide->id,
        ]);
        $this->allocate($alpha['tenant'], $assetBooking, $boat->id, null);
        $assigned = $this->booking($alpha, $north, 'Harbor hour', '2026-07-20 17:00:00', $harbor, 'Assigned Guest', [
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'created_by' => $alpha['user']->id,
            'guide_user_id' => $guide->id,
        ]);
        $this->allocate($alpha['tenant'], $assigned, $boat->id, null);
        $this->booking($alpha, $south, 'South hour', '2026-07-20 15:00:00', $southHour, 'South Guest');
        $sunriseDeparture = $this->departure($alpha['tenant'], $sunrise, '2026-07-20 09:00:00');
        $sunsetDeparture = $this->departure($alpha['tenant'], $sunset, '2026-07-20 11:00:00');

        $this->actingAs($alpha['user']);
        $day = '/staff/calendar?view=day&date=2026-07-20';

        $this->get($this->tenantUrl('alpha.test', $day))
            ->assertOk()
            ->assertSee('Pool Guest')
            ->assertSee('Asset Guest')
            ->assertSee('Assigned Guest')
            ->assertSee('South Guest')
            ->assertSee('Sunrise tour')
            ->assertSee('Sunset tour')
            ->assertSee('Guide Gwen')
            ->assertSee('Booking status')
            ->assertSee('Payment status')
            ->assertSee('Operational status')
            ->assertSee('Staff member')
            ->assertSee('All resources');

        $this->get($this->tenantUrl('alpha.test', $day.'&location='.$north->id))
            ->assertOk()
            ->assertSee('Pool Guest')
            ->assertDontSee('South Guest');

        $serviceOnly = $this->get($this->tenantUrl('alpha.test', $day.'&service='.$kayak->id))
            ->assertOk()
            ->assertSee('Pool Guest')
            ->assertDontSee('Asset Guest');
        $this->assertStringNotContainsString('data-departure="'.$sunriseDeparture->id.'"', (string) $serviceOnly->getContent());

        $poolOnly = $this->get($this->tenantUrl('alpha.test', $day.'&resource=pool:'.$pool->id))
            ->assertOk()
            ->assertSee('Pool Guest')
            ->assertDontSee('Asset Guest')
            ->assertDontSee('South Guest');
        $this->assertStringContainsString('data-departure="'.$sunriseDeparture->id.'"', (string) $poolOnly->getContent());
        $this->assertStringNotContainsString('data-departure="'.$sunsetDeparture->id.'"', (string) $poolOnly->getContent());

        $assetOnly = $this->get($this->tenantUrl('alpha.test', $day.'&resource=asset:'.$boat->id))
            ->assertOk()
            ->assertSee('Asset Guest')
            ->assertSee('Assigned Guest')
            ->assertDontSee('Pool Guest');
        $this->assertStringContainsString('data-departure="'.$sunsetDeparture->id.'"', (string) $assetOnly->getContent());
        $this->assertStringNotContainsString('data-departure="'.$sunriseDeparture->id.'"', (string) $assetOnly->getContent());

        $this->get($this->tenantUrl('alpha.test', $day.'&staff='.$guide->id))
            ->assertOk()
            ->assertSee('Asset Guest')
            ->assertSee('Assigned Guest')
            ->assertDontSee('Pool Guest')
            ->assertDontSee('South Guest');

        $narrow = $day
            .'&location='.$north->id
            .'&service='.$kayak->id
            .'&resource=pool:'.$pool->id
            .'&staff='.$alpha['user']->id
            .'&status=confirmed'
            .'&payment=paid'
            .'&operational=checked_in';
        $narrowed = $this->get($this->tenantUrl('alpha.test', $narrow))
            ->assertOk()
            ->assertSee('Pool Guest')
            ->assertDontSee('Asset Guest')
            ->assertDontSee('Assigned Guest')
            ->assertDontSee('South Guest');
        $this->assertStringNotContainsString('data-departure="'.$sunriseDeparture->id.'"', (string) $narrowed->getContent());

        $this->get($this->tenantUrl('alpha.test', $day.'&location='.$north->id.'&service='.$southHour->id))
            ->assertOk()
            ->assertSee('Pool Guest')
            ->assertDontSee('South Guest');

        $this->get($this->tenantUrl('alpha.test', $day.'&resource=asset:not-a-resource'))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', $day.'&staff=01ARZ3NDEKTSV4RRFFQ69G5FAV'))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', $day.'&service=01ARZ3NDEKTSV4RRFFQ69G5FAV'))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', $day.'&resource=asset:'.$southBoat->id))
            ->assertOk()
            ->assertDontSee('Pool Guest')
            ->assertDontSee('South Guest');
    }

    public function test_a_short_tour_is_flagged_before_departure_and_stays_scheduled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $dock = $this->location($alpha['tenant'], 'North dock');
        $tour = $this->service($alpha['tenant'], $dock, 'Harbor tour', ServiceKind::Tour, 4);
        $boat = $this->asset($dock, 'Tour boat');

        $short = $this->departure($alpha['tenant'], $tour, '2026-06-17 15:00:00');
        $this->seat($alpha, $dock, $tour, $short, $boat, 'Short Guest', 2, '2026-06-17 15:00:00');

        $met = $this->departure($alpha['tenant'], $tour, '2026-06-17 18:00:00');
        $this->seat($alpha, $dock, $tour, $met, $boat, 'Full Guest', 4, '2026-06-17 18:00:00');

        $started = $this->departure($alpha['tenant'], $tour, '2026-06-16 09:00:00');
        $this->seat($alpha, $dock, $tour, $started, $boat, 'Started Guest', 2, '2026-06-16 09:00:00');

        $cancelled = $this->departure($alpha['tenant'], $tour, '2026-06-18 15:00:00', DepartureStatus::Cancelled);
        $this->seat($alpha, $dock, $tour, $cancelled, $boat, 'Cancelled Guest', 2, '2026-06-18 15:00:00');

        $empty = $this->departure($alpha['tenant'], $tour, '2026-06-18 18:00:00');
        $dropped = $this->seat($alpha, $dock, $tour, $empty, $boat, 'Dropped Guest', 4, '2026-06-18 18:00:00', BookingStatus::Cancelled);
        $this->assertNotNull($dropped);

        $expired = $this->departure($alpha['tenant'], $tour, '2026-06-19 15:00:00');
        $expiredBooking = $this->seat($alpha, $dock, $tour, $expired, $boat, 'Expired Guest', 3, '2026-06-19 15:00:00', BookingStatus::Held, false);
        $this->hold($alpha['tenant'], $expiredBooking, $boat, '2026-06-15 12:00:00');

        $waiting = $this->departure($alpha['tenant'], $tour, '2026-06-19 18:00:00');
        $waitingBooking = $this->seat($alpha, $dock, $tour, $waiting, $boat, 'Waiting Guest', 1, '2026-06-19 18:00:00', BookingStatus::Held, false);
        $this->hold($alpha['tenant'], $waitingBooking, $boat, '2026-06-20 12:00:00');

        $this->actingAs($alpha['user']);

        $calendar = $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view=week&date=2026-06-16'))
            ->assertOk()
            ->assertSee('Below minimum')
            ->assertSee('@media (max-width: 390px)', false);
        $this->assertFlagged((string) $calendar->getContent(), [$short->id, $waiting->id], [$met->id, $started->id, $cancelled->id, $empty->id, $expired->id]);

        $month = $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view=month&date=2026-06-16'))->assertOk();
        $this->assertFlagged((string) $month->getContent(), [$short->id, $waiting->id], [$met->id, $started->id, $cancelled->id, $empty->id, $expired->id]);

        $agenda = $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view=agenda&date=2026-06-16'))->assertOk();
        $this->assertFlagged((string) $agenda->getContent(), [$short->id, $waiting->id], [$met->id, $cancelled->id, $empty->id]);

        $home = $this->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('Tours below minimum')
            ->assertSee('They stay on the schedule for you to decide.')
            ->assertSee('2 of 4')
            ->assertSee('@media (max-width: 390px)', false);
        $this->assertFlagged((string) $home->getContent(), [$short->id, $waiting->id], [$met->id, $started->id, $cancelled->id, $empty->id, $expired->id]);

        $manifest = $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-06-17'))
            ->assertOk()
            ->assertSee('Departures')
            ->assertSee('Below minimum')
            ->assertSee('@media (max-width: 390px)', false);
        $this->assertFlagged((string) $manifest->getContent(), [$short->id], [$met->id]);

        $past = $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-06-16'))->assertOk();
        $this->assertFlagged((string) $past->getContent(), [], [$started->id]);
        $this->assertStringContainsString('data-departure="'.$started->id.'"', (string) $past->getContent());

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(DepartureStatus::Scheduled, $short->fresh()?->status);
        $this->assertSame(DepartureStatus::Scheduled, $met->fresh()?->status);
        $this->assertSame(DepartureStatus::Scheduled, $started->fresh()?->status);
        $this->assertSame(DepartureStatus::Cancelled, $cancelled->fresh()?->status);
        $this->assertSame(DepartureStatus::Scheduled, $empty->fresh()?->status);
        $this->assertSame(DepartureStatus::Scheduled, $waiting->fresh()?->status);
        $this->assertSame(BookingStatus::Confirmed, Booking::query()->find($short->bookings()->value('id'))?->status);
    }

    public function test_location_limits_hide_other_tours_and_reject_other_locations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test', 'staff');
        $north = $this->location($alpha['tenant'], 'North dock');
        $south = $this->location($alpha['tenant'], 'South dock');
        $northTour = $this->service($alpha['tenant'], $north, 'North paddle', ServiceKind::Tour, 4);
        $southTour = $this->service($alpha['tenant'], $south, 'South paddle', ServiceKind::Tour, 4);
        $northBoat = $this->asset($north, 'North boat');
        $southBoat = $this->asset($south, 'South boat');
        $northDeparture = $this->departure($alpha['tenant'], $northTour, '2026-06-17 15:00:00');
        $southDeparture = $this->departure($alpha['tenant'], $southTour, '2026-06-17 15:00:00');
        $this->seat($alpha, $north, $northTour, $northDeparture, $northBoat, 'North Guest', 2, '2026-06-17 15:00:00');
        $this->seat($alpha, $south, $southTour, $southDeparture, $southBoat, 'South Guest', 2, '2026-06-17 15:00:00');
        $this->limit($alpha['membership'], $north->id);

        $this->actingAs($alpha['user']);
        $calendar = $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view=week&date=2026-06-16'))
            ->assertOk()
            ->assertSee('North dock')
            ->assertSee('North paddle')
            ->assertSee('North Guest')
            ->assertDontSee('South dock')
            ->assertDontSee('South paddle')
            ->assertDontSee('South Guest')
            ->assertDontSee('South boat');
        $this->assertFlagged((string) $calendar->getContent(), [$northDeparture->id], [$southDeparture->id]);

        $home = $this->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('North paddle')
            ->assertDontSee('South paddle');
        $this->assertFlagged((string) $home->getContent(), [$northDeparture->id], [$southDeparture->id]);

        $manifest = $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-06-17'))
            ->assertOk()
            ->assertSee('North paddle')
            ->assertDontSee('South paddle');
        $this->assertFlagged((string) $manifest->getContent(), [$northDeparture->id], [$southDeparture->id]);

        $this->get($this->tenantUrl('alpha.test', '/staff/calendar?location='.$south->id))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/calendar?service='.$southTour->id))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/calendar?resource=asset:'.$southBoat->id))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-06-17&location='.$south->id))->assertNotFound();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(DepartureStatus::Scheduled, $northDeparture->fresh()?->status);
        $this->assertSame(DepartureStatus::Scheduled, $southDeparture->fresh()?->status);
    }

    /**
     * @param  list<string>  $flagged
     * @param  list<string>  $plain
     */
    private function assertFlagged(string $html, array $flagged, array $plain): void
    {
        foreach ($flagged as $id) {
            $this->assertStringContainsString('data-below-minimum="'.$id.'"', $html);
        }

        foreach ($plain as $id) {
            $this->assertStringNotContainsString('data-below-minimum="'.$id.'"', $html);
        }
    }

    private function location(Tenant $tenant, string $name): Location
    {
        app(TenantManager::class)->set($tenant);

        return Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => 'UTC',
        ]);
    }

    private function pool(Location $location, string $name): Pool
    {
        return Pool::factory()->create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'name' => $name,
            'quantity' => 8,
        ]);
    }

    private function asset(Location $location, string $name): Asset
    {
        return Asset::factory()->create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'name' => $name,
        ]);
    }

    private function service(Tenant $tenant, Location $location, string $name, ServiceKind $kind, ?int $minimum = null): Service
    {
        app(TenantManager::class)->set($tenant);

        return Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => $kind,
            'min_participants' => $minimum,
        ]);
    }

    private function requireResource(Tenant $tenant, Service $service, string $type, string $id): void
    {
        app(TenantManager::class)->set($tenant);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => $type,
            'resource_id' => $id,
            'quantity' => 1,
        ]);
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     * @param  array<string, mixed>  $extra
     */
    private function booking(
        array $workspace,
        Location $location,
        string $serviceName,
        string $start,
        ?Service $service = null,
        ?string $customerName = null,
        array $extra = [],
    ): Booking {
        app(TenantManager::class)->set($workspace['tenant']);
        $service ??= $this->service($workspace['tenant'], $location, $serviceName, ServiceKind::Rental);
        $name = $customerName ?? $serviceName;
        $customer = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        ]);
        $starts = CarbonImmutable::parse($start, 'UTC');

        return Booking::query()->create(array_merge([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
            'participants' => 2,
            'payload_hash' => hash('sha256', $name.$start),
        ], $extra));
    }

    private function allocate(Tenant $tenant, Booking $booking, ?string $assetId, ?string $poolId): void
    {
        app(TenantManager::class)->set($tenant);
        Allocation::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $assetId,
            'pool_id' => $poolId,
            'quantity' => 1,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
        ]);
    }

    private function departure(Tenant $tenant, Service $service, string $start, DepartureStatus $status = DepartureStatus::Scheduled): Departure
    {
        app(TenantManager::class)->set($tenant);
        $starts = CarbonImmutable::parse($start, 'UTC');

        return Departure::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'location_id' => $service->location_id,
            'starts_at' => $starts,
            'ends_at' => $starts->addHours(2),
            'private' => false,
            'seats' => 8,
            'status' => $status,
        ]);
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function seat(
        array $workspace,
        Location $location,
        Service $service,
        Departure $departure,
        Asset $asset,
        string $customerName,
        int $participants,
        string $start,
        BookingStatus $status = BookingStatus::Confirmed,
        bool $withAllocation = true,
    ): Booking {
        $booking = $this->booking($workspace, $location, $service->name, $start, $service, $customerName, [
            'departure_id' => $departure->id,
            'status' => $status,
            'participants' => $participants,
            'ends_at' => CarbonImmutable::parse($start, 'UTC')->addHours(2),
        ]);

        if ($withAllocation) {
            $this->allocate($workspace['tenant'], $booking, $asset->id, null);
        }

        return $booking;
    }

    private function hold(Tenant $tenant, Booking $booking, Asset $asset, string $expiresAt): void
    {
        app(TenantManager::class)->set($tenant);
        Hold::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
            'expires_at' => CarbonImmutable::parse($expiresAt, 'UTC'),
        ]);
    }

    private function limit(Membership $membership, string $locationId): void
    {
        $membership->locations_restricted = true;
        $membership->save();
        MembershipLocation::query()->create([
            'tenant_id' => $membership->tenant_id,
            'membership_id' => $membership->id,
            'location_id' => $locationId,
        ]);
        app(StaffLocationAccess::class)->forget();
    }
}
