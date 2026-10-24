<?php

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Location;
use App\Models\PricingSnapshot;
use App\Models\Service;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * Gross service cents of confirmed and no-show bookings, grouped by the
 * location-local date they start and by service. The cents are the same
 * `services_cents` (or `basis_cents`) the daily summary stores as `gross_cents`.
 * Held, cancelled, and unpaid bookings are left out. Taxes, the booking fee,
 * and the customer total are not included. This read does not write a summary row.
 */
final class ServiceRevenue
{
    public function __construct(private readonly CsvTable $csv) {}

    /**
     * @return list<array{service_id: string, service: string, location_id: string, date: string, bookings: int, gross_cents: int}>
     */
    public function build(string $from, string $to): array
    {
        $fromStart = CarbonImmutable::parse($from.' 00:00:00', 'UTC')->subDay();
        $toEnd = CarbonImmutable::parse($to.' 00:00:00', 'UTC')->addDays(2);

        $bookings = Booking::query()
            ->with(['service', 'location'])
            ->where('starts_at', '>=', $fromStart)
            ->where('starts_at', '<', $toEnd)
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::NoShow->value])
            ->get();

        $included = $bookings->filter(function (Booking $booking) use ($from, $to): bool {
            $date = $this->localDate($booking->starts_at, $this->zone($booking->location));

            return $date !== null && $date >= $from && $date <= $to;
        })->values();

        $gross = $this->grossByBooking($included);

        /** @var array<string, array{service_id: string, service: string, location_id: string, date: string, bookings: int, gross_cents: int}> $groups */
        $groups = [];

        foreach ($included as $booking) {
            $date = $this->localDate($booking->starts_at, $this->zone($booking->location));

            if ($date === null) {
                continue;
            }

            $service = $booking->service;
            $name = $service instanceof Service ? $service->name : 'Service';
            $serviceId = (string) $booking->service_id;
            $locationId = (string) $booking->location_id;
            $key = $date.'|'.$serviceId.'|'.$locationId;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'service_id' => $serviceId,
                    'service' => $name,
                    'location_id' => $locationId,
                    'date' => $date,
                    'bookings' => 0,
                    'gross_cents' => 0,
                ];
            }

            $groups[$key]['bookings']++;
            $groups[$key]['gross_cents'] += $gross[(string) $booking->id] ?? 0;
        }

        /** @var list<array{service_id: string, service: string, location_id: string, date: string, bookings: int, gross_cents: int}> $rows */
        $rows = array_values($groups);
        usort($rows, function (array $left, array $right): int {
            return [$left['date'], $left['service'], $left['location_id'], $left['service_id']]
                <=> [$right['date'], $right['service'], $right['location_id'], $right['service_id']];
        });

        return $rows;
    }

    public function toCsv(string $from, string $to): string
    {
        $rows = [];

        foreach ($this->build($from, $to) as $row) {
            $rows[] = [
                $row['service_id'],
                $row['service'],
                $row['location_id'],
                $row['date'],
                $row['bookings'],
                $row['gross_cents'],
            ];
        }

        return $this->csv->render([
            'service_id',
            'service',
            'location_id',
            'date',
            'bookings',
            'gross_cents',
        ], $rows);
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, int>
     */
    private function grossByBooking(Collection $bookings): array
    {
        $ids = $bookings->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $totals = [];

        foreach (PricingSnapshot::query()->whereIn('booking_id', $ids)->get() as $snapshot) {
            $bookingId = (string) $snapshot->booking_id;
            $totals[$bookingId] = ($totals[$bookingId] ?? 0) + $this->grossCents($snapshot->payload);
        }

        return $totals;
    }

    private function grossCents(mixed $payload): int
    {
        if (! is_array($payload)) {
            return 0;
        }

        $services = $payload['services_cents'] ?? $payload['basis_cents'] ?? 0;

        return is_numeric($services) ? (int) $services : 0;
    }

    private function zone(mixed $location): string
    {
        $timezone = $location instanceof Location ? $location->timezone : 'UTC';

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    private function localDate(mixed $instant, string $zone): ?string
    {
        if (! $instant instanceof DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::createFromInterface($instant)->timezone($zone)->format('Y-m-d');
    }
}
