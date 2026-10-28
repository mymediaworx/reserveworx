<?php

namespace App\Services\Reports;

use App\Domain\Fees\Rounding;
use App\Models\Location;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * One staff report for an inclusive date window. Utilization, conversion,
 * average order value, and revenue per resource-hour are rolled up from
 * stored summary rows. They are not recomputed from bookings. Revenue,
 * payments, rates, customers, and tour departures use the same bounded
 * reads as the API. The tour rows are not written into a summary.
 */
final class RangeReport
{
    public function __construct(
        private readonly SummaryReport $summaries,
        private readonly ServiceRevenue $serviceRevenue,
        private readonly OperationsReport $operations,
        private readonly CustomerReport $customers,
        private readonly TourAttendance $tours,
        private readonly CsvTable $csv,
    ) {}

    public function defaultTimezone(): string
    {
        $zone = Location::query()->orderBy('created_at')->value('timezone');

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }

    /**
     * @return array{from: string, to: string, location_id: string|null}
     */
    public function window(string $from, string $to, string $locationId, string $timezone): array
    {
        $from = trim($from);
        $to = trim($to);
        $locationId = trim($locationId);

        if ($from === '' && $to === '') {
            $zone = in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
            $today = CarbonImmutable::now($zone)->startOfDay();
            $from = $today->subDays(29)->toDateString();
            $to = $today->toDateString();
        } elseif (! $this->isDate($from) || ! $this->isDate($to) || $from > $to) {
            abort(422);
        } else {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');

            if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable) {
                abort(422);
            }

            if (intdiv($end->getTimestamp() - $start->getTimestamp(), 86400) > 365) {
                abort(422);
            }
        }

        $selected = null;

        if ($locationId !== '') {
            $selected = Location::query()->whereKey($locationId)->first();
            abort_unless($selected instanceof Location, 404);
        }

        return [
            'from' => $from,
            'to' => $to,
            'location_id' => $selected instanceof Location ? $selected->id : null,
        ];
    }

    /**
     * @return array{
     *     gross_cents: int,
     *     revenue_by_service: list<array{service: string, location: string, bookings: int, gross_cents: int}>,
     *     revenue_by_date: list<array{date: string, bookings: int, gross_cents: int}>,
     *     card_cents: int,
     *     offline_cents: int,
     *     refunds_cents: int,
     *     tax_cents: int,
     *     fee_cents: int,
     *     fee_refunded_cents: int,
     *     cancellation_bps: int|null,
     *     no_show_bps: int|null,
     *     new_customers: int,
     *     repeat_customers: int,
     *     attribution: list<array{channel: string, bookings: int}>,
     *     summary_ready: bool,
     *     utilization_bps: int|null,
     *     conversion_bps: int|null,
     *     average_order_cents: int|null,
     *     revenue_per_resource_hour_cents: int|null,
     *     tours: list<array{
     *         departure_id: string,
     *         service: string,
     *         location: string,
     *         starts_at: string,
     *         when: string,
     *         capacity: int,
     *         seats_booked: int,
     *         seats_attended: int,
     *         no_show_seats: int,
     *         fill_rate_bps: int|null,
     *         attendance_rate_bps: int|null
     *     }>
     * }
     */
    public function build(string $from, string $to, ?string $locationId): array
    {
        $revenue = $this->revenueRows($from, $to, $locationId);
        $operations = $this->operationRows($from, $to, $locationId);
        $customers = $this->customers->windowTotals($from, $to, $locationId);
        $summary = $this->summary($from, $to, $locationId);

        return [
            'gross_cents' => $revenue['gross_cents'],
            'revenue_by_service' => $revenue['by_service'],
            'revenue_by_date' => $revenue['by_date'],
            'card_cents' => $operations['card_cents'],
            'offline_cents' => $operations['offline_cents'],
            'refunds_cents' => $operations['refunds_cents'],
            'tax_cents' => $operations['tax_cents'],
            'fee_cents' => $operations['fee_cents'],
            'fee_refunded_cents' => $operations['fee_refunded_cents'],
            'cancellation_bps' => $operations['cancellation_bps'],
            'no_show_bps' => $operations['no_show_bps'],
            'new_customers' => $customers['new_customers'],
            'repeat_customers' => $customers['repeat_customers'],
            'attribution' => $customers['attribution'],
            'summary_ready' => $summary['ready'],
            'utilization_bps' => $summary['utilization_bps'],
            'conversion_bps' => $summary['conversion_bps'],
            'average_order_cents' => $summary['average_order_cents'],
            'revenue_per_resource_hour_cents' => $summary['revenue_per_resource_hour_cents'],
            'tours' => $this->tourRows($from, $to, $locationId),
        ];
    }

    public function toCsv(string $from, string $to, ?string $locationId): string
    {
        $report = $this->build($from, $to, $locationId);
        $rows = [$this->totalRow($report)];

        foreach ($report['revenue_by_service'] as $row) {
            $rows[] = $this->blankRow('revenue_by_service', [
                'location' => $row['location'],
                'service' => $row['service'],
                'bookings' => $row['bookings'],
                'gross_cents' => $row['gross_cents'],
            ]);
        }

        foreach ($report['revenue_by_date'] as $row) {
            $rows[] = $this->blankRow('revenue_by_date', [
                'date' => $row['date'],
                'bookings' => $row['bookings'],
                'gross_cents' => $row['gross_cents'],
            ]);
        }

        foreach ($report['attribution'] as $row) {
            $rows[] = $this->blankRow('attribution', [
                'bookings' => $row['bookings'],
                'channel' => $row['channel'],
            ]);
        }

        foreach ($report['tours'] as $row) {
            $rows[] = $this->blankRow('tour', [
                'departure_id' => $row['departure_id'],
                'location' => $row['location'],
                'date' => substr($row['starts_at'], 0, 10),
                'service' => $row['service'],
                'starts_at' => $row['when'],
                'capacity' => $row['capacity'],
                'seats_booked' => $row['seats_booked'],
                'seats_attended' => $row['seats_attended'],
                'no_show_seats' => $row['no_show_seats'],
                'fill_rate_bps' => $row['fill_rate_bps'],
                'attendance_rate_bps' => $row['attendance_rate_bps'],
            ]);
        }

        return $this->csv->render($this->header(), $rows);
    }

    /**
     * @param  array{
     *     gross_cents: int,
     *     card_cents: int,
     *     offline_cents: int,
     *     refunds_cents: int,
     *     tax_cents: int,
     *     fee_cents: int,
     *     fee_refunded_cents: int,
     *     cancellation_bps: int|null,
     *     no_show_bps: int|null,
     *     new_customers: int,
     *     repeat_customers: int,
     *     utilization_bps: int|null,
     *     conversion_bps: int|null,
     *     average_order_cents: int|null,
     *     revenue_per_resource_hour_cents: int|null
     * }  $report
     * @return list<int|string|null>
     */
    private function totalRow(array $report): array
    {
        return $this->blankRow('total', [
            'gross_cents' => $report['gross_cents'],
            'card_cents' => $report['card_cents'],
            'offline_cents' => $report['offline_cents'],
            'refunds_cents' => $report['refunds_cents'],
            'tax_cents' => $report['tax_cents'],
            'fee_charged_cents' => $report['fee_cents'],
            'fee_refunded_cents' => $report['fee_refunded_cents'],
            'cancellation_bps' => $report['cancellation_bps'],
            'no_show_bps' => $report['no_show_bps'],
            'new_customers' => $report['new_customers'],
            'repeat_customers' => $report['repeat_customers'],
            'utilization_bps' => $report['utilization_bps'],
            'conversion_bps' => $report['conversion_bps'],
            'average_order_cents' => $report['average_order_cents'],
            'revenue_per_resource_hour_cents' => $report['revenue_per_resource_hour_cents'],
        ]);
    }

    /**
     * @param  array<string, int|string|null>  $values
     * @return list<int|string|null>
     */
    private function blankRow(string $record, array $values): array
    {
        $row = [];

        foreach ($this->header() as $column) {
            if ($column === 'record') {
                $row[] = $record;

                continue;
            }

            $row[] = $values[$column] ?? null;
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    private function header(): array
    {
        return [
            'record',
            'location',
            'date',
            'service',
            'bookings',
            'gross_cents',
            'card_cents',
            'offline_cents',
            'refunds_cents',
            'tax_cents',
            'fee_charged_cents',
            'fee_refunded_cents',
            'cancellation_bps',
            'no_show_bps',
            'new_customers',
            'repeat_customers',
            'utilization_bps',
            'conversion_bps',
            'average_order_cents',
            'revenue_per_resource_hour_cents',
            'channel',
            'departure_id',
            'starts_at',
            'capacity',
            'seats_booked',
            'seats_attended',
            'no_show_seats',
            'fill_rate_bps',
            'attendance_rate_bps',
        ];
    }

    /**
     * @return list<array{
     *     departure_id: string,
     *     service: string,
     *     location: string,
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
    private function tourRows(string $from, string $to, ?string $locationId): array
    {
        $rows = [];

        foreach ($this->tours->build($from, $to) as $row) {
            if ($locationId !== null && $row['location_id'] !== $locationId) {
                continue;
            }

            $rows[] = [
                'departure_id' => $row['departure_id'],
                'service' => $row['service'],
                'location' => $row['location'],
                'starts_at' => $row['starts_at'],
                'when' => $row['when'],
                'capacity' => $row['capacity'],
                'seats_booked' => $row['seats_booked'],
                'seats_attended' => $row['seats_attended'],
                'no_show_seats' => $row['no_show_seats'],
                'fill_rate_bps' => $row['fill_rate_bps'],
                'attendance_rate_bps' => $row['attendance_rate_bps'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     gross_cents: int,
     *     by_service: list<array{service: string, location: string, bookings: int, gross_cents: int}>,
     *     by_date: list<array{date: string, bookings: int, gross_cents: int}>
     * }
     */
    private function revenueRows(string $from, string $to, ?string $locationId): array
    {
        /** @var array<string, string> $names */
        $names = [];

        foreach (Location::query()->get(['id', 'name']) as $location) {
            $names[$location->id] = $location->name;
        }

        /** @var array<string, array{service: string, location: string, bookings: int, gross_cents: int}> $byService */
        $byService = [];
        /** @var array<string, array{date: string, bookings: int, gross_cents: int}> $byDate */
        $byDate = [];
        $gross = 0;

        foreach ($this->serviceRevenue->build($from, $to) as $row) {
            if ($locationId !== null && $row['location_id'] !== $locationId) {
                continue;
            }

            $gross += $row['gross_cents'];
            $serviceId = $row['service_id'];

            if (! isset($byService[$serviceId])) {
                $byService[$serviceId] = [
                    'service' => $row['service'],
                    'location' => $names[$row['location_id']] ?? '',
                    'bookings' => 0,
                    'gross_cents' => 0,
                ];
            }

            $byService[$serviceId]['bookings'] += $row['bookings'];
            $byService[$serviceId]['gross_cents'] += $row['gross_cents'];

            $date = $row['date'];

            if (! isset($byDate[$date])) {
                $byDate[$date] = [
                    'date' => $date,
                    'bookings' => 0,
                    'gross_cents' => 0,
                ];
            }

            $byDate[$date]['bookings'] += $row['bookings'];
            $byDate[$date]['gross_cents'] += $row['gross_cents'];
        }

        $services = array_values($byService);
        usort($services, fn (array $left, array $right): int => [$left['service'], $left['location']] <=> [$right['service'], $right['location']]);
        $dates = array_values($byDate);
        usort($dates, fn (array $left, array $right): int => $left['date'] <=> $right['date']);

        return [
            'gross_cents' => $gross,
            'by_service' => $services,
            'by_date' => $dates,
        ];
    }

    /**
     * @return array{
     *     card_cents: int,
     *     offline_cents: int,
     *     refunds_cents: int,
     *     tax_cents: int,
     *     fee_cents: int,
     *     fee_refunded_cents: int,
     *     cancellation_bps: int|null,
     *     no_show_bps: int|null
     * }
     */
    private function operationRows(string $from, string $to, ?string $locationId): array
    {
        $card = 0;
        $offline = 0;
        $refunds = 0;
        $tax = 0;
        $fees = 0;
        $feeRefunds = 0;
        $confirmed = 0;
        $cancelled = 0;
        $noShow = 0;

        foreach ($this->operations->build($from, $to) as $row) {
            if ($locationId !== null && $row['location_id'] !== $locationId) {
                continue;
            }

            $card += $row['card_cents'];
            $offline += $row['offline_cents'];
            $refunds += $row['refunds_cents'];
            $tax += $row['tax_cents'];
            $fees += $row['fee_cents'];
            $feeRefunds += $row['fee_refunded_cents'];
            $confirmed += $row['confirmed'];
            $cancelled += $row['cancelled'];
            $noShow += $row['no_show'];
        }

        $denominator = $confirmed + $cancelled + $noShow;

        return [
            'card_cents' => $card,
            'offline_cents' => $offline,
            'refunds_cents' => $refunds,
            'tax_cents' => $tax,
            'fee_cents' => $fees,
            'fee_refunded_cents' => $feeRefunds,
            'cancellation_bps' => $denominator > 0 ? Rounding::halfUp($cancelled * 10_000, $denominator) : null,
            'no_show_bps' => $denominator > 0 ? Rounding::halfUp($noShow * 10_000, $denominator) : null,
        ];
    }

    /**
     * @return array{
     *     ready: bool,
     *     utilization_bps: int|null,
     *     conversion_bps: int|null,
     *     average_order_cents: int|null,
     *     revenue_per_resource_hour_cents: int|null
     * }
     */
    private function summary(string $from, string $to, ?string $locationId): array
    {
        $available = 0;
        $allocated = 0;
        $created = 0;
        $converted = 0;
        $orderCents = 0;
        $orders = 0;
        $gross = 0;
        $ready = false;

        foreach ($this->summaries->build($from, $to) as $row) {
            if ($locationId !== null && $row['location_id'] !== $locationId) {
                continue;
            }

            $ready = true;
            $available += $row['available_resource_minutes'];
            $allocated += $row['allocated_resource_minutes'];
            $created += $row['bookings_created'];
            $converted += $row['bookings_converted'];
            $gross += $row['gross_cents'];

            if ($row['bookings_converted'] > 0 && $row['average_order_cents'] !== null) {
                $orderCents += $row['average_order_cents'] * $row['bookings_converted'];
                $orders += $row['bookings_converted'];
            }
        }

        if (! $ready) {
            return [
                'ready' => false,
                'utilization_bps' => null,
                'conversion_bps' => null,
                'average_order_cents' => null,
                'revenue_per_resource_hour_cents' => null,
            ];
        }

        return [
            'ready' => true,
            'utilization_bps' => $available > 0 ? Rounding::halfUp($allocated * 10_000, $available) : null,
            'conversion_bps' => $created > 0 ? Rounding::halfUp($converted * 10_000, $created) : null,
            'average_order_cents' => $orders > 0 ? Rounding::halfUp($orderCents, $orders) : null,
            'revenue_per_resource_hour_cents' => $available > 0 ? Rounding::halfUp($gross * 60, $available) : null,
        ];
    }

    private function isDate(string $value): bool
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $value;
    }
}
