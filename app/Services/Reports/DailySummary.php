<?php

namespace App\Services\Reports;

use App\Domain\Fees\Rounding;
use App\Enums\BookingStatus;
use App\Enums\ResourceStatus;
use App\Enums\ScheduleOwnerType;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AvailabilitySchedule;
use App\Models\Blackout;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Pool;
use App\Models\PricingSnapshot;
use App\Models\ReportSummary;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * One stored row per location per local date. The today report does not call this.
 *
 * Available minutes are the location's open minutes that day times active assets
 * plus active pool quantity. No opening hours means the whole local day, which is
 * 23 or 25 hours on a daylight-saving transition. A blackout, or a weekday the
 * hours do not cover, has none. Allocated minutes are allocation overlap with
 * those open intervals, times quantity. Holds are not use.
 *
 * Conversion is bookings created that local day. Confirmed and no-show are the
 * numerator. Average order value is their customer total, or the service total
 * when the snapshot has no customer total. Revenue per resource-hour is the gross
 * service cents of confirmed and no-show bookings that start that day, divided by
 * available resource-hours. Rates are integer half-up. An empty available day
 * leaves utilization and revenue per hour empty.
 */
final class DailySummary
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function write(Location $location, string $date): ReportSummary
    {
        $this->assertDate($date);
        $tenantId = $this->tenants->id();

        if ($tenantId !== $location->tenant_id) {
            throw new TenantContextException('Refusing to summarize a location outside the current workspace.');
        }

        $zone = $this->zone($location->timezone);
        $dayStart = CarbonImmutable::parse($date.' 00:00:00', $zone);
        $dayEnd = $dayStart->addDay();
        $open = $this->openIntervals($location, $date, $dayStart, $dayEnd);
        $openMinutes = $this->minutes($open);
        $units = $this->activeUnits($location);
        $available = $openMinutes * $units;
        $allocated = $this->allocatedMinutes($location, $dayStart, $dayEnd, $open);
        $created = $this->createdOn($location, $dayStart, $dayEnd);
        $converted = $created->filter(fn (Booking $booking): bool => $this->converted($booking))->values();
        $started = $this->startedOn($location, $dayStart, $dayEnd);
        $orderCents = $this->orderTotal($converted);
        $gross = $this->grossTotal($started);
        $convertedCount = $converted->count();
        $createdCount = $created->count();

        $attributes = [
            'tenant_id' => $location->tenant_id,
            'available_resource_minutes' => $available,
            'allocated_resource_minutes' => $allocated,
            'utilization_bps' => $available > 0 ? Rounding::halfUp($allocated * 10_000, $available) : null,
            'bookings_created' => $createdCount,
            'bookings_converted' => $convertedCount,
            'conversion_bps' => $createdCount > 0 ? Rounding::halfUp($convertedCount * 10_000, $createdCount) : null,
            'average_order_cents' => $convertedCount > 0 ? Rounding::halfUp($orderCents, $convertedCount) : null,
            'gross_cents' => $gross,
            'revenue_per_resource_hour_cents' => $available > 0 ? Rounding::halfUp($gross * 60, $available) : null,
        ];

        $existing = ReportSummary::query()
            ->where('location_id', $location->id)
            ->whereDate('summary_date', $date)
            ->first();

        if ($existing instanceof ReportSummary) {
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        return ReportSummary::query()->create([
            'location_id' => $location->id,
            'summary_date' => $date,
            ...$attributes,
        ]);
    }

    private function assertDate(string $date): void
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
    }

    private function zone(string $timezone): string
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function openIntervals(Location $location, string $date, CarbonImmutable $dayStart, CarbonImmutable $dayEnd): array
    {
        $blackout = Blackout::query()
            ->where(function ($query) use ($location): void {
                $query->whereNull('location_id')->orWhere('location_id', $location->id);
            })
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->exists();

        if ($blackout) {
            return [];
        }

        $rows = AvailabilitySchedule::query()
            ->where('owner_type', ScheduleOwnerType::Location)
            ->where('owner_id', $location->id)
            ->get();

        if ($rows->isEmpty()) {
            return [[$dayStart, $dayEnd]];
        }

        $weekday = $dayStart->dayOfWeek;
        $previous = ($weekday + 6) % 7;
        $intervals = [];

        foreach ($rows as $row) {
            $opens = $this->clock($row->opens_local);
            $closes = $this->clock($row->closes_local);

            if ($opens === $closes) {
                continue;
            }

            if ($closes > $opens && $row->weekday === $weekday) {
                $intervals[] = [
                    CarbonImmutable::parse($date.' '.$opens, $dayStart->timezone),
                    CarbonImmutable::parse($date.' '.$closes, $dayStart->timezone),
                ];
            }

            if ($closes < $opens && $row->weekday === $weekday) {
                $intervals[] = [
                    CarbonImmutable::parse($date.' '.$opens, $dayStart->timezone),
                    $dayEnd,
                ];
            }

            if ($closes < $opens && $row->weekday === $previous) {
                $intervals[] = [
                    $dayStart,
                    CarbonImmutable::parse($date.' '.$closes, $dayStart->timezone),
                ];
            }
        }

        return $this->merge($intervals);
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function merge(array $intervals): array
    {
        usort($intervals, fn (array $left, array $right): int => $left[0]->getTimestamp() <=> $right[0]->getTimestamp());
        $merged = [];

        foreach ($intervals as [$start, $end]) {
            if ($end->getTimestamp() <= $start->getTimestamp()) {
                continue;
            }

            if ($merged === []) {
                $merged[] = [$start, $end];

                continue;
            }

            $last = $merged[array_key_last($merged)];

            if ($start->getTimestamp() <= $last[1]->getTimestamp()) {
                $merged[array_key_last($merged)] = [
                    $last[0],
                    $end->getTimestamp() > $last[1]->getTimestamp() ? $end : $last[1],
                ];

                continue;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     */
    private function minutes(array $intervals): int
    {
        $total = 0;

        foreach ($intervals as [$start, $end]) {
            $total += intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        }

        return $total;
    }

    private function activeUnits(Location $location): int
    {
        $assets = Asset::query()
            ->where('location_id', $location->id)
            ->where('status', ResourceStatus::Active)
            ->count();
        $pools = (int) Pool::query()
            ->where('location_id', $location->id)
            ->where('status', ResourceStatus::Active)
            ->sum('quantity');

        return $assets + $pools;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $open
     */
    private function allocatedMinutes(Location $location, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, array $open): int
    {
        if ($open === []) {
            return 0;
        }

        $assetIds = Asset::query()->where('location_id', $location->id)->pluck('id')->all();
        $poolIds = Pool::query()->where('location_id', $location->id)->pluck('id')->all();
        $rows = Allocation::query()
            ->where('starts_at', '<', $dayEnd->utc())
            ->where('ends_at', '>', $dayStart->utc())
            ->get();
        $total = 0;

        foreach ($rows as $allocation) {
            $here = ($allocation->asset_id !== null && in_array($allocation->asset_id, $assetIds, true))
                || ($allocation->pool_id !== null && in_array($allocation->pool_id, $poolIds, true));

            if (! $here) {
                continue;
            }

            $overlap = $this->overlapMinutes(
                CarbonImmutable::instance($allocation->starts_at)->utc(),
                CarbonImmutable::instance($allocation->ends_at)->utc(),
                $open,
            );
            $total += $overlap * max(0, (int) $allocation->quantity);
        }

        return $total;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $open
     */
    private function overlapMinutes(CarbonImmutable $start, CarbonImmutable $end, array $open): int
    {
        $total = 0;

        foreach ($open as [$from, $until]) {
            $left = max($start->getTimestamp(), $from->getTimestamp());
            $right = min($end->getTimestamp(), $until->getTimestamp());

            if ($right > $left) {
                $total += intdiv($right - $left, 60);
            }
        }

        return $total;
    }

    /**
     * @return Collection<int, Booking>
     */
    private function createdOn(Location $location, CarbonImmutable $dayStart, CarbonImmutable $dayEnd)
    {
        return Booking::query()
            ->where('location_id', $location->id)
            ->where('created_at', '>=', $dayStart->utc())
            ->where('created_at', '<', $dayEnd->utc())
            ->get();
    }

    /**
     * @return Collection<int, Booking>
     */
    private function startedOn(Location $location, CarbonImmutable $dayStart, CarbonImmutable $dayEnd)
    {
        return Booking::query()
            ->where('location_id', $location->id)
            ->where('starts_at', '>=', $dayStart->utc())
            ->where('starts_at', '<', $dayEnd->utc())
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::NoShow->value])
            ->get();
    }

    private function converted(Booking $booking): bool
    {
        return $booking->status === BookingStatus::Confirmed || $booking->status === BookingStatus::NoShow;
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function orderTotal($bookings): int
    {
        return $this->sumSnapshots($bookings, function (array $payload): int {
            if (is_numeric($payload['customer_total_cents'] ?? null)) {
                return (int) $payload['customer_total_cents'];
            }

            if (is_numeric($payload['services_cents'] ?? null)) {
                return (int) $payload['services_cents'];
            }

            return 0;
        });
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function grossTotal($bookings): int
    {
        return $this->sumSnapshots($bookings, function (array $payload): int {
            $services = $payload['services_cents'] ?? $payload['basis_cents'] ?? 0;

            return is_numeric($services) ? (int) $services : 0;
        });
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     */
    private function sumSnapshots($bookings, callable $pick): int
    {
        $ids = $bookings->pluck('id')->all();

        if ($ids === []) {
            return 0;
        }

        $total = 0;

        foreach (PricingSnapshot::query()->whereIn('booking_id', $ids)->get() as $snapshot) {
            $total += $pick($snapshot->payload);
        }

        return $total;
    }

    private function clock(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        $text = (string) $value;

        if (strlen($text) === 5) {
            return $text.':00';
        }

        return substr($text, 0, 8);
    }
}
