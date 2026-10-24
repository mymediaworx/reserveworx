<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class TourAttendanceTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_booked_seats_count_confirmed_and_no_show_participants_only(): void
    {
        $workspace = $this->workspace('dock.test');
        $harbor = $this->location($workspace['tenant']->id, 'UTC', 'Harbor');
        $sunset = $this->service($workspace['tenant']->id, $harbor->id, 'Sunset tour', 20);
        $morning = $this->service($workspace['tenant']->id, $harbor->id, 'Morning tour', 8);
        $departure = $this->departure($workspace['tenant']->id, $harbor, $sunset, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 10);
        $cancelledDeparture = $this->departure(
            $workspace['tenant']->id,
            $harbor,
            $morning,
            '2026-06-16 12:00:00',
            '2026-06-16 13:00:00',
            4,
            DepartureStatus::Cancelled,
        );
        $outside = $this->departure($workspace['tenant']->id, $harbor, $sunset, '2026-06-15 15:00:00', '2026-06-15 16:00:00', 10);
        $this->party($workspace['tenant']->id, $harbor, $sunset, $departure, BookingStatus::Confirmed, 4);
        $this->party($workspace['tenant']->id, $harbor, $sunset, $departure, BookingStatus::Confirmed, 2);
        $this->party($workspace['tenant']->id, $harbor, $sunset, $departure, BookingStatus::NoShow, 3);
        $held = $this->party($workspace['tenant']->id, $harbor, $sunset, $departure, BookingStatus::Held, 5);
        $expired = $this->party($workspace['tenant']->id, $harbor, $sunset, $departure, BookingStatus::Held, 9);
        $this->party($workspace['tenant']->id, $harbor, $sunset, $departure, BookingStatus::Cancelled, 7);
        $this->party($workspace['tenant']->id, $harbor, $sunset, $departure, BookingStatus::PendingPayment, 11);
        $this->party($workspace['tenant']->id, $harbor, $morning, $cancelledDeparture, BookingStatus::Confirmed, 1);
        $this->party($workspace['tenant']->id, $harbor, $sunset, $outside, BookingStatus::Confirmed, 6);
        $this->hold($workspace['tenant']->id, $held, '2026-06-16 18:00:00');
        $this->hold($workspace['tenant']->id, $expired, '2020-01-01 00:00:00');

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/tours?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.departure_id', $cancelledDeparture->id)
            ->assertJsonPath('data.0.service_id', $morning->id)
            ->assertJsonPath('data.0.service', 'Morning tour')
            ->assertJsonPath('data.0.location_id', $harbor->id)
            ->assertJsonPath('data.0.location', 'Harbor')
            ->assertJsonPath('data.0.timezone', 'UTC')
            ->assertJsonPath('data.0.starts_at', '2026-06-16T12:00:00')
            ->assertJsonPath('data.0.capacity', 4)
            ->assertJsonPath('data.0.seats_booked', 1)
            ->assertJsonPath('data.0.seats_attended', 1)
            ->assertJsonPath('data.0.no_show_seats', 0)
            ->assertJsonPath('data.0.fill_rate_bps', 2500)
            ->assertJsonPath('data.0.attendance_rate_bps', 10000)
            ->assertJsonPath('data.1.departure_id', $departure->id)
            ->assertJsonPath('data.1.service', 'Sunset tour')
            ->assertJsonPath('data.1.starts_at', '2026-06-16T15:00:00')
            ->assertJsonPath('data.1.capacity', 10)
            ->assertJsonPath('data.1.seats_booked', 9)
            ->assertJsonPath('data.1.seats_attended', 6)
            ->assertJsonPath('data.1.no_show_seats', 3)
            ->assertJsonPath('data.1.fill_rate_bps', 9000)
            ->assertJsonPath('data.1.attendance_rate_bps', 6667);
    }

    public function test_rates_use_half_up_basis_points_and_a_zero_cap_is_null(): void
    {
        $workspace = $this->workspace('dock.test');
        $harbor = $this->location($workspace['tenant']->id, 'UTC', 'Harbor');
        $capped = $this->service($workspace['tenant']->id, $harbor->id, 'Capped', 9);
        $open = $this->service($workspace['tenant']->id, $harbor->id, 'Open boat', null);
        $fallback = $this->service($workspace['tenant']->id, $harbor->id, 'Fallback', 8);
        $seatsWin = $this->service($workspace['tenant']->id, $harbor->id, 'Seats win', 12);
        $third = $this->departure($workspace['tenant']->id, $harbor, $capped, '2026-06-16 10:00:00', '2026-06-16 11:00:00', 3);
        $uncapped = $this->departure($workspace['tenant']->id, $harbor, $open, '2026-06-16 12:00:00', '2026-06-16 13:00:00', null);
        $empty = $this->departure($workspace['tenant']->id, $harbor, $fallback, '2026-06-16 14:00:00', '2026-06-16 15:00:00', null);
        $ownCap = $this->departure($workspace['tenant']->id, $harbor, $seatsWin, '2026-06-16 16:00:00', '2026-06-16 17:00:00', 6);
        $this->party($workspace['tenant']->id, $harbor, $capped, $third, BookingStatus::Confirmed, 1);
        $this->party($workspace['tenant']->id, $harbor, $capped, $third, BookingStatus::NoShow, 1);
        $this->party($workspace['tenant']->id, $harbor, $open, $uncapped, BookingStatus::Confirmed, 2);
        $this->party($workspace['tenant']->id, $harbor, $seatsWin, $ownCap, BookingStatus::Confirmed, 3);

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/tours?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.departure_id', $third->id)
            ->assertJsonPath('data.0.capacity', 3)
            ->assertJsonPath('data.0.seats_booked', 2)
            ->assertJsonPath('data.0.seats_attended', 1)
            ->assertJsonPath('data.0.no_show_seats', 1)
            ->assertJsonPath('data.0.fill_rate_bps', 6667)
            ->assertJsonPath('data.0.attendance_rate_bps', 5000)
            ->assertJsonPath('data.1.departure_id', $uncapped->id)
            ->assertJsonPath('data.1.capacity', 0)
            ->assertJsonPath('data.1.seats_booked', 2)
            ->assertJsonPath('data.1.seats_attended', 2)
            ->assertJsonPath('data.1.fill_rate_bps', null)
            ->assertJsonPath('data.1.attendance_rate_bps', 10000)
            ->assertJsonPath('data.2.departure_id', $empty->id)
            ->assertJsonPath('data.2.capacity', 8)
            ->assertJsonPath('data.2.seats_booked', 0)
            ->assertJsonPath('data.2.seats_attended', 0)
            ->assertJsonPath('data.2.no_show_seats', 0)
            ->assertJsonPath('data.2.fill_rate_bps', 0)
            ->assertJsonPath('data.2.attendance_rate_bps', null)
            ->assertJsonPath('data.3.departure_id', $ownCap->id)
            ->assertJsonPath('data.3.capacity', 6)
            ->assertJsonPath('data.3.seats_booked', 3)
            ->assertJsonPath('data.3.fill_rate_bps', 5000)
            ->assertJsonPath('data.3.attendance_rate_bps', 10000);
    }

    public function test_a_departure_falls_on_the_location_local_date(): void
    {
        $workspace = $this->workspace('dock.test');
        $chicago = $this->location($workspace['tenant']->id, 'America/Chicago', 'Chicago');
        $york = $this->location($workspace['tenant']->id, 'America/New_York', 'New York');
        $lake = $this->service($workspace['tenant']->id, $chicago->id, 'Chicago tour', 10);
        $river = $this->service($workspace['tenant']->id, $york->id, 'York tour', 10);
        $before = $this->departure($workspace['tenant']->id, $chicago, $lake, '2026-06-16 04:59:59', '2026-06-16 06:00:00', 10);
        $midnight = $this->departure($workspace['tenant']->id, $chicago, $lake, '2026-06-16 05:00:00', '2026-06-16 06:00:00', 10);
        $late = $this->departure($workspace['tenant']->id, $chicago, $lake, '2026-06-17 04:59:59', '2026-06-17 06:00:00', 10);
        $next = $this->departure($workspace['tenant']->id, $chicago, $lake, '2026-06-17 05:00:00', '2026-06-17 06:00:00', 10);
        $yorkBefore = $this->departure($workspace['tenant']->id, $york, $river, '2026-06-16 03:59:59', '2026-06-16 05:00:00', 10);
        $yorkMidnight = $this->departure($workspace['tenant']->id, $york, $river, '2026-06-16 04:00:00', '2026-06-16 05:00:00', 10);
        foreach ([$before, $midnight, $late, $next, $yorkBefore, $yorkMidnight] as $departure) {
            $service = $departure->service_id === $lake->id ? $lake : $river;
            $location = $departure->location_id === $chicago->id ? $chicago : $york;
            $this->party($workspace['tenant']->id, $location, $service, $departure, BookingStatus::Confirmed, 1);
        }

        $this->actingAsWorkspace($workspace);
        $rows = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/tours?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->json('data');
        $this->assertIsArray($rows);
        $this->assertSame([
            [$midnight->id, 'America/Chicago', '2026-06-16T00:00:00'],
            [$yorkMidnight->id, 'America/New_York', '2026-06-16T00:00:00'],
            [$late->id, 'America/Chicago', '2026-06-16T23:59:59'],
        ], array_map(fn (mixed $row): array => [
            is_array($row) ? $row['departure_id'] : null,
            is_array($row) ? $row['timezone'] : null,
            is_array($row) ? $row['starts_at'] : null,
        ], $rows));
        $ids = array_map(fn (mixed $row): mixed => is_array($row) ? $row['departure_id'] : null, $rows);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($next->id, $ids);
        $this->assertNotContains($yorkBefore->id, $ids);
    }

    public function test_csv_rows_match_the_json_rows_and_keep_a_null_rate_blank(): void
    {
        $workspace = $this->workspace('dock.test');
        $harbor = $this->location($workspace['tenant']->id, 'UTC', 'Harbor');
        $named = $this->service($workspace['tenant']->id, $harbor->id, 'Sunset, "late"', 10);
        $open = $this->service($workspace['tenant']->id, $harbor->id, 'Open boat', null);
        $empty = $this->departure($workspace['tenant']->id, $harbor, $named, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 10);
        $uncapped = $this->departure($workspace['tenant']->id, $harbor, $open, '2026-06-16 18:00:00', '2026-06-16 19:00:00', null);
        $this->party($workspace['tenant']->id, $harbor, $open, $uncapped, BookingStatus::Confirmed, 2);
        $this->party($workspace['tenant']->id, $harbor, $named, $empty, BookingStatus::Held, 4);

        $this->actingAsWorkspace($workspace);
        $query = 'from=2026-06-16&to=2026-06-16';
        $json = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/tours?'.$query))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($json);
        $csv = $this->get($this->tenantUrl('dock.test', '/api/v1/reports/tours/export?'.$query))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename="tours.csv"');
        $rows = $this->table((string) $csv->getContent());
        $this->assertSame([
            'departure_id',
            'service_id',
            'service',
            'location_id',
            'location',
            'timezone',
            'starts_at',
            'capacity',
            'seats_booked',
            'seats_attended',
            'no_show_seats',
            'fill_rate_bps',
            'attendance_rate_bps',
        ], $rows[0]);
        $this->assertCount(count($json) + 1, $rows);
        foreach ($json as $index => $row) {
            $this->assertIsArray($row);
            $this->assertSame($this->cells([
                $row['departure_id'],
                $row['service_id'],
                $row['service'],
                $row['location_id'],
                $row['location'],
                $row['timezone'],
                $row['starts_at'],
                $row['capacity'],
                $row['seats_booked'],
                $row['seats_attended'],
                $row['no_show_seats'],
                $row['fill_rate_bps'],
                $row['attendance_rate_bps'],
            ]), $rows[$index + 1]);
        }
        $this->assertSame('Sunset, "late"', $rows[1][2]);
        $this->assertSame('0', $rows[1][8]);
        $this->assertSame('0', $rows[1][11]);
        $this->assertSame('', $rows[1][12]);
        $this->assertSame('', $rows[2][11]);
        $this->assertSame('10000', $rows[2][12]);
    }

    public function test_another_workspace_is_absent_and_a_bad_window_or_missing_permission_is_refused(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $staff = $this->workspace('staff.test', 'staff');
        $alphaLocation = $this->location($alpha['tenant']->id, 'UTC', 'Alpha');
        $betaLocation = $this->location($beta['tenant']->id, 'UTC', 'Beta');
        $alphaService = $this->service($alpha['tenant']->id, $alphaLocation->id, 'Alpha tour', 10);
        $betaService = $this->service($beta['tenant']->id, $betaLocation->id, 'Beta tour', 10);
        $alphaDeparture = $this->departure($alpha['tenant']->id, $alphaLocation, $alphaService, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 10);
        $betaDeparture = $this->departure($beta['tenant']->id, $betaLocation, $betaService, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 8);
        $this->party($alpha['tenant']->id, $alphaLocation, $alphaService, $alphaDeparture, BookingStatus::Confirmed, 2);
        $this->party($beta['tenant']->id, $betaLocation, $betaService, $betaDeparture, BookingStatus::Confirmed, 7);

        $this->actingAsWorkspace($alpha);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/tours?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.departure_id', $alphaDeparture->id)
            ->assertJsonPath('data.0.location_id', $alphaLocation->id)
            ->assertJsonPath('data.0.seats_booked', 2)
            ->assertJsonMissing(['departure_id' => $betaDeparture->id]);
        $alphaCsv = (string) $this->get($this->tenantUrl('alpha.test', '/api/v1/reports/tours/export?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString($betaDeparture->id, $alphaCsv);
        $this->assertStringNotContainsString($betaLocation->id, $alphaCsv);

        $foreign = $this->getJson($this->tenantUrl('beta.test', '/api/v1/reports/tours?from=2026-06-16&to=2026-06-16'));
        $this->assertContains($foreign->status(), [403, 404]);
        $foreignCsv = $this->getJson($this->tenantUrl('beta.test', '/api/v1/reports/tours/export?from=2026-06-16&to=2026-06-16'));
        $this->assertContains($foreignCsv->status(), [403, 404]);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/tours'))->assertStatus(422);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/tours?from=2026-06-17&to=2026-06-16'))->assertStatus(422);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/tours?from=2025-01-01&to=2026-01-02'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/tours/export?from=2025-01-01&to=2026-01-02'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/tours?from=2025-01-01&to=2026-01-01'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $permission = Permission::query()->where('slug', 'bookings.view')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->actingAsWorkspace($staff);
        $this->getJson($this->tenantUrl('staff.test', '/api/v1/reports/tours?from=2026-06-16&to=2026-06-16'))
            ->assertForbidden();
        $this->getJson($this->tenantUrl('staff.test', '/api/v1/reports/tours/export?from=2026-06-16&to=2026-06-16'))
            ->assertForbidden();
    }

    private function location(string $tenantId, string $timezone, string $name): Location
    {
        return Location::factory()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'timezone' => $timezone,
        ]);
    }

    private function service(string $tenantId, string $locationId, string $name, ?int $maximum): Service
    {
        return Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'name' => $name,
            'kind' => ServiceKind::Tour,
            'max_participants' => $maximum,
        ]);
    }

    private function departure(
        string $tenantId,
        Location $location,
        Service $service,
        string $starts,
        string $ends,
        ?int $seats,
        DepartureStatus $status = DepartureStatus::Scheduled,
    ): Departure {
        return Departure::query()->create([
            'tenant_id' => $tenantId,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'private' => false,
            'seats' => $seats,
            'status' => $status,
        ]);
    }

    private function party(
        string $tenantId,
        Location $location,
        Service $service,
        Departure $departure,
        BookingStatus $status,
        int $participants,
    ): Booking {
        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'departure_id' => $departure->id,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $departure->starts_at,
            'ends_at' => $departure->ends_at,
            'participants' => $participants,
            'payload_hash' => str_repeat('a', 64),
        ]);
    }

    private function hold(string $tenantId, Booking $booking, string $expires): void
    {
        Hold::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'quantity' => 1,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
            'expires_at' => $expires,
        ]);
    }

    /**
     * @param  list<int|string|null>  $values
     * @return list<string>
     */
    private function cells(array $values): array
    {
        return array_map(
            fn (int|string|null $value): string => $value === null ? '' : (string) $value,
            $values,
        );
    }

    /**
     * @return list<list<string>>
     */
    private function table(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $cells = [];

            foreach ($row as $cell) {
                $cells[] = is_string($cell) ? $cell : '';
            }

            $rows[] = $cells;
        }

        fclose($handle);

        return $rows;
    }
}
