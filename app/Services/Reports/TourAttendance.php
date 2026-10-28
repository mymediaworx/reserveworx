<?php

namespace App\Services\Reports;

use App\Domain\Fees\Rounding;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Service;
use App\Support\Display;
use App\Support\DisplayZone;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Seat cap, seats booked, and seats attended for each tour departure in a
 * location-local date window. Capacity is the departure seat cap, or the
 * service maximum when that cap is empty, and zero when both are empty.
 * Booked seats are participants on confirmed and no-show bookings. Attended
 * seats are the confirmed participants. No-show seats are the rest of the
 * booked seats. Held, expired, and cancelled bookings stay out, as do any
 * other status. Fill rate is booked over capacity, and attendance rate is
 * attended over booked, both in basis points rounded half-up. A zero capacity
 * or zero booked seats leaves that rate empty. The window is at most 366 days,
 * so this stays a live read and does not write a summary row.
 */
final class TourAttendance
{
    public function __construct(private readonly CsvTable $csv) {}

    /**
     * @return list<array{
     *     departure_id: string,
     *     service_id: string,
     *     service: string,
     *     location_id: string,
     *     location: string,
     *     timezone: string,
     *     starts_at: string,
     *     when: string,
     *     capacity: int,
     *     seats_booked: int,
     *     seats_attended: int,
     *     no_show_seats: int,
     *     fill_rate_bps: int|null,
     *     attendance_rate_bps: int|null
     * }>
     */
    public function build(string $from, string $to): array
    {
        $fromStart = CarbonImmutable::parse($from.' 00:00:00', 'UTC')->subDay();
        $toEnd = CarbonImmutable::parse($to.' 00:00:00', 'UTC')->addDays(2);

        $departures = Departure::query()
            ->with(['service', 'location'])
            ->where('starts_at', '>=', $fromStart)
            ->where('starts_at', '<', $toEnd)
            ->get()
            ->filter(function (Departure $departure) use ($from, $to): bool {
                $local = $this->localStart($departure->starts_at, $this->zone($departure->location));

                if ($local === null) {
                    return false;
                }

                $date = $local->format('Y-m-d');

                return $date >= $from && $date <= $to;
            })
            ->values();

        $seats = $this->seats($departures->pluck('id')->all());

        $rows = [];

        foreach ($departures as $departure) {
            $zone = $this->zone($departure->location);
            $local = $this->localStart($departure->starts_at, $zone);

            if ($local === null) {
                continue;
            }

            $departureId = (string) $departure->id;
            $booked = $seats[$departureId]['booked'] ?? 0;
            $attended = $seats[$departureId]['attended'] ?? 0;
            $noShow = $seats[$departureId]['no_show'] ?? 0;
            $capacity = $this->capacity($departure);
            $service = $departure->service;
            $location = $departure->location;

            $rows[] = [
                'departure_id' => $departureId,
                'service_id' => (string) $departure->service_id,
                'service' => $service instanceof Service ? $service->name : 'Tour',
                'location_id' => (string) $departure->location_id,
                'location' => $location instanceof Location ? $location->name : 'Location',
                'timezone' => $zone,
                'starts_at' => $local->format('Y-m-d\TH:i:s'),
                'when' => $this->when($departure, $zone),
                'capacity' => $capacity,
                'seats_booked' => $booked,
                'seats_attended' => $attended,
                'no_show_seats' => $noShow,
                'fill_rate_bps' => $this->rate($booked, $capacity),
                'attendance_rate_bps' => $this->rate($attended, $booked),
            ];
        }

        usort($rows, function (array $left, array $right): int {
            return [$left['starts_at'], $left['service'], $left['location_id'], $left['departure_id']]
                <=> [$right['starts_at'], $right['service'], $right['location_id'], $right['departure_id']];
        });

        return $rows;
    }

    public function toCsv(string $from, string $to): string
    {
        $rows = [];

        foreach ($this->build($from, $to) as $row) {
            $rows[] = [
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
                $row['when'],
            ];
        }

        return $this->csv->render([
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
            'when',
        ], $rows);
    }

    /**
     * @param  list<mixed>  $departureIds
     * @return array<string, array{booked: int, attended: int, no_show: int}>
     */
    private function seats(array $departureIds): array
    {
        $ids = [];

        foreach ($departureIds as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $bookings = Booking::query()
            ->whereIn('departure_id', $ids)
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::NoShow->value])
            ->get(['departure_id', 'status', 'participants']);

        $seats = [];

        foreach ($bookings as $booking) {
            $departureId = $booking->departure_id;

            if (! is_string($departureId) || $departureId === '') {
                continue;
            }

            if (! isset($seats[$departureId])) {
                $seats[$departureId] = ['booked' => 0, 'attended' => 0, 'no_show' => 0];
            }

            $participants = max(0, (int) $booking->participants);
            $seats[$departureId]['booked'] += $participants;

            if ($booking->status === BookingStatus::NoShow) {
                $seats[$departureId]['no_show'] += $participants;
            } else {
                $seats[$departureId]['attended'] += $participants;
            }
        }

        return $seats;
    }

    private function capacity(Departure $departure): int
    {
        if ($departure->seats !== null) {
            return max(0, $departure->seats);
        }

        $service = $departure->service;
        $maximum = $service instanceof Service ? $service->max_participants : null;

        return $maximum === null ? 0 : max(0, $maximum);
    }

    private function rate(int $numerator, int $denominator): ?int
    {
        if ($denominator <= 0) {
            return null;
        }

        return Rounding::halfUp($numerator * 10_000, $denominator);
    }

    private function zone(mixed $location): string
    {
        if ($location instanceof Location) {
            return DisplayZone::forLocation($location);
        }

        return DisplayZone::forTenant(null);
    }

    private function when(Departure $departure, string $zone): string
    {
        return Display::rangeWithZone($departure->starts_at, $departure->ends_at, $zone);
    }

    private function localStart(mixed $instant, string $zone): ?CarbonImmutable
    {
        if (! $instant instanceof DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::createFromInterface($instant)->timezone($zone);
    }
}
