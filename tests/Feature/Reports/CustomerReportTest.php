<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PricingSnapshot;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CustomerReportTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_repeat_customers_follow_the_first_counted_booking_and_the_stored_channel(): void
    {
        $workspace = $this->workspace('dock.test');
        $harbor = $this->location($workspace['tenant']->id, 'UTC', 'Harbor');
        $creek = $this->location($workspace['tenant']->id, 'UTC', 'Creek');
        $kayak = $this->service($workspace['tenant']->id, $harbor->id, 'Kayak');
        $canoe = $this->service($workspace['tenant']->id, $creek->id, 'Canoe');
        $ada = $this->customer($workspace['tenant']->id, 'Ada');
        $bea = $this->customer($workspace['tenant']->id, 'Bea');
        $cam = $this->customer($workspace['tenant']->id, 'Cam');
        $dee = $this->customer($workspace['tenant']->id, 'Dee');
        $gus = $this->customer($workspace['tenant']->id, 'Gus');

        $adaFirst = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::NoShow, '2026-06-01 15:00:00', '2026-06-01 16:00:00', $ada->id);
        $adaAgain = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 15:00:00', '2026-06-16 16:00:00', $ada->id);
        $adaCreek = $this->booking($workspace['tenant']->id, $creek, $canoe, BookingStatus::Confirmed, '2026-06-16 18:00:00', '2026-06-16 19:00:00', $ada->id);
        $adaLater = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-17 15:00:00', '2026-06-17 16:00:00', $ada->id);
        $beaBooking = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 12:00:00', '2026-06-16 13:00:00', $bea->id);
        $camHeld = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Held, '2026-06-10 12:00:00', '2026-06-10 13:00:00', $cam->id);
        $camShow = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::NoShow, '2026-06-16 13:00:00', '2026-06-16 14:00:00', $cam->id);
        $deeCancelled = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Cancelled, '2026-06-15 12:00:00', '2026-06-15 13:00:00', $dee->id);
        $deeKept = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 14:00:00', '2026-06-16 15:00:00', $dee->id);
        $anonymous = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 19:00:00', '2026-06-16 20:00:00', null);
        $gusFirst = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 17:00:00', '2026-06-16 18:00:00', $gus->id, '01JREPEATCST0000000000000A');
        $gusSecond = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 17:00:00', '2026-06-16 18:00:00', $gus->id, '01JREPEATCST0000000000000B');

        $this->snapshot($workspace['tenant']->id, $adaFirst, 'direct');
        $this->snapshot($workspace['tenant']->id, $adaAgain, 'direct');
        $this->snapshot($workspace['tenant']->id, $adaCreek, 'third_party');
        $this->snapshot($workspace['tenant']->id, $adaLater, 'direct');
        $this->snapshot($workspace['tenant']->id, $camShow, 'direct');
        $this->snapshot($workspace['tenant']->id, $deeKept, 'direct');
        $this->snapshot($workspace['tenant']->id, $gusFirst, 'direct');
        $this->snapshot($workspace['tenant']->id, $gusSecond, 'not_a_channel');
        $this->snapshot($workspace['tenant']->id, $camHeld, 'direct');
        $this->snapshot($workspace['tenant']->id, $deeCancelled, 'direct');

        $this->actingAsWorkspace($workspace);
        $report = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/customers?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($report);

        $customers = collect(is_array($report['customers'] ?? null) ? $report['customers'] : [])->keyBy('location_id');
        $harborRow = $customers[$harbor->id];
        $creekRow = $customers[$creek->id];
        $this->assertIsArray($harborRow);
        $this->assertIsArray($creekRow);
        $this->assertSame('2026-06-16', $harborRow['date']);
        $this->assertSame(5, $harborRow['customers']);
        $this->assertSame(4, $harborRow['new_customers']);
        $this->assertSame(1, $harborRow['repeat_customers']);
        $this->assertSame(7, $harborRow['bookings']);
        $this->assertSame(4, $harborRow['new_bookings']);
        $this->assertSame(2, $harborRow['repeat_bookings']);
        $this->assertSame(1, $harborRow['anonymous_bookings']);
        $this->assertSame(1, $creekRow['customers']);
        $this->assertSame(0, $creekRow['new_customers']);
        $this->assertSame(1, $creekRow['repeat_customers']);
        $this->assertSame(1, $creekRow['bookings']);
        $this->assertSame(0, $creekRow['new_bookings']);
        $this->assertSame(1, $creekRow['repeat_bookings']);
        $this->assertSame(0, $creekRow['anonymous_bookings']);
        $this->assertCount(2, $customers);
        $this->assertNotSame('2026-06-17', $harborRow['date']);
        $this->assertNotNull($adaLater->id);

        $attribution = collect(is_array($report['attribution'] ?? null) ? $report['attribution'] : []);
        $this->assertSame(4, $attribution->first(fn (mixed $row): bool => is_array($row) && $row['location_id'] === $harbor->id && $row['channel'] === 'direct')['bookings'] ?? null);
        $this->assertSame(3, $attribution->first(fn (mixed $row): bool => is_array($row) && $row['location_id'] === $harbor->id && $row['channel'] === 'unrecorded')['bookings'] ?? null);
        $this->assertSame(1, $attribution->first(fn (mixed $row): bool => is_array($row) && $row['location_id'] === $creek->id && $row['channel'] === 'third_party')['bookings'] ?? null);
        $this->assertNull($attribution->first(fn (mixed $row): bool => is_array($row) && $row['channel'] === 'not_a_channel'));
        $this->assertNotNull($anonymous->id);
        $this->assertNotNull($beaBooking->id);

        $today = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/today?date=2026-06-16'))->assertOk()->json('data');
        $this->assertIsArray($today);
        $this->assertArrayNotHasKey('attribution', $today);
        $this->assertArrayNotHasKey('new_customers', $today);
    }

    public function test_a_local_date_follows_the_location_timezone_and_an_earlier_day_is_a_repeat(): void
    {
        $workspace = $this->workspace('dock.test');
        $chicago = $this->location($workspace['tenant']->id, 'America/Chicago', 'Chicago');
        $york = $this->location($workspace['tenant']->id, 'America/New_York', 'New York');
        $kayak = $this->service($workspace['tenant']->id, $chicago->id, 'Kayak');
        $tour = $this->service($workspace['tenant']->id, $york->id, 'Tour');
        $ada = $this->customer($workspace['tenant']->id, 'Ada');
        $bea = $this->customer($workspace['tenant']->id, 'Bea');
        $beforeMidnight = $this->booking($workspace['tenant']->id, $chicago, $kayak, BookingStatus::Confirmed, '2026-06-16 04:59:59', '2026-06-16 06:00:00', $ada->id);
        $atMidnight = $this->booking($workspace['tenant']->id, $chicago, $kayak, BookingStatus::Confirmed, '2026-06-16 05:00:00', '2026-06-16 06:00:00', $ada->id);
        $spring = $this->booking($workspace['tenant']->id, $york, $tour, BookingStatus::Confirmed, '2026-03-08 06:30:00', '2026-03-08 08:00:00', $bea->id);
        $this->snapshot($workspace['tenant']->id, $beforeMidnight, 'direct');
        $this->snapshot($workspace['tenant']->id, $atMidnight, 'direct');
        $this->snapshot($workspace['tenant']->id, $spring, 'third_party');

        $this->actingAsWorkspace($workspace);
        $summer = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/customers?from=2026-06-15&to=2026-06-16'))
            ->assertOk()
            ->json('data.customers');
        $this->assertIsArray($summer);
        $this->assertSame([
            ['date' => '2026-06-15', 'new_customers' => 1, 'repeat_customers' => 0, 'new_bookings' => 1, 'repeat_bookings' => 0],
            ['date' => '2026-06-16', 'new_customers' => 0, 'repeat_customers' => 1, 'new_bookings' => 0, 'repeat_bookings' => 1],
        ], array_map(fn (mixed $row): array => [
            'date' => is_array($row) ? $row['date'] : null,
            'new_customers' => is_array($row) ? $row['new_customers'] : null,
            'repeat_customers' => is_array($row) ? $row['repeat_customers'] : null,
            'new_bookings' => is_array($row) ? $row['new_bookings'] : null,
            'repeat_bookings' => is_array($row) ? $row['repeat_bookings'] : null,
        ], $summer));

        $march = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/customers?from=2026-03-07&to=2026-03-08'))
            ->assertOk()
            ->json('data.customers');
        $this->assertIsArray($march);
        $this->assertSame('2026-03-08', is_array($march[0] ?? null) ? $march[0]['date'] : null);
        $this->assertSame($york->id, is_array($march[0] ?? null) ? $march[0]['location_id'] : null);
        $this->assertSame(1, is_array($march[0] ?? null) ? $march[0]['new_customers'] : null);
        $this->assertSame(0, is_array($march[0] ?? null) ? $march[0]['repeat_customers'] : null);
    }

    public function test_another_workspace_is_not_included_and_a_bad_window_is_refused(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaLocation = $this->location($alpha['tenant']->id, 'UTC', 'Alpha');
        $betaLocation = $this->location($beta['tenant']->id, 'UTC', 'Beta');
        $alphaService = $this->service($alpha['tenant']->id, $alphaLocation->id, 'Kayak');
        $betaService = $this->service($beta['tenant']->id, $betaLocation->id, 'Kayak');
        $alphaCustomer = $this->customer($alpha['tenant']->id, 'Ada');
        $betaCustomer = $this->customer($beta['tenant']->id, 'Ada');
        $alphaFirst = $this->booking($alpha['tenant']->id, $alphaLocation, $alphaService, BookingStatus::Confirmed, '2026-06-01 15:00:00', '2026-06-01 16:00:00', $alphaCustomer->id);
        $alphaAgain = $this->booking($alpha['tenant']->id, $alphaLocation, $alphaService, BookingStatus::Confirmed, '2026-06-16 15:00:00', '2026-06-16 16:00:00', $alphaCustomer->id);
        $betaBooking = $this->booking($beta['tenant']->id, $betaLocation, $betaService, BookingStatus::Confirmed, '2026-06-16 15:00:00', '2026-06-16 16:00:00', $betaCustomer->id);
        $this->snapshot($alpha['tenant']->id, $alphaFirst, 'direct');
        $this->snapshot($alpha['tenant']->id, $alphaAgain, 'direct');
        $this->snapshot($beta['tenant']->id, $betaBooking, 'third_party');

        $this->actingAsWorkspace($alpha);
        $row = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/customers?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->json('data.customers.0');
        $this->assertIsArray($row);
        $this->assertSame($alphaLocation->id, $row['location_id']);
        $this->assertSame(1, $row['repeat_customers']);
        $this->assertSame(0, $row['new_customers']);
        $channels = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/customers?from=2026-06-16&to=2026-06-16'))
            ->json('data.attribution');
        $this->assertIsArray($channels);
        $this->assertCount(1, $channels);
        $this->assertSame('direct', is_array($channels[0] ?? null) ? $channels[0]['channel'] : null);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/customers'))->assertStatus(422);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/customers?from=2026-06-17&to=2026-06-16'))->assertStatus(422);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/customers?from=2025-01-01&to=2026-01-02'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/customers?from=2025-01-01&to=2026-01-01'))
            ->assertOk()
            ->assertJsonPath('data.customers', [])
            ->assertJsonPath('data.attribution', []);
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

    private function customer(string $tenantId, string $name): Customer
    {
        return Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => strtolower($name).'@example.com',
        ]);
    }

    private function booking(
        string $tenantId,
        Location $location,
        Service $service,
        BookingStatus $status,
        string $starts,
        string $ends,
        ?string $customerId,
        ?string $id = null,
    ): Booking {
        return Booking::query()->create([
            'id' => $id,
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customerId,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => 1,
            'payload_hash' => str_repeat('a', 64),
        ]);
    }

    private function snapshot(string $tenantId, Booking $booking, string $channel): void
    {
        PricingSnapshot::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/11',
            'payload' => ['channel' => $channel, 'services_cents' => 1_000],
        ]);
    }
}
