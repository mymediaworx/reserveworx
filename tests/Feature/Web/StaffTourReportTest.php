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
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Tenancy\StaffLocationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffTourReportTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_reports_list_tour_capacity_and_attendance_for_the_range(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $harbor = $this->location($alpha['tenant']->id, 'UTC', 'Harbor');
        $creek = $this->location($alpha['tenant']->id, 'America/New_York', 'Creek');
        $foreign = $this->location($beta['tenant']->id, 'UTC', 'Foreign dock');
        $sunset = $this->service($alpha['tenant']->id, $harbor->id, 'Sunset tour', 20);
        $openBoat = $this->service($alpha['tenant']->id, $harbor->id, 'Open boat', null);
        $morning = $this->service($alpha['tenant']->id, $creek->id, 'Creek paddle', 8);
        $secret = $this->service($beta['tenant']->id, $foreign->id, 'Secret sunset', 12);
        $departure = $this->departure($alpha['tenant']->id, $harbor, $sunset, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 10);
        $cancelled = $this->departure(
            $alpha['tenant']->id,
            $harbor,
            $sunset,
            '2026-06-16 12:00:00',
            '2026-06-16 13:00:00',
            null,
            DepartureStatus::Cancelled,
        );
        $open = $this->departure($alpha['tenant']->id, $harbor, $sunset, '2026-06-16 18:00:00', '2026-06-16 19:00:00', null);
        $zero = $this->departure($alpha['tenant']->id, $harbor, $openBoat, '2026-06-16 20:00:00', '2026-06-16 21:00:00', null);
        $localEvening = $this->departure($alpha['tenant']->id, $creek, $morning, '2026-06-17 02:00:00', '2026-06-17 03:00:00', 8);
        $outside = $this->departure($alpha['tenant']->id, $harbor, $sunset, '2026-06-15 15:00:00', '2026-06-15 16:00:00', 10);
        $spy = $this->departure($beta['tenant']->id, $foreign, $secret, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 6);
        $this->party($alpha['tenant']->id, $harbor, $sunset, $departure, BookingStatus::Confirmed, 4);
        $this->party($alpha['tenant']->id, $harbor, $sunset, $departure, BookingStatus::Confirmed, 2);
        $this->party($alpha['tenant']->id, $harbor, $sunset, $departure, BookingStatus::NoShow, 3);
        $this->party($alpha['tenant']->id, $harbor, $sunset, $departure, BookingStatus::Held, 5);
        $this->party($alpha['tenant']->id, $harbor, $sunset, $departure, BookingStatus::Cancelled, 7);
        $this->party($alpha['tenant']->id, $harbor, $sunset, $open, BookingStatus::Confirmed, 2);
        $this->party($alpha['tenant']->id, $creek, $morning, $localEvening, BookingStatus::Confirmed, 1);
        $this->party($alpha['tenant']->id, $harbor, $sunset, $outside, BookingStatus::Confirmed, 6);
        $this->party($beta['tenant']->id, $foreign, $secret, $spy, BookingStatus::Confirmed, 4);

        $url = '/staff/reports?from=2026-06-16&to=2026-06-16';
        $page = $this->actingAs($alpha['user'])->get($this->tenantUrl('alpha.test', $url));
        $page->assertOk()
            ->assertSee('Tour capacity and attendance')
            ->assertSee('A held or cancelled booking is not a seat.')
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.reports-page .report-list', false)
            ->assertDontSee('Secret sunset')
            ->assertDontSee($spy->id)
            ->assertDontSee($outside->id);
        $html = (string) $page->getContent();
        $booked = $this->tour($html, $departure->id);
        $this->assertSame('Sunset tour', $booked['service']);
        $this->assertSame('Harbor', $booked['location']);
        $this->assertSame('2026-06-16T15:00:00', $booked['starts']);
        $this->assertSame('10', $booked['capacity']);
        $this->assertSame('9', $booked['booked']);
        $this->assertSame('6', $booked['attended']);
        $this->assertSame('3', $booked['no_show']);
        $this->assertSame('9000', $booked['fill']);
        $this->assertSame('6667', $booked['attendance']);
        $this->assertStringContainsString('9 of 10 booked', $html);
        $this->assertStringContainsString('Fill 90.0%', $html);
        $this->assertStringContainsString('Attendance 66.7%', $html);

        $emptyCap = $this->tour($html, $cancelled->id);
        $this->assertSame('20', $emptyCap['capacity']);
        $this->assertSame('0', $emptyCap['booked']);
        $this->assertSame('0', $emptyCap['fill']);
        $this->assertSame('', $emptyCap['attendance']);

        $noCap = $this->tour($html, $zero->id);
        $this->assertSame('Open boat', $noCap['service']);
        $this->assertSame('0', $noCap['capacity']);
        $this->assertSame('0', $noCap['booked']);
        $this->assertSame('', $noCap['fill']);
        $this->assertSame('', $noCap['attendance']);

        $uncapped = $this->tour($html, $open->id);
        $this->assertSame('20', $uncapped['capacity']);
        $this->assertSame('2', $uncapped['booked']);
        $this->assertSame('2', $uncapped['attended']);
        $this->assertSame('1000', $uncapped['fill']);
        $this->assertSame('10000', $uncapped['attendance']);

        $evening = $this->tour($html, $localEvening->id);
        $this->assertSame('Creek paddle', $evening['service']);
        $this->assertSame('Creek', $evening['location']);
        $this->assertSame('2026-06-16T22:00:00', $evening['starts']);
        $this->assertSame('1', $evening['booked']);
        $this->assertSame('1', $evening['attended']);

        $nextDay = $this->actingAs($alpha['user'])->get($this->tenantUrl('alpha.test', '/staff/reports?from=2026-06-17&to=2026-06-17'));
        $nextDay->assertOk()->assertDontSee($localEvening->id)->assertSee('No tour departures in this range.');

        $csv = (string) $this->get($this->tenantUrl('alpha.test', '/staff/reports.csv?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Secret sunset', $csv);
        $this->assertStringNotContainsString($outside->id, $csv);
        $row = $this->csvTour($csv, $departure->id);
        $this->assertSame('tour', $row['record']);
        $this->assertSame('Harbor', $row['location']);
        $this->assertSame('2026-06-16', $row['date']);
        $this->assertSame('Sunset tour', $row['service']);
        $this->assertSame('2026-06-16T15:00:00', $row['starts_at']);
        $this->assertSame('10', $row['capacity']);
        $this->assertSame('9', $row['seats_booked']);
        $this->assertSame('6', $row['seats_attended']);
        $this->assertSame('3', $row['no_show_seats']);
        $this->assertSame('9000', $row['fill_rate_bps']);
        $this->assertSame('6667', $row['attendance_rate_bps']);
        $this->assertSame('0', $this->csvTour($csv, $cancelled->id)['fill_rate_bps']);
        $this->assertSame('', $this->csvTour($csv, $cancelled->id)['attendance_rate_bps']);
        $this->assertSame('', $this->csvTour($csv, $zero->id)['fill_rate_bps']);
        $this->assertSame('', $this->csvTour($csv, $zero->id)['attendance_rate_bps']);
        $this->assertSame('2026-06-16T22:00:00', $this->csvTour($csv, $localEvening->id)['starts_at']);

        $harborPage = $this->get($this->tenantUrl('alpha.test', $url.'&location='.$harbor->id));
        $harborPage->assertOk()->assertSee($departure->id)->assertDontSee($localEvening->id)->assertDontSee('Creek paddle');
        $harborCsv = (string) $this->get($this->tenantUrl('alpha.test', '/staff/reports.csv?from=2026-06-16&to=2026-06-16&location='.$harbor->id))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString($departure->id, $harborCsv);
        $this->assertStringNotContainsString($localEvening->id, $harborCsv);
        $this->assertStringNotContainsString('Creek paddle', $harborCsv);

        $this->actingAs($beta['user'])
            ->get($this->tenantUrl('beta.test', $url))
            ->assertOk()
            ->assertSee('Secret sunset')
            ->assertSee($spy->id)
            ->assertDontSee('Sunset tour')
            ->assertDontSee($departure->id);
    }

    public function test_a_location_limited_member_does_not_see_another_locations_departure(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        $harbor = $this->location($alpha['tenant']->id, 'UTC', 'Harbor');
        $creek = $this->location($alpha['tenant']->id, 'UTC', 'Creek');
        $sunset = $this->service($alpha['tenant']->id, $harbor->id, 'Sunset tour', 10);
        $paddle = $this->service($alpha['tenant']->id, $creek->id, 'Creek paddle', 8);
        $harborDeparture = $this->departure($alpha['tenant']->id, $harbor, $sunset, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 10);
        $creekDeparture = $this->departure($alpha['tenant']->id, $creek, $paddle, '2026-06-16 12:00:00', '2026-06-16 13:00:00', 8);
        $this->party($alpha['tenant']->id, $harbor, $sunset, $harborDeparture, BookingStatus::Confirmed, 2);
        $this->party($alpha['tenant']->id, $creek, $paddle, $creekDeparture, BookingStatus::Confirmed, 3);
        $this->limit($alpha['membership'], $harbor->id);

        $url = '/staff/reports?from=2026-06-16&to=2026-06-16';
        $page = $this->actingAs($alpha['user'])->get($this->tenantUrl('alpha.test', $url));
        $page->assertOk()
            ->assertSee($harborDeparture->id)
            ->assertDontSee($creekDeparture->id)
            ->assertDontSee('Creek paddle')
            ->assertDontSee($creek->id);
        $csv = (string) $this->get($this->tenantUrl('alpha.test', '/staff/reports.csv?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString($harborDeparture->id, $csv);
        $this->assertStringNotContainsString($creekDeparture->id, $csv);
        $this->assertStringNotContainsString('Creek paddle', $csv);
        $this->get($this->tenantUrl('alpha.test', $url.'&location='.$creek->id))->assertNotFound();
    }

    public function test_a_member_without_booking_access_cannot_open_the_tour_rows(): void
    {
        $staff = $this->workspace('staff.test', 'staff');
        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id);

        $this->actingAs($staff['user']);
        $this->get($this->tenantUrl('staff.test', '/staff/reports?from=2026-06-16&to=2026-06-16'))->assertForbidden();
        $this->get($this->tenantUrl('staff.test', '/staff/reports.csv?from=2026-06-16&to=2026-06-16'))->assertForbidden();
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
    ): void {
        Booking::query()->create([
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

    /**
     * @return array{service: string, location: string, starts: string, capacity: string, booked: string, attended: string, no_show: string, fill: string, attendance: string}
     */
    private function tour(string $html, string $departureId): array
    {
        $this->assertSame(1, preg_match('/data-tour="'.preg_quote($departureId, '/').'"([^>]*)/', $html, $matches));
        $attrs = $matches[1];

        return [
            'service' => $this->attr($attrs, 'data-tour-service'),
            'location' => $this->attr($attrs, 'data-tour-location'),
            'starts' => $this->attr($attrs, 'data-tour-starts'),
            'capacity' => $this->attr($attrs, 'data-tour-capacity'),
            'booked' => $this->attr($attrs, 'data-tour-booked'),
            'attended' => $this->attr($attrs, 'data-tour-attended'),
            'no_show' => $this->attr($attrs, 'data-tour-no-show'),
            'fill' => $this->attr($attrs, 'data-tour-fill'),
            'attendance' => $this->attr($attrs, 'data-tour-attendance'),
        ];
    }

    private function attr(string $attrs, string $name): string
    {
        $this->assertSame(1, preg_match('/'.preg_quote($name, '/').'="([^"]*)"/', $attrs, $matches));

        return $matches[1];
    }

    /**
     * @return array<string, string>
     */
    private function csvTour(string $csv, string $departureId): array
    {
        foreach ($this->records($csv) as $row) {
            if (($row['record'] ?? '') === 'tour' && ($row['departure_id'] ?? '') === $departureId) {
                return $row;
            }
        }

        $this->fail('Missing tour row for '.$departureId);
    }

    /**
     * @return list<array<string, string>>
     */
    private function records(string $csv): array
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
        $header = array_shift($rows);
        $this->assertIsArray($header);
        $records = [];

        foreach ($rows as $row) {
            $record = [];

            foreach ($header as $index => $column) {
                $record[(string) $column] = $row[$index] ?? '';
            }

            $records[] = $record;
        }

        return $records;
    }
}
