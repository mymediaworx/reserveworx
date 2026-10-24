<?php

namespace App\Services\Reports;

use App\Domain\Fees\Rounding;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Location;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ReportSummary;
use App\Models\Tenant;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * Rollup for businesses one owner holds. The caller passes those tenants.
 * Every read drops the current-workspace scope and then filters to that list,
 * so a booking from any other workspace cannot be included.
 *
 * A visit counts on the location-local calendar date of `starts_at`. Bookings
 * are confirmed and no-show visits. Gross is `services_cents`, or `basis_cents`
 * when the service total was not stored, for those same visits. Booking fees
 * and customer refunds also include cancelled visits that start in the range.
 * Net is gross, minus customer refunds, minus booking fees still kept
 * (charged minus refunded). Cancellation and no-show rates use confirmed,
 * cancelled, and no-show as the denominator, in basis points, half-up, and
 * stay empty when that denominator is zero.
 *
 * New and returning follow the customer report: a customer is new when their
 * earliest confirmed or no-show visit, by start then id, is inside the range.
 * Customers are not matched across businesses. Upcoming is confirmed visits
 * in the next seven days and does not use the range. Utilization is allocated
 * minutes over available minutes on stored daily summaries in the range, and
 * stays empty when those days have no available minutes.
 */
final class PortfolioReport
{
    /**
     * @param  Collection<int, Tenant>  $tenants
     * @return array{
     *     businesses: list<array{
     *         tenant_id: string,
     *         name: string,
     *         bookings: int,
     *         confirmed: int,
     *         cancelled: int,
     *         no_shows: int,
     *         cancellation_bps: int|null,
     *         no_show_bps: int|null,
     *         gross_cents: int,
     *         platform_fees_cents: int,
     *         fee_refunded_cents: int,
     *         refunds_cents: int,
     *         net_cents: int,
     *         new_customers: int,
     *         returning_customers: int,
     *         upcoming: int,
     *         utilization_bps: int|null
     *     }>,
     *     totals: array{
     *         bookings: int,
     *         confirmed: int,
     *         cancelled: int,
     *         no_shows: int,
     *         cancellation_bps: int|null,
     *         no_show_bps: int|null,
     *         gross_cents: int,
     *         platform_fees_cents: int,
     *         fee_refunded_cents: int,
     *         refunds_cents: int,
     *         net_cents: int,
     *         new_customers: int,
     *         returning_customers: int,
     *         upcoming: int,
     *         utilization_bps: int|null
     *     },
     *     trend: list<array{date: string, bookings: int, gross_cents: int}>
     * }
     */
    public function build(Collection $tenants, string $from, string $to): array
    {
        /** @var array<string, string> $names */
        $names = [];

        foreach ($tenants as $tenant) {
            $names[(string) $tenant->id] = $tenant->name;
        }

        $tenantIds = array_keys($names);
        $blank = $this->blank();

        /** @var array<string, array<string, int|null>> $rows */
        $rows = [];

        foreach ($names as $tenantId => $name) {
            $rows[$tenantId] = $blank;
        }

        $trend = [];

        foreach ($this->dates($from, $to) as $date) {
            $trend[$date] = ['date' => $date, 'bookings' => 0, 'gross_cents' => 0];
        }

        if ($tenantIds === []) {
            return [
                'businesses' => [],
                'totals' => $this->finish($blank),
                'trend' => array_values($trend),
            ];
        }

        $padStart = CarbonImmutable::parse($from.' 00:00:00', 'UTC')->subDays(2);
        $padEnd = CarbonImmutable::parse($to.' 00:00:00', 'UTC')->addDays(2);

        $bookings = Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with(['location' => function ($query): void {
                $query->withoutGlobalScope(TenantScope::class);
            }])
            ->whereIn('tenant_id', $tenantIds)
            ->where('starts_at', '>=', $padStart)
            ->where('starts_at', '<', $padEnd)
            ->whereIn('status', [
                BookingStatus::Confirmed->value,
                BookingStatus::Cancelled->value,
                BookingStatus::NoShow->value,
            ])
            ->get()
            ->filter(function (Booking $booking) use ($from, $to, $tenantIds): bool {
                if (! in_array((string) $booking->tenant_id, $tenantIds, true)) {
                    return false;
                }

                $date = $this->localDate($booking->starts_at, $booking->location);

                return $date !== null && $date >= $from && $date <= $to;
            })
            ->values();

        $bookingIds = $bookings->map(fn (Booking $booking): string => (string) $booking->id)->all();
        $gross = $this->grossByBooking($bookingIds, $tenantIds);
        $fees = $this->feesByBooking($bookingIds, $tenantIds);
        $refunds = $this->refundsByBooking($bookingIds, $tenantIds);
        $customers = $this->customers($bookings, $tenantIds);

        foreach ($bookings as $booking) {
            $tenantId = (string) $booking->tenant_id;

            if (! isset($rows[$tenantId])) {
                continue;
            }

            $bookingId = (string) $booking->id;
            $fee = $fees[$bookingId] ?? ['amount' => 0, 'refunded' => 0];
            $rows[$tenantId]['platform_fees_cents'] += $fee['amount'];
            $rows[$tenantId]['fee_refunded_cents'] += $fee['refunded'];
            $rows[$tenantId]['refunds_cents'] += $refunds[$bookingId] ?? 0;

            if ($booking->status === BookingStatus::Cancelled) {
                $rows[$tenantId]['cancelled']++;

                continue;
            }

            if ($booking->status === BookingStatus::NoShow) {
                $rows[$tenantId]['no_shows']++;
            } else {
                $rows[$tenantId]['confirmed']++;
            }

            $rows[$tenantId]['bookings']++;
            $cents = $gross[$bookingId] ?? 0;
            $rows[$tenantId]['gross_cents'] += $cents;
            $date = $this->localDate($booking->starts_at, $booking->location);

            if ($date !== null && isset($trend[$date])) {
                $trend[$date]['bookings']++;
                $trend[$date]['gross_cents'] += $cents;
            }
        }

        foreach ($customers as $tenantId => $counts) {
            if (! isset($rows[$tenantId])) {
                continue;
            }

            $rows[$tenantId]['new_customers'] = $counts['new'];
            $rows[$tenantId]['returning_customers'] = $counts['returning'];
        }

        $this->upcoming($rows, $tenantIds);
        $this->utilization($rows, $tenantIds, $from, $to);

        $businesses = [];

        foreach ($names as $tenantId => $name) {
            $finished = $this->finish($rows[$tenantId]);
            $finished['tenant_id'] = $tenantId;
            $finished['name'] = $name;
            $businesses[] = $finished;
        }

        $totals = $blank;

        foreach ($rows as $row) {
            foreach (['bookings', 'confirmed', 'cancelled', 'no_shows', 'gross_cents', 'platform_fees_cents', 'fee_refunded_cents', 'refunds_cents', 'new_customers', 'returning_customers', 'upcoming', 'available_minutes', 'allocated_minutes'] as $key) {
                $totals[$key] += $row[$key];
            }
        }

        return [
            'businesses' => $businesses,
            'totals' => $this->finish($totals),
            'trend' => array_values($trend),
        ];
    }

    public function toCsv(Collection $tenants, string $from, string $to): string
    {
        $report = $this->build($tenants, $from, $to);
        $rows = [];

        foreach ($report['businesses'] as $business) {
            $rows[] = $this->csvBusiness('business', $business, null);
        }

        if (count($report['businesses']) > 1) {
            $rows[] = $this->csvBusiness('total', [
                'tenant_id' => '',
                'name' => 'All businesses',
                ...$report['totals'],
            ], null);
        }

        foreach ($report['trend'] as $point) {
            $rows[] = [
                'day',
                '',
                '',
                $point['date'],
                $point['bookings'],
                null,
                null,
                null,
                null,
                $point['gross_cents'],
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
            ];
        }

        return (new CsvTable)->render([
            'record',
            'business_id',
            'business',
            'date',
            'bookings',
            'cancelled',
            'no_shows',
            'cancellation_bps',
            'no_show_bps',
            'gross_cents',
            'platform_fees_cents',
            'fee_refunded_cents',
            'refunds_cents',
            'net_cents',
            'new_customers',
            'returning_customers',
            'upcoming_bookings',
            'utilization_bps',
        ], $rows);
    }

    /**
     * @return array<string, int|null>
     */
    private function blank(): array
    {
        return [
            'bookings' => 0,
            'confirmed' => 0,
            'cancelled' => 0,
            'no_shows' => 0,
            'gross_cents' => 0,
            'platform_fees_cents' => 0,
            'fee_refunded_cents' => 0,
            'refunds_cents' => 0,
            'new_customers' => 0,
            'returning_customers' => 0,
            'upcoming' => 0,
            'available_minutes' => 0,
            'allocated_minutes' => 0,
        ];
    }

    /**
     * @param  array<string, int|null>  $row
     * @return array{
     *     bookings: int,
     *     confirmed: int,
     *     cancelled: int,
     *     no_shows: int,
     *     cancellation_bps: int|null,
     *     no_show_bps: int|null,
     *     gross_cents: int,
     *     platform_fees_cents: int,
     *     fee_refunded_cents: int,
     *     refunds_cents: int,
     *     net_cents: int,
     *     new_customers: int,
     *     returning_customers: int,
     *     upcoming: int,
     *     utilization_bps: int|null
     * }
     */
    private function finish(array $row): array
    {
        $confirmed = (int) $row['confirmed'];
        $cancelled = (int) $row['cancelled'];
        $noShows = (int) $row['no_shows'];
        $denominator = $confirmed + $cancelled + $noShows;
        $gross = (int) $row['gross_cents'];
        $fees = (int) $row['platform_fees_cents'];
        $feeRefunded = (int) $row['fee_refunded_cents'];
        $refunds = (int) $row['refunds_cents'];
        $available = (int) $row['available_minutes'];
        $allocated = (int) $row['allocated_minutes'];

        return [
            'bookings' => (int) $row['bookings'],
            'confirmed' => $confirmed,
            'cancelled' => $cancelled,
            'no_shows' => $noShows,
            'cancellation_bps' => $this->rate($cancelled, $denominator),
            'no_show_bps' => $this->rate($noShows, $denominator),
            'gross_cents' => $gross,
            'platform_fees_cents' => $fees,
            'fee_refunded_cents' => $feeRefunded,
            'refunds_cents' => $refunds,
            'net_cents' => $gross - $refunds - ($fees - $feeRefunded),
            'new_customers' => (int) $row['new_customers'],
            'returning_customers' => (int) $row['returning_customers'],
            'upcoming' => (int) $row['upcoming'],
            'utilization_bps' => $available > 0 ? Rounding::halfUp($allocated * 10_000, $available) : null,
        ];
    }

    private function rate(int $part, int $whole): ?int
    {
        if ($whole === 0) {
            return null;
        }

        return Rounding::halfUp($part * 10_000, $whole);
    }

    /**
     * @return list<string>
     */
    private function dates(string $from, string $to): array
    {
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');

        if (! $cursor instanceof CarbonImmutable || ! $end instanceof CarbonImmutable) {
            return [];
        }

        $dates = [];

        while ($cursor->toDateString() <= $end->toDateString()) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @param  list<string>  $bookingIds
     * @param  list<string>  $tenantIds
     * @return array<string, int>
     */
    private function grossByBooking(array $bookingIds, array $tenantIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $totals = [];

        $snapshots = PricingSnapshot::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('booking_id', $bookingIds)
            ->get();

        foreach ($snapshots as $snapshot) {
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

    /**
     * @param  list<string>  $bookingIds
     * @param  list<string>  $tenantIds
     * @return array<string, array{amount: int, refunded: int}>
     */
    private function feesByBooking(array $bookingIds, array $tenantIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $totals = [];

        $fees = PlatformFee::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('booking_id', $bookingIds)
            ->get();

        foreach ($fees as $fee) {
            $bookingId = (string) $fee->booking_id;

            if (! isset($totals[$bookingId])) {
                $totals[$bookingId] = ['amount' => 0, 'refunded' => 0];
            }

            $totals[$bookingId]['amount'] += (int) $fee->amount_cents;
            $totals[$bookingId]['refunded'] += (int) $fee->refunded_cents;
        }

        return $totals;
    }

    /**
     * @param  list<string>  $bookingIds
     * @param  list<string>  $tenantIds
     * @return array<string, int>
     */
    private function refundsByBooking(array $bookingIds, array $tenantIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $totals = [];

        $refunds = Refund::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('booking_id', $bookingIds)
            ->get();

        foreach ($refunds as $refund) {
            $bookingId = (string) $refund->booking_id;
            $totals[$bookingId] = ($totals[$bookingId] ?? 0) + (int) $refund->customer_refund_cents;
        }

        return $totals;
    }

    /**
     * @param  Collection<int, Booking>  $included
     * @param  list<string>  $tenantIds
     * @return array<string, array{new: int, returning: int}>
     */
    private function customers(Collection $included, array $tenantIds): array
    {
        $counted = $included->filter(
            fn (Booking $booking): bool => $booking->status === BookingStatus::Confirmed || $booking->status === BookingStatus::NoShow,
        );

        $customerIds = $counted
            ->pluck('customer_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        $first = [];

        if ($customerIds !== []) {
            $history = Booking::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereIn('tenant_id', $tenantIds)
                ->whereIn('customer_id', $customerIds)
                ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::NoShow->value])
                ->get(['id', 'tenant_id', 'customer_id', 'starts_at']);

            foreach ($history as $booking) {
                $customerId = $booking->customer_id;

                if (! is_string($customerId) || $customerId === '') {
                    continue;
                }

                $key = (string) $booking->tenant_id.'|'.$customerId;
                $current = $first[$key] ?? null;

                if (! $current instanceof Booking || $this->before($booking, $current)) {
                    $first[$key] = $booking;
                }
            }
        }

        /** @var array<string, array{new: array<string, true>, later: array<string, true>}> $groups */
        $groups = [];

        foreach ($counted as $booking) {
            $customerId = $booking->customer_id;

            if (! is_string($customerId) || $customerId === '') {
                continue;
            }

            $tenantId = (string) $booking->tenant_id;

            if (! isset($groups[$tenantId])) {
                $groups[$tenantId] = ['new' => [], 'later' => []];
            }

            $key = $tenantId.'|'.$customerId;
            $earliest = $first[$key] ?? null;

            if ($earliest instanceof Booking && (string) $earliest->id === (string) $booking->id) {
                $groups[$tenantId]['new'][$customerId] = true;
            } else {
                $groups[$tenantId]['later'][$customerId] = true;
            }
        }

        $counts = [];

        foreach ($groups as $tenantId => $group) {
            $counts[$tenantId] = [
                'new' => count($group['new']),
                'returning' => count(array_diff_key($group['later'], $group['new'])),
            ];
        }

        return $counts;
    }

    private function before(Booking $candidate, Booking $current): bool
    {
        $left = $this->instant($candidate->starts_at);
        $right = $this->instant($current->starts_at);

        if ($left === null || $right === null) {
            return false;
        }

        if ($left->getTimestamp() !== $right->getTimestamp()) {
            return $left->getTimestamp() < $right->getTimestamp();
        }

        return (string) $candidate->id < (string) $current->id;
    }

    /**
     * @param  array<string, array<string, int|null>>  $rows
     * @param  list<string>  $tenantIds
     */
    private function upcoming(array &$rows, array $tenantIds): void
    {
        $start = CarbonImmutable::now('UTC');
        $end = $start->addDays(7);

        $bookings = Booking::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', BookingStatus::Confirmed->value)
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->get(['id', 'tenant_id']);

        foreach ($bookings as $booking) {
            $tenantId = (string) $booking->tenant_id;

            if (isset($rows[$tenantId])) {
                $rows[$tenantId]['upcoming']++;
            }
        }
    }

    /**
     * @param  array<string, array<string, int|null>>  $rows
     * @param  list<string>  $tenantIds
     */
    private function utilization(array &$rows, array $tenantIds, string $from, string $to): void
    {
        $summaries = ReportSummary::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereIn('tenant_id', $tenantIds)
            ->whereDate('summary_date', '>=', $from)
            ->whereDate('summary_date', '<=', $to)
            ->get();

        foreach ($summaries as $summary) {
            $tenantId = (string) $summary->tenant_id;

            if (! isset($rows[$tenantId])) {
                continue;
            }

            $rows[$tenantId]['available_minutes'] += (int) $summary->available_resource_minutes;
            $rows[$tenantId]['allocated_minutes'] += (int) $summary->allocated_resource_minutes;
        }
    }

    /**
     * @param  array<string, int|string|null>  $business
     * @return list<int|string|null>
     */
    private function csvBusiness(string $record, array $business, ?string $date): array
    {
        return [
            $record,
            $business['tenant_id'] ?? '',
            $business['name'] ?? '',
            $date,
            $business['bookings'] ?? 0,
            $business['cancelled'] ?? 0,
            $business['no_shows'] ?? 0,
            $business['cancellation_bps'] ?? null,
            $business['no_show_bps'] ?? null,
            $business['gross_cents'] ?? 0,
            $business['platform_fees_cents'] ?? 0,
            $business['fee_refunded_cents'] ?? 0,
            $business['refunds_cents'] ?? 0,
            $business['net_cents'] ?? 0,
            $business['new_customers'] ?? 0,
            $business['returning_customers'] ?? 0,
            $business['upcoming'] ?? 0,
            $business['utilization_bps'] ?? null,
        ];
    }

    private function localDate(mixed $instant, mixed $location): ?string
    {
        $parsed = $this->instant($instant);

        if ($parsed === null) {
            return null;
        }

        $timezone = $location instanceof Location ? $location->timezone : 'UTC';
        $zone = in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';

        return $parsed->timezone($zone)->format('Y-m-d');
    }

    private function instant(mixed $instant): ?CarbonImmutable
    {
        if (! $instant instanceof DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::createFromInterface($instant);
    }
}
