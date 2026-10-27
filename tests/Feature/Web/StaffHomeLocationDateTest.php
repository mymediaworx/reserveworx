<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffHomeLocationDateTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_uses_each_locations_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $east = $this->location($alpha['tenant'], 'Harbor', 'America/New_York', '2026-01-01 00:00:00');
        $west = $this->location($alpha['tenant'], 'Pacific dock', 'America/Los_Angeles', '2026-01-02 00:00:00');
        $utc = $this->location($alpha['tenant'], 'Utc dock', 'UTC', '2026-01-03 00:00:00');
        $fallback = $this->location($alpha['tenant'], 'Fallback dock', 'Not/AZone', '2026-01-04 00:00:00');
        $late = $this->visit($alpha['tenant'], $west, 'Late paddle', '2026-06-17 05:30:00', '2026-06-17 06:30:00');
        $yesterday = $this->visit($alpha['tenant'], $west, 'Yesterday paddle', '2026-06-16 05:30:00', '2026-06-16 06:30:00');
        $harbor = $this->visit($alpha['tenant'], $east, 'Harbor paddle', '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $stillOut = $this->visit($alpha['tenant'], $west, 'Still out kayak', '2026-06-15 18:00:00', '2026-06-16 20:00:00');
        $cancelled = $this->visit($alpha['tenant'], $west, 'Cancelled paddle', '2026-06-17 05:30:00', '2026-06-17 06:30:00', BookingStatus::Cancelled);
        $utcMorning = $this->visit($alpha['tenant'], $utc, 'Utc morning', '2026-06-17 02:30:00', '2026-06-17 03:30:00');
        $fallbackVisit = $this->visit($alpha['tenant'], $fallback, 'Fallback paddle', '2026-06-17 03:30:00', '2026-06-17 03:50:00');
        $tomorrow = $this->visit($alpha['tenant'], $west, 'Tomorrow paddle', '2026-06-17 08:00:00', '2026-06-17 09:00:00');
        $other = $this->visit($beta['tenant'], $this->location($beta['tenant'], 'Other dock', 'America/Los_Angeles', '2026-01-01 00:00:00'), 'Other paddle', '2026-06-17 05:30:00', '2026-06-17 06:30:00');
        $lateDeparture = $this->departure($alpha['tenant'], $west, 'Late tour', '2026-06-17 05:30:00', '2026-06-17 06:30:00');
        $earlyDeparture = $this->departure($alpha['tenant'], $west, 'Early tour', '2026-06-16 05:30:00', '2026-06-16 06:30:00');
        app(TenantManager::class)->forget();

        $page = $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('data-today="4"', false)
            ->assertSee('data-upcoming="2"', false)
            ->assertSee('data-booking="'.$late->id.'"', false)
            ->assertSee('Late paddle')
            ->assertSee('10:30 PM')
            ->assertSee('data-booking="'.$harbor->id.'"', false)
            ->assertSee('Harbor paddle')
            ->assertSee('11:00 AM')
            ->assertSee('data-booking="'.$stillOut->id.'"', false)
            ->assertSee('Still out kayak')
            ->assertSee('Mon, Jun 15')
            ->assertSee('data-booking="'.$fallbackVisit->id.'"', false)
            ->assertSee('Fallback paddle')
            ->assertSee('11:30 PM')
            ->assertSee('data-departure="'.$lateDeparture->id.'"', false)
            ->assertSee('Late tour')
            ->assertDontSee('data-booking="'.$yesterday->id.'"', false)
            ->assertDontSee('Yesterday paddle')
            ->assertDontSee('data-booking="'.$cancelled->id.'"', false)
            ->assertDontSee('Cancelled paddle')
            ->assertDontSee('data-booking="'.$utcMorning->id.'"', false)
            ->assertDontSee('Utc morning')
            ->assertDontSee('data-booking="'.$tomorrow->id.'"', false)
            ->assertDontSee('Tomorrow paddle')
            ->assertDontSee('data-booking="'.$other->id.'"', false)
            ->assertDontSee('Other paddle')
            ->assertDontSee('data-departure="'.$earlyDeparture->id.'"', false)
            ->assertDontSee('Early tour');

        $html = (string) $page->getContent();
        $this->assertStringContainsString('@media (max-width: 390px)', $html);
        $this->assertStringContainsString('.today-departures', $html);
        $this->assertSame('2026-06-17 05:30:00', $late->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_spring_forward_and_fall_back_follow_the_location_day(): void
    {
        $alpha = $this->workspace('alpha.test');
        $east = $this->location($alpha['tenant'], 'Harbor', 'America/New_York', '2026-01-01 00:00:00');
        $before = $this->visit($alpha['tenant'], $east, 'Before spring', '2026-03-08 04:30:00', '2026-03-08 04:50:00');
        $morning = $this->visit($alpha['tenant'], $east, 'After the jump', '2026-03-08 11:30:00', '2026-03-08 12:30:00');
        $shortEvening = $this->visit($alpha['tenant'], $east, 'Short evening', '2026-03-09 03:30:00', '2026-03-09 03:50:00');
        $longEvening = $this->visit($alpha['tenant'], $east, 'Long evening', '2026-11-02 04:30:00', '2026-11-02 04:50:00');
        $nextMorning = $this->visit($alpha['tenant'], $east, 'Next morning', '2026-11-02 05:30:00', '2026-11-02 06:00:00');
        app(TenantManager::class)->forget();

        Carbon::setTestNow(Carbon::parse('2026-03-08 16:00:00', 'UTC'));
        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('data-today="2"', false)
            ->assertSee('data-booking="'.$morning->id.'"', false)
            ->assertSee('After the jump')
            ->assertSee('data-booking="'.$shortEvening->id.'"', false)
            ->assertSee('Short evening')
            ->assertDontSee('data-booking="'.$before->id.'"', false)
            ->assertDontSee('Before spring')
            ->assertDontSee('Long evening')
            ->assertDontSee('Next morning');

        Carbon::setTestNow(Carbon::parse('2026-11-01 18:00:00', 'UTC'));
        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('data-today="1"', false)
            ->assertSee('data-booking="'.$longEvening->id.'"', false)
            ->assertSee('Long evening')
            ->assertDontSee('data-booking="'.$nextMorning->id.'"', false)
            ->assertDontSee('Next morning')
            ->assertDontSee('After the jump')
            ->assertDontSee('Short evening');
    }

    public function test_a_member_limited_to_one_location_does_not_see_another_locations_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test', 'staff');
        $east = $this->location($alpha['tenant'], 'Harbor', 'America/New_York', '2026-01-01 00:00:00');
        $west = $this->location($alpha['tenant'], 'Pacific dock', 'America/Los_Angeles', '2026-01-02 00:00:00');
        $this->visit($alpha['tenant'], $east, 'Harbor paddle', '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $this->visit($alpha['tenant'], $west, 'Late paddle', '2026-06-17 05:30:00', '2026-06-17 06:30:00');
        $alpha['membership']->locations_restricted = true;
        $alpha['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'membership_id' => $alpha['membership']->id,
            'location_id' => $east->id,
        ]);
        app(StaffLocationAccess::class)->forget();
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('data-today="1"', false)
            ->assertSee('Harbor paddle')
            ->assertDontSee('Late paddle');
    }

    private function location(Tenant $tenant, string $name, string $timezone, string $createdAt): Location
    {
        app(TenantManager::class)->set($tenant);
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => $timezone,
        ]);
        $location->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $location;
    }

    private function visit(
        Tenant $tenant,
        Location $location,
        string $name,
        string $startsAt,
        string $endsAt,
        BookingStatus $status = BookingStatus::Confirmed,
    ): Booking {
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'participants' => 1,
            'payload_hash' => hash('sha256', $name),
        ]);
    }

    private function departure(Tenant $tenant, Location $location, string $name, string $startsAt, string $endsAt): Departure
    {
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Tour,
            'min_participants' => 1,
            'max_participants' => 8,
            'waitlist_enabled' => false,
        ]);

        return Departure::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'private' => false,
            'seats' => 8,
            'status' => DepartureStatus::Scheduled,
        ]);
    }
}
