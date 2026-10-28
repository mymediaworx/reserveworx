<?php

namespace Tests\Feature\Web;

use App\Enums\DepartureStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Setup\ServiceDetail;
use App\Models\AuditLog;
use App\Models\Departure;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ServiceDepartureClockTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_service_departure_names_the_location_zone_including_a_daylight_saving_span(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 15:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        $tour = $this->tour($workspace['tenant'], 'America/New_York', 'Harbor night tour');
        $utc = $this->tour($workspace['tenant'], 'UTC', 'Utc tour');
        $creek = $this->tour($other['tenant'], 'America/New_York', 'Creek midnight tour');

        $this->departure($tour, '2026-03-08 06:30:00', '2026-03-08 08:30:00');
        $this->departure($tour, '2026-11-01 05:30:00', '2026-11-01 07:30:00');
        $this->departure($utc, '2026-06-15 18:00:00', '2026-06-15 20:00:00');
        $this->departure($creek, '2026-06-16 17:00:00', '2026-06-16 19:00:00');

        $money = $this->moneyCounts();
        $this->actingAs($workspace['user']);

        $page = $this->get($this->tenantUrl('dock.test', '/staff/setup/services/'.$tour->id));
        $page->assertOk()
            ->assertSee('Times are in America/New_York.')
            ->assertSee('Sun, Mar 8, 1:30 AM EST – 4:30 AM EDT', false)
            ->assertSee('Sun, Nov 1, 1:30 AM EDT – 2:30 AM EST', false)
            ->assertDontSee('Tue, Jun 16, 1:00 PM EDT')
            ->assertDontSee('Creek midnight tour')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false);

        $this->get($this->tenantUrl('dock.test', '/staff/setup/services/'.$utc->id))
            ->assertOk()
            ->assertSee('Times are in UTC.')
            ->assertSee('Mon, Jun 15, 6:00 PM – 8:00 PM UTC', false)
            ->assertDontSee('EDT');

        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_scheduling_picks_the_fall_back_hour_and_refuses_the_missing_spring_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 15:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $tour = $this->tour($workspace['tenant'], 'America/New_York', 'Harbor night tour');
        $money = $this->moneyCounts();
        $audits = $this->rows(AuditLog::class);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        $form = Livewire::test(ServiceDetail::class, ['service' => $tour->id])
            ->set('departureDate', '2026-11-01')
            ->set('departureTime', '01:30')
            ->set('departureMinutes', 120)
            ->assertSee('That time happens twice.')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->call('addDeparture')
            ->assertHasErrors('departureOccurrence')
            ->assertSee('That time happens twice. Choose the earlier or the later one.');

        $this->assertSame(0, $this->rows(Departure::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $form->set('departureOccurrence', 'earlier')
            ->call('addDeparture')
            ->assertHasNoErrors()
            ->assertSee('Sun, Nov 1, 1:30 AM EDT – 2:30 AM EST')
            ->set('departureOccurrence', 'later')
            ->call('addDeparture')
            ->assertHasNoErrors()
            ->assertSee('Sun, Nov 1, 1:30 AM – 3:30 AM EST')
            ->set('departureDate', '2026-03-08')
            ->set('departureTime', '02:30')
            ->call('addDeparture')
            ->assertHasErrors('departureTime')
            ->assertSee('That time does not exist in America/New_York.')
            ->set('departureDate', '2026-03-08')
            ->set('departureTime', '01:30')
            ->call('addDeparture')
            ->assertHasNoErrors()
            ->assertSee('Sun, Mar 8, 1:30 AM EST – 4:30 AM EDT')
            ->set('departureDate', '2026-06-15')
            ->set('departureTime', '10:00')
            ->call('addDeparture')
            ->assertHasNoErrors()
            ->assertSee('Mon, Jun 15, 10:00 AM – 12:00 PM EDT');

        $stored = Departure::query()->orderBy('starts_at')->get();
        $this->assertCount(4, $stored);
        $this->assertTrue($stored[0]->starts_at->equalTo(CarbonImmutable::parse('2026-03-08 06:30:00', 'UTC')));
        $this->assertTrue($stored[0]->ends_at->equalTo(CarbonImmutable::parse('2026-03-08 08:30:00', 'UTC')));
        $this->assertTrue($stored[1]->starts_at->equalTo(CarbonImmutable::parse('2026-06-15 14:00:00', 'UTC')));
        $this->assertTrue($stored[1]->ends_at->equalTo(CarbonImmutable::parse('2026-06-15 16:00:00', 'UTC')));
        $this->assertTrue($stored[2]->starts_at->equalTo(CarbonImmutable::parse('2026-11-01 05:30:00', 'UTC')));
        $this->assertTrue($stored[2]->ends_at->equalTo(CarbonImmutable::parse('2026-11-01 07:30:00', 'UTC')));
        $this->assertTrue($stored[3]->starts_at->equalTo(CarbonImmutable::parse('2026-11-01 06:30:00', 'UTC')));
        $this->assertTrue($stored[3]->ends_at->equalTo(CarbonImmutable::parse('2026-11-01 08:30:00', 'UTC')));
        $this->assertSame(4, AuditLog::query()->where('action', 'departure.scheduled')->count());
        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_an_invalid_location_zone_uses_the_business_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 15:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);
        $tour = $this->tour($workspace['tenant'], 'Mars/Phobos', 'Harbor night tour');
        $this->departure($tour, '2026-06-15 15:00:00', '2026-06-15 17:00:00');
        $money = $this->moneyCounts();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        $this->get($this->tenantUrl('dock.test', '/staff/setup/services/'.$tour->id))
            ->assertOk()
            ->assertSee('Times are in America/Chicago.')
            ->assertSee('Mon, Jun 15, 10:00 AM – 12:00 PM CDT', false)
            ->assertDontSee('Mars/Phobos');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        Livewire::test(ServiceDetail::class, ['service' => $tour->id])
            ->set('departureDate', '2026-06-16')
            ->set('departureTime', '10:00')
            ->set('departureMinutes', 120)
            ->call('addDeparture')
            ->assertHasNoErrors()
            ->assertSee('Tue, Jun 16, 10:00 AM – 12:00 PM CDT');

        $added = Departure::query()->orderByDesc('starts_at')->first();
        $this->assertInstanceOf(Departure::class, $added);
        $this->assertTrue($added->starts_at->equalTo(CarbonImmutable::parse('2026-06-16 15:00:00', 'UTC')));
        $this->assertTrue($added->ends_at->equalTo(CarbonImmutable::parse('2026-06-16 17:00:00', 'UTC')));
        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_staff_can_read_the_clock_and_cannot_schedule_a_departure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 15:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $tour = $this->tour($workspace['tenant'], 'America/New_York', 'Harbor night tour');
        $this->departure($tour, '2026-03-08 06:30:00', '2026-03-08 08:30:00');
        $staff = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $staff->id,
        ]);
        $money = $this->moneyCounts();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($staff);

        $this->get($this->tenantUrl('dock.test', '/staff/setup/services/'.$tour->id))
            ->assertOk()
            ->assertSee('Times are in America/New_York.')
            ->assertSee('Sun, Mar 8, 1:30 AM EST – 4:30 AM EDT', false)
            ->assertDontSee('Schedule departure');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($staff);

        Livewire::test(ServiceDetail::class, ['service' => $tour->id])
            ->set('departureDate', '2026-06-15')
            ->set('departureTime', '10:00')
            ->call('addDeparture')
            ->assertForbidden();

        $this->assertSame(1, $this->rows(Departure::class));
        $this->assertSame($money, $this->moneyCounts());
    }

    private function tour(Tenant $tenant, string $timezone, string $name): Service
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => $timezone,
            'name' => $name.' dock',
        ]);
        app(TenantManager::class)->set($tenant);

        return Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Tour,
            'max_participants' => 8,
        ]);
    }

    private function departure(Service $service, string $startUtc, string $endUtc): Departure
    {
        app(TenantManager::class)->set($service->tenant);

        return Departure::query()->create([
            'tenant_id' => $service->tenant_id,
            'service_id' => $service->id,
            'location_id' => $service->location_id,
            'starts_at' => CarbonImmutable::parse($startUtc, 'UTC'),
            'ends_at' => CarbonImmutable::parse($endUtc, 'UTC'),
            'private' => false,
            'seats' => 8,
            'status' => DepartureStatus::Scheduled,
        ]);
    }

    /**
     * @return array{prices: int, payments: int, fees: int, fee_refunds: int, refunds: int, snapshots: int, taxes: int}
     */
    private function moneyCounts(): array
    {
        return [
            'prices' => $this->rows(PriceRule::class),
            'payments' => $this->rows(Payment::class),
            'fees' => $this->rows(PlatformFee::class),
            'fee_refunds' => $this->rows(PlatformFeeRefund::class),
            'refunds' => $this->rows(Refund::class),
            'snapshots' => $this->rows(PricingSnapshot::class),
            'taxes' => $this->rows(LocationTaxRule::class),
        ];
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
