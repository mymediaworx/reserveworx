<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Location;
use App\Models\PricingSnapshot;
use App\Models\ReportSummary;
use App\Models\Service;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ServiceRevenueTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_revenue_is_grouped_by_service_and_the_location_date_it_starts(): void
    {
        $workspace = $this->workspace('dock.test');
        $harbor = $this->location($workspace['tenant']->id, 'UTC', 'Harbor');
        $creek = $this->location($workspace['tenant']->id, 'UTC', 'Creek');
        $kayak = $this->service($workspace['tenant']->id, $harbor->id, 'Kayak');
        $tour = $this->service($workspace['tenant']->id, $harbor->id, 'Sunset tour');
        $canoe = $this->service($workspace['tenant']->id, $creek->id, 'Canoe');
        $confirmed = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $again = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 18:00:00', '2026-06-16 19:00:00');
        $noShow = $this->booking($workspace['tenant']->id, $harbor, $tour, BookingStatus::NoShow, '2026-06-17 15:00:00', '2026-06-17 16:00:00');
        $otherPlace = $this->booking($workspace['tenant']->id, $creek, $canoe, BookingStatus::Confirmed, '2026-06-16 12:00:00', '2026-06-16 13:00:00');
        $held = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Held, '2026-06-16 20:00:00', '2026-06-16 21:00:00');
        $cancelled = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Cancelled, '2026-06-16 21:00:00', '2026-06-16 22:00:00');
        $this->snapshot($workspace['tenant']->id, $confirmed, ['services_cents' => 10_000, 'basis_cents' => 99_999, 'tax_cents' => 800, 'customer_total_cents' => 12_000]);
        $this->snapshot($workspace['tenant']->id, $again, ['basis_cents' => 2_500, 'customer_total_cents' => 2_500]);
        $this->snapshot($workspace['tenant']->id, $noShow, ['services_cents' => 4_000, 'customer_total_cents' => 5_000]);
        $this->snapshot($workspace['tenant']->id, $otherPlace, ['services_cents' => 3_000]);
        $this->snapshot($workspace['tenant']->id, $held, ['services_cents' => 9_999]);
        $this->snapshot($workspace['tenant']->id, $cancelled, ['services_cents' => 8_888]);
        $missing = $this->booking($workspace['tenant']->id, $harbor, $tour, BookingStatus::Confirmed, '2026-06-17 18:00:00', '2026-06-17 19:00:00');

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/revenue?from=2026-06-16&to=2026-06-17'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.service_id', $canoe->id)
            ->assertJsonPath('data.0.service', 'Canoe')
            ->assertJsonPath('data.0.location_id', $creek->id)
            ->assertJsonPath('data.0.date', '2026-06-16')
            ->assertJsonPath('data.0.bookings', 1)
            ->assertJsonPath('data.0.gross_cents', 3000)
            ->assertJsonPath('data.1.service', 'Kayak')
            ->assertJsonPath('data.1.date', '2026-06-16')
            ->assertJsonPath('data.1.bookings', 2)
            ->assertJsonPath('data.1.gross_cents', 12500)
            ->assertJsonPath('data.2.service', 'Sunset tour')
            ->assertJsonPath('data.2.service_id', $tour->id)
            ->assertJsonPath('data.2.location_id', $harbor->id)
            ->assertJsonPath('data.2.date', '2026-06-17')
            ->assertJsonPath('data.2.bookings', 2)
            ->assertJsonPath('data.2.gross_cents', 4000);

        $this->assertNotNull($missing->id);
        $today = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/today?date=2026-06-16'))->assertOk()->json('data');
        $this->assertIsArray($today);
        $this->assertArrayNotHasKey('revenue', $today);
    }

    public function test_a_location_date_follows_that_locations_timezone_including_daylight_saving(): void
    {
        $workspace = $this->workspace('dock.test');
        $chicago = $this->location($workspace['tenant']->id, 'America/Chicago', 'Chicago');
        $york = $this->location($workspace['tenant']->id, 'America/New_York', 'New York');
        $kayak = $this->service($workspace['tenant']->id, $chicago->id, 'Kayak');
        $tour = $this->service($workspace['tenant']->id, $york->id, 'Tour');
        $beforeMidnight = $this->booking($workspace['tenant']->id, $chicago, $kayak, BookingStatus::Confirmed, '2026-06-16 04:59:59', '2026-06-16 06:00:00');
        $atMidnight = $this->booking($workspace['tenant']->id, $chicago, $kayak, BookingStatus::Confirmed, '2026-06-16 05:00:00', '2026-06-16 06:00:00');
        $spring = $this->booking($workspace['tenant']->id, $york, $tour, BookingStatus::Confirmed, '2026-03-08 04:30:00', '2026-03-08 06:00:00');
        $beforeFallback = $this->booking($workspace['tenant']->id, $york, $tour, BookingStatus::Confirmed, '2026-11-01 03:30:00', '2026-11-01 05:00:00');
        $duringFallback = $this->booking($workspace['tenant']->id, $york, $tour, BookingStatus::NoShow, '2026-11-01 05:30:00', '2026-11-01 07:00:00');
        $afterFallback = $this->booking($workspace['tenant']->id, $york, $tour, BookingStatus::Confirmed, '2026-11-01 06:30:00', '2026-11-01 08:00:00');
        foreach ([$beforeMidnight, $atMidnight, $spring, $beforeFallback, $duringFallback, $afterFallback] as $booking) {
            $this->snapshot($workspace['tenant']->id, $booking, ['services_cents' => 1_000]);
        }

        $this->actingAsWorkspace($workspace);
        $summer = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/revenue?from=2026-06-15&to=2026-06-16'))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($summer);
        $this->assertSame([
            ['date' => '2026-06-15', 'bookings' => 1, 'gross_cents' => 1000],
            ['date' => '2026-06-16', 'bookings' => 1, 'gross_cents' => 1000],
        ], array_map(fn (mixed $row): array => [
            'date' => is_array($row) ? $row['date'] : null,
            'bookings' => is_array($row) ? $row['bookings'] : null,
            'gross_cents' => is_array($row) ? $row['gross_cents'] : null,
        ], $summer));

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/revenue?from=2026-03-07&to=2026-03-08'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-03-07')
            ->assertJsonPath('data.0.service', 'Tour');

        $fall = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/revenue?from=2026-10-31&to=2026-11-01'))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($fall);
        $this->assertSame([
            ['date' => '2026-10-31', 'bookings' => 1],
            ['date' => '2026-11-01', 'bookings' => 2],
        ], array_map(fn (mixed $row): array => [
            'date' => is_array($row) ? $row['date'] : null,
            'bookings' => is_array($row) ? $row['bookings'] : null,
        ], $fall));
    }

    public function test_service_revenue_for_a_day_matches_the_stored_summary_gross(): void
    {
        $workspace = $this->workspace('dock.test');
        $location = $this->location($workspace['tenant']->id, 'UTC');
        $kayak = $this->service($workspace['tenant']->id, $location->id, 'Kayak');
        $tour = $this->service($workspace['tenant']->id, $location->id, 'Sunset tour');
        $confirmed = $this->booking($workspace['tenant']->id, $location, $kayak, BookingStatus::Confirmed, '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $noShow = $this->booking($workspace['tenant']->id, $location, $tour, BookingStatus::NoShow, '2026-06-16 17:00:00', '2026-06-16 18:00:00');
        $held = $this->booking($workspace['tenant']->id, $location, $kayak, BookingStatus::Held, '2026-06-16 18:00:00', '2026-06-16 19:00:00');
        $this->snapshot($workspace['tenant']->id, $confirmed, ['services_cents' => 10_000, 'customer_total_cents' => 10_300]);
        $this->snapshot($workspace['tenant']->id, $noShow, ['services_cents' => 4_000, 'customer_total_cents' => 5_000]);
        $this->snapshot($workspace['tenant']->id, $held, ['services_cents' => 9_999]);

        $this->artisan('reports:build-summaries', ['--date' => '2026-06-16'])->assertSuccessful();
        $stored = ReportSummary::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->where('location_id', $location->id)
            ->firstOrFail();

        $this->actingAsWorkspace($workspace);
        $rows = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/revenue?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($rows);
        $gross = 0;
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $gross += is_numeric($row['gross_cents'] ?? null) ? (int) $row['gross_cents'] : 0;
        }
        $this->assertSame(14000, $gross);
        $this->assertSame($stored->gross_cents, $gross);
    }

    public function test_another_workspace_is_not_included_and_a_bad_window_is_refused(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaLocation = $this->location($alpha['tenant']->id, 'UTC', 'Alpha');
        $betaLocation = $this->location($beta['tenant']->id, 'UTC', 'Beta');
        $alphaService = $this->service($alpha['tenant']->id, $alphaLocation->id, 'Kayak');
        $betaService = $this->service($beta['tenant']->id, $betaLocation->id, 'Kayak');
        $alphaBooking = $this->booking($alpha['tenant']->id, $alphaLocation, $alphaService, BookingStatus::Confirmed, '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $betaBooking = $this->booking($beta['tenant']->id, $betaLocation, $betaService, BookingStatus::Confirmed, '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $this->snapshot($alpha['tenant']->id, $alphaBooking, ['services_cents' => 1_000]);
        $this->snapshot($beta['tenant']->id, $betaBooking, ['services_cents' => 9_000]);

        $this->actingAsWorkspace($alpha);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/revenue?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.service_id', $alphaService->id)
            ->assertJsonPath('data.0.gross_cents', 1000)
            ->assertJsonPath('data.0.location_id', $alphaLocation->id);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/revenue'))->assertStatus(422);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/revenue?from=2026-06-17&to=2026-06-16'))->assertStatus(422);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/revenue?from=2025-01-01&to=2026-01-02'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/revenue?from=2025-01-01&to=2026-01-01'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function location(string $tenantId, string $timezone, string $name = 'Harbor'): Location
    {
        return Location::factory()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'timezone' => $timezone,
        ]);
    }

    private function service(string $tenantId, string $locationId, string $name): Service
    {
        return Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'name' => $name,
            'kind' => ServiceKind::Rental,
        ]);
    }

    private function booking(
        string $tenantId,
        Location $location,
        Service $service,
        BookingStatus $status,
        string $starts,
        string $ends,
    ): Booking {
        return Booking::query()->create([
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
    }

    /**
     * @param  array<string, int>  $payload
     */
    private function snapshot(string $tenantId, Booking $booking, array $payload): void
    {
        PricingSnapshot::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/11',
            'payload' => $payload,
        ]);
    }
}
