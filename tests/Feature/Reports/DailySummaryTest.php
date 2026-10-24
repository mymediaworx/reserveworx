<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ResourceStatus;
use App\Enums\ScheduleOwnerType;
use App\Enums\ServiceKind;
use App\Enums\TenantStatus;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AvailabilitySchedule;
use App\Models\Blackout;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Pool;
use App\Models\PricingSnapshot;
use App\Models\ReportSummary;
use App\Models\Service;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class DailySummaryTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_stored_summary_keeps_heavy_metrics_off_the_live_today_report(): void
    {
        $workspace = $this->workspace('dock.test');
        $location = $this->location($workspace['tenant']->id, 'UTC');
        $service = $this->service($workspace['tenant']->id, $location->id);
        $weekday = CarbonImmutable::parse('2026-06-16', 'UTC')->dayOfWeek;
        $this->hours($workspace['tenant']->id, $location->id, $weekday, '09:00:00', '17:00:00');
        $kayak = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Open kayak',
            'status' => ResourceStatus::Active,
        ]);
        $blocked = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Out of service',
            'status' => ResourceStatus::Blocked,
        ]);
        $confirmed = $this->booking($workspace['tenant']->id, $location, $service, BookingStatus::Confirmed, '2026-06-16 15:00:00', '2026-06-16 16:00:00', '2026-06-16 12:00:00');
        $noShow = $this->booking($workspace['tenant']->id, $location, $service, BookingStatus::NoShow, '2026-06-16 17:00:00', '2026-06-16 18:00:00', '2026-06-16 13:00:00');
        $held = $this->booking($workspace['tenant']->id, $location, $service, BookingStatus::Held, '2026-06-16 18:00:00', '2026-06-16 19:00:00', '2026-06-16 14:00:00');
        $this->snapshot($workspace['tenant']->id, $confirmed, 10_000, 10_300);
        $this->snapshot($workspace['tenant']->id, $noShow, 4_000, 5_000);
        $this->snapshot($workspace['tenant']->id, $held, 9_999, 9_999);
        Allocation::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $confirmed->id,
            'asset_id' => $kayak->id,
            'quantity' => 1,
            'starts_at' => '2026-06-16 10:00:00',
            'ends_at' => '2026-06-16 11:00:00',
        ]);
        Allocation::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $confirmed->id,
            'asset_id' => $blocked->id,
            'quantity' => 1,
            'starts_at' => '2026-06-16 10:00:00',
            'ends_at' => '2026-06-16 12:00:00',
        ]);

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/summaries?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $today = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/today?date=2026-06-16'))->assertOk()->json('data');
        $this->assertIsArray($today);
        $this->assertArrayNotHasKey('utilization_bps', $today);

        $this->artisan('reports:build-summaries', ['--date' => '2026-06-16'])->assertSuccessful();
        $this->artisan('reports:build-summaries', ['--date' => '2026-06-16'])->assertSuccessful();
        $this->assertSame(1, ReportSummary::withoutGlobalScope(TenantScope::class)->where('tenant_id', $workspace['tenant']->id)->count());

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/summaries?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertJsonPath('data.0.location_id', $location->id)
            ->assertJsonPath('data.0.date', '2026-06-16')
            ->assertJsonPath('data.0.timezone', 'UTC')
            ->assertJsonPath('data.0.available_resource_minutes', 480)
            ->assertJsonPath('data.0.allocated_resource_minutes', 180)
            ->assertJsonPath('data.0.utilization_bps', 3750)
            ->assertJsonPath('data.0.bookings_created', 3)
            ->assertJsonPath('data.0.bookings_converted', 2)
            ->assertJsonPath('data.0.conversion_bps', 6667)
            ->assertJsonPath('data.0.average_order_cents', 7650)
            ->assertJsonPath('data.0.gross_cents', 14000)
            ->assertJsonPath('data.0.revenue_per_resource_hour_cents', 1750);

        Allocation::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $confirmed->id,
            'asset_id' => $kayak->id,
            'quantity' => 1,
            'starts_at' => '2026-06-16 12:00:00',
            'ends_at' => '2026-06-16 13:00:00',
        ]);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/summaries?from=2026-06-16&to=2026-06-16'))
            ->assertJsonPath('data.0.allocated_resource_minutes', 180);

        $this->artisan('reports:build-summaries', ['--date' => '2026-06-16'])->assertSuccessful();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/summaries?from=2026-06-16&to=2026-06-16'))
            ->assertJsonPath('data.0.allocated_resource_minutes', 240)
            ->assertJsonPath('data.0.utilization_bps', 5000);
        $this->assertSame(1, ReportSummary::withoutGlobalScope(TenantScope::class)->where('tenant_id', $workspace['tenant']->id)->count());
    }

    public function test_a_pool_scales_capacity_and_a_blocked_pool_does_not(): void
    {
        $workspace = $this->workspace('dock.test');
        $location = $this->location($workspace['tenant']->id, 'UTC');
        $service = $this->service($workspace['tenant']->id, $location->id);
        $weekday = CarbonImmutable::parse('2026-07-01', 'UTC')->dayOfWeek;
        $this->hours($workspace['tenant']->id, $location->id, $weekday, '10:00:00', '11:00:00');
        $pool = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Singles',
            'quantity' => 4,
            'status' => ResourceStatus::Active,
        ]);
        Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Retired bin',
            'quantity' => 10,
            'status' => ResourceStatus::Maintenance,
        ]);
        $booking = $this->booking($workspace['tenant']->id, $location, $service, BookingStatus::Confirmed, '2026-07-01 10:00:00', '2026-07-01 10:30:00', '2026-07-01 09:00:00');
        Allocation::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'pool_id' => $pool->id,
            'quantity' => 2,
            'starts_at' => '2026-07-01 10:00:00',
            'ends_at' => '2026-07-01 10:30:00',
        ]);

        $this->artisan('reports:build-summaries', ['--date' => '2026-07-01'])->assertSuccessful();

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/summaries?from=2026-07-01&to=2026-07-01'))
            ->assertOk()
            ->assertJsonPath('data.0.available_resource_minutes', 240)
            ->assertJsonPath('data.0.allocated_resource_minutes', 60)
            ->assertJsonPath('data.0.utilization_bps', 2500);
    }

    public function test_closed_days_and_blackouts_leave_utilization_empty(): void
    {
        $workspace = $this->workspace('dock.test');
        $open = $this->location($workspace['tenant']->id, 'UTC', 'Open dock');
        $closed = $this->location($workspace['tenant']->id, 'UTC', 'Sunday dock');
        $dark = $this->location($workspace['tenant']->id, 'UTC', 'Dark dock');
        Asset::factory()->create(['tenant_id' => $workspace['tenant']->id, 'location_id' => $open->id, 'name' => 'A']);
        Asset::factory()->create(['tenant_id' => $workspace['tenant']->id, 'location_id' => $closed->id, 'name' => 'B']);
        Asset::factory()->create(['tenant_id' => $workspace['tenant']->id, 'location_id' => $dark->id, 'name' => 'C']);
        $tuesday = CarbonImmutable::parse('2026-06-16', 'UTC')->dayOfWeek;
        $this->hours($workspace['tenant']->id, $open->id, ($tuesday + 6) % 7, '22:00:00', '02:00:00');
        $this->hours($workspace['tenant']->id, $closed->id, 0, '09:00:00', '17:00:00');
        Blackout::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $dark->id,
            'starts_on' => '2026-06-16',
            'ends_on' => '2026-06-16',
            'reason' => 'Storm',
        ]);
        $service = $this->service($workspace['tenant']->id, $dark->id);
        $booking = $this->booking($workspace['tenant']->id, $dark, $service, BookingStatus::Confirmed, '2026-06-16 15:00:00', '2026-06-16 16:00:00', '2026-06-16 12:00:00');
        $this->snapshot($workspace['tenant']->id, $booking, 8_000, 8_000);

        $this->artisan('reports:build-summaries', ['--date' => '2026-06-16'])->assertSuccessful();

        $rows = ReportSummary::withoutGlobalScope(TenantScope::class)->where('tenant_id', $workspace['tenant']->id)->get()->keyBy('location_id');
        $this->assertSame(120, $rows[$open->id]->available_resource_minutes);
        $this->assertSame(0, $rows[$open->id]->utilization_bps);
        $this->assertNull($rows[$closed->id]->utilization_bps);
        $this->assertSame(0, $rows[$closed->id]->available_resource_minutes);
        $this->assertNull($rows[$dark->id]->utilization_bps);
        $this->assertNull($rows[$dark->id]->revenue_per_resource_hour_cents);
        $this->assertSame(8000, $rows[$dark->id]->gross_cents);
        $this->assertSame(10000, $rows[$dark->id]->conversion_bps);
    }

    public function test_overlapping_hours_are_counted_once(): void
    {
        $workspace = $this->workspace('dock.test');
        $location = $this->location($workspace['tenant']->id, 'UTC');
        $weekday = CarbonImmutable::parse('2026-06-16', 'UTC')->dayOfWeek;
        $this->hours($workspace['tenant']->id, $location->id, $weekday, '09:00:00', '12:00:00');
        $this->hours($workspace['tenant']->id, $location->id, $weekday, '11:00:00', '13:00:00');
        Asset::factory()->create(['tenant_id' => $workspace['tenant']->id, 'location_id' => $location->id, 'name' => 'One']);

        $this->artisan('reports:build-summaries', ['--date' => '2026-06-16'])->assertSuccessful();

        $this->assertSame(240, ReportSummary::withoutGlobalScope(TenantScope::class)->where('tenant_id', $workspace['tenant']->id)->firstOrFail()->available_resource_minutes);
    }

    public function test_daylight_saving_changes_the_length_of_an_open_day(): void
    {
        $workspace = $this->workspace('dock.test');
        $location = $this->location($workspace['tenant']->id, 'America/New_York');
        Asset::factory()->create(['tenant_id' => $workspace['tenant']->id, 'location_id' => $location->id, 'name' => 'One']);
        $spring = CarbonImmutable::parse('2026-03-08', 'America/New_York')->dayOfWeek;
        $this->hours($workspace['tenant']->id, $location->id, $spring, '00:00:00', '04:00:00');

        $this->artisan('reports:build-summaries', ['--date' => '2026-03-08'])->assertSuccessful();
        $this->assertSame(180, ReportSummary::withoutGlobalScope(TenantScope::class)->whereDate('summary_date', '2026-03-08')->firstOrFail()->available_resource_minutes);

        AvailabilitySchedule::withoutGlobalScope(TenantScope::class)->delete();
        $this->artisan('reports:build-summaries', ['--date' => '2026-03-08'])->assertSuccessful();
        $this->assertSame(1380, ReportSummary::withoutGlobalScope(TenantScope::class)->whereDate('summary_date', '2026-03-08')->firstOrFail()->available_resource_minutes);

        $this->artisan('reports:build-summaries', ['--date' => '2026-11-01'])->assertSuccessful();
        $this->assertSame(1500, ReportSummary::withoutGlobalScope(TenantScope::class)->whereDate('summary_date', '2026-11-01')->firstOrFail()->available_resource_minutes);
    }

    public function test_a_location_date_follows_that_locations_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 03:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test');
        $location = $this->location($workspace['tenant']->id, 'America/Chicago');
        $service = $this->service($workspace['tenant']->id, $location->id);
        $this->booking($workspace['tenant']->id, $location, $service, BookingStatus::Held, '2026-06-16 04:30:00', '2026-06-16 05:30:00', '2026-06-16 04:30:00');

        $this->artisan('reports:build-summaries')->assertSuccessful();

        $rows = ReportSummary::withoutGlobalScope(TenantScope::class)->where('tenant_id', $workspace['tenant']->id)->orderBy('summary_date')->get();
        $dates = $rows->map(fn (ReportSummary $row): string => $row->summary_date->format('Y-m-d'))->all();
        $this->assertSame(['2026-06-14', '2026-06-15'], $dates);
        $this->assertSame(1, $rows[1]->bookings_created);
        $this->assertSame(0, $rows[0]->bookings_created);
        $this->assertNull($rows[0]->conversion_bps);
        $this->assertSame(0, $rows[1]->conversion_bps);
    }

    public function test_another_workspace_and_a_suspended_workspace_stay_out_of_the_read(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $suspended = $this->workspace('closed.test', 'owner', ['status' => TenantStatus::Suspended]);
        $onboarding = $this->workspace('new.test', 'owner', ['status' => TenantStatus::Onboarding]);
        $alphaLocation = $this->location($alpha['tenant']->id, 'UTC', 'Alpha');
        $betaLocation = $this->location($beta['tenant']->id, 'UTC', 'Beta');
        $this->location($suspended['tenant']->id, 'UTC', 'Closed');
        $onboardingLocation = $this->location($onboarding['tenant']->id, 'UTC', 'New');

        $this->artisan('reports:build-summaries', ['--date' => '2026-06-16'])->assertSuccessful();

        $this->actingAsWorkspace($alpha);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/summaries?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.location_id', $alphaLocation->id);

        $this->assertSame($beta['tenant']->id, ReportSummary::withoutGlobalScope(TenantScope::class)->where('location_id', $betaLocation->id)->firstOrFail()->tenant_id);
        $this->assertSame(0, ReportSummary::withoutGlobalScope(TenantScope::class)->where('tenant_id', $suspended['tenant']->id)->count());
        $this->assertSame(1, ReportSummary::withoutGlobalScope(TenantScope::class)->where('location_id', $onboardingLocation->id)->count());

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/summaries'))->assertStatus(422);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/summaries?from=2026-06-17&to=2026-06-16'))->assertStatus(422);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/summaries?from=2025-01-01&to=2026-01-02'))->assertStatus(422);
        $this->artisan('reports:build-summaries', ['--date' => '2026-02-31'])->assertFailed();
    }

    private function location(string $tenantId, string $timezone, string $name = 'Harbor'): Location
    {
        return Location::factory()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'timezone' => $timezone,
        ]);
    }

    private function service(string $tenantId, string $locationId): Service
    {
        return Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'name' => 'Kayak',
            'kind' => ServiceKind::Rental,
        ]);
    }

    private function hours(string $tenantId, string $locationId, int $weekday, string $opens, string $closes): void
    {
        AvailabilitySchedule::query()->create([
            'tenant_id' => $tenantId,
            'owner_type' => ScheduleOwnerType::Location,
            'owner_id' => $locationId,
            'weekday' => $weekday,
            'opens_local' => $opens,
            'closes_local' => $closes,
        ]);
    }

    private function booking(
        string $tenantId,
        Location $location,
        Service $service,
        BookingStatus $status,
        string $starts,
        string $ends,
        string $createdAt,
    ): Booking {
        $booking = Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => 1,
            'payload_hash' => str_repeat('a', 64),
        ]);
        $booking->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $booking;
    }

    private function snapshot(string $tenantId, Booking $booking, int $servicesCents, int $customerTotalCents): void
    {
        PricingSnapshot::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/11',
            'payload' => [
                'services_cents' => $servicesCents,
                'customer_total_cents' => $customerTotalCents,
            ],
        ]);
    }
}
