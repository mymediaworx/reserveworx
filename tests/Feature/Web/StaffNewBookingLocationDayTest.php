<?php

namespace Tests\Feature\Web;

use App\Enums\DepartureStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\NewBooking;
use App\Models\BookingOperatorFee;
use App\Models\Departure;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Display;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

/**
 * Staff new-booking departures follow the service location's local day.
 */
class StaffNewBookingLocationDayTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_fall_back_keeps_the_last_local_hour_and_names_the_zone(): void
    {
        $this->freeze('2026-11-02 03:30:00');
        $workspace = $this->signedIn();
        $this->utcDesk($workspace['tenant']->id);
        $service = $this->tour($workspace['tenant']->id, 'America/New_York', 'Harbor tour');
        $late = $this->departure($service, '2026-11-01 23:30:00', 'America/New_York');
        $nextMorning = $this->departure($service, '2026-11-02 00:30:00', 'America/New_York');
        $past = $this->departure($service, '2026-11-01 09:00:00', 'America/New_York');
        $cancelled = $this->departure($service, '2026-11-01 22:00:00', 'America/New_York', DepartureStatus::Cancelled);
        $other = $this->otherWorkspaceDeparture('2026-11-01 23:30:00', 'America/New_York');
        $money = $this->moneyCounts();

        Livewire::test(NewBooking::class, ['serviceId' => $service->id])
            ->assertSet('date', '2026-11-01')
            ->assertSet('dateIsAutomatic', true)
            ->assertSee('Times are in America/New_York.')
            ->assertSee('data-booking-zone="America/New_York"', false)
            ->assertSee(Display::rangeWithZone($late->starts_at, $late->ends_at, 'America/New_York'), false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('grid-template-columns: minmax(0, 1fr)', false)
            ->assertSee($late->id, false)
            ->assertDontSee($nextMorning->id, false)
            ->assertDontSee($past->id, false)
            ->assertDontSee($cancelled->id, false)
            ->assertDontSee($other->id, false);

        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_spring_forward_leaves_out_the_next_morning(): void
    {
        $this->freeze('2026-03-08 05:00:00');
        $workspace = $this->signedIn();
        $this->utcDesk($workspace['tenant']->id);
        $service = $this->tour($workspace['tenant']->id, 'America/New_York', 'Dawn tour');
        $crossing = $this->departure($service, '2026-03-08 01:30:00', 'America/New_York');
        $nextMorning = $this->departure($service, '2026-03-09 00:30:00', 'America/New_York');
        $label = Display::rangeWithZone($crossing->starts_at, $crossing->ends_at, 'America/New_York');
        $money = $this->moneyCounts();

        Livewire::test(NewBooking::class, ['serviceId' => $service->id])
            ->assertSet('date', '2026-03-08')
            ->assertSee('Times are in America/New_York.')
            ->assertSee($label, false)
            ->assertSee('EST', false)
            ->assertSee('EDT', false)
            ->assertDontSee($nextMorning->id, false)
            ->set('date', '2026-03-09')
            ->assertSet('dateIsAutomatic', false)
            ->assertSee($nextMorning->id, false)
            ->assertDontSee($crossing->id, false);

        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_a_normal_day_stops_at_the_next_local_midnight(): void
    {
        $this->freeze('2026-06-15 16:00:00');
        $workspace = $this->signedIn();
        $service = $this->tour($workspace['tenant']->id, 'America/New_York', 'Afternoon tour');
        $late = $this->departure($service, '2026-06-15 23:30:00', 'America/New_York');
        $nextMorning = $this->departure($service, '2026-06-16 00:30:00', 'America/New_York');
        $midnight = $this->departure($service, '2026-06-16 00:00:00', 'America/New_York');

        Livewire::test(NewBooking::class, ['serviceId' => $service->id])
            ->assertSet('date', '2026-06-15')
            ->assertSee(Display::rangeWithZone($late->starts_at, $late->ends_at, 'America/New_York'), false)
            ->assertSee($late->id, false)
            ->assertDontSee($nextMorning->id, false)
            ->assertDontSee($midnight->id, false);
    }

    public function test_an_invalid_location_zone_uses_the_business_zone(): void
    {
        $this->freeze('2026-11-02 04:00:00');
        $workspace = $this->signedIn();
        $this->businessZone($workspace['tenant']->id, 'America/Chicago');
        $this->utcDesk($workspace['tenant']->id);
        $service = $this->tour($workspace['tenant']->id, 'Not/AZone', 'River tour');
        $late = $this->departure($service, '2026-11-01 23:30:00', 'America/Chicago');
        $nextMorning = $this->departure($service, '2026-11-02 00:30:00', 'America/Chicago');
        $money = $this->moneyCounts();

        Livewire::test(NewBooking::class, ['serviceId' => $service->id])
            ->assertSet('date', '2026-11-01')
            ->assertSee('Times are in America/Chicago.')
            ->assertDontSee('Times are in Not/AZone.')
            ->assertSee(Display::rangeWithZone($late->starts_at, $late->ends_at, 'America/Chicago'), false)
            ->assertSee($late->id, false)
            ->assertDontSee($nextMorning->id, false);

        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_an_automatic_date_follows_the_service_until_staff_choose_one(): void
    {
        $this->freeze('2026-11-02 05:30:00');
        $workspace = $this->signedIn();
        $this->utcDesk($workspace['tenant']->id);
        $losAngeles = $this->tour($workspace['tenant']->id, 'America/Los_Angeles', 'Pacific tour');
        $newYork = $this->tour($workspace['tenant']->id, 'America/New_York', 'Atlantic tour');

        Livewire::test(NewBooking::class, ['serviceId' => $losAngeles->id])
            ->assertSet('date', '2026-11-01')
            ->assertSet('dateIsAutomatic', true)
            ->set('serviceId', $newYork->id)
            ->assertSet('date', '2026-11-02')
            ->assertSet('dateIsAutomatic', true)
            ->set('date', '2026-11-03')
            ->assertSet('dateIsAutomatic', false)
            ->set('serviceId', $losAngeles->id)
            ->assertSet('date', '2026-11-03')
            ->assertSet('dateIsAutomatic', false);
    }

    private function freeze(string $utc): void
    {
        $now = CarbonImmutable::parse($utc, 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(): array
    {
        $workspace = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function utcDesk(string $tenantId): Location
    {
        return Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => 'UTC',
            'name' => 'UTC desk',
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);
    }

    private function tour(string $tenantId, string $timezone, string $name): Service
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => $timezone,
            'name' => $name,
            'created_at' => '2024-06-01 00:00:00',
            'updated_at' => '2024-06-01 00:00:00',
        ]);

        return Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Tour,
            'min_participants' => 1,
            'max_participants' => 8,
        ]);
    }

    private function departure(Service $service, string $local, string $zone, DepartureStatus $status = DepartureStatus::Scheduled): Departure
    {
        $start = CarbonImmutable::parse($local, $zone);

        return Departure::query()->create([
            'tenant_id' => $service->tenant_id,
            'service_id' => $service->id,
            'location_id' => $service->location_id,
            'starts_at' => $start->utc(),
            'ends_at' => $start->addHours(2)->utc(),
            'private' => false,
            'seats' => 8,
            'status' => $status,
        ]);
    }

    private function otherWorkspaceDeparture(string $local, string $zone): Departure
    {
        $other = $this->workspace('other.test', 'owner', ['name' => 'Other Dock']);
        $service = $this->tour($other['tenant']->id, $zone, 'Other tour');

        return $this->departure($service, $local, $zone);
    }

    private function businessZone(string $tenantId, string $zone): void
    {
        TenantSetting::query()->where('tenant_id', $tenantId)->update(['timezone' => $zone]);
    }

    /**
     * @return array{prices: int, payments: int, fees: int, fee_refunds: int, refunds: int, operator_fees: int}
     */
    private function moneyCounts(): array
    {
        return [
            'prices' => PriceRule::query()->count(),
            'payments' => Payment::query()->count(),
            'fees' => PlatformFee::query()->count(),
            'fee_refunds' => PlatformFeeRefund::query()->count(),
            'refunds' => Refund::query()->count(),
            'operator_fees' => BookingOperatorFee::query()->count(),
        ];
    }
}
