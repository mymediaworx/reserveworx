<?php

namespace App\Services\Reports;

use App\Models\ReportSummary;

/**
 * Reads stored daily summary rows. It does not run reports:build-summaries.
 */
final class SummaryReport
{
    public function __construct(private readonly CsvTable $csv) {}

    /**
     * @return list<array{
     *     location_id: string,
     *     date: string,
     *     timezone: string|null,
     *     available_resource_minutes: int,
     *     allocated_resource_minutes: int,
     *     utilization_bps: int|null,
     *     bookings_created: int,
     *     bookings_converted: int,
     *     conversion_bps: int|null,
     *     average_order_cents: int|null,
     *     gross_cents: int,
     *     revenue_per_resource_hour_cents: int|null,
     *     updated_at: string|null
     * }>
     */
    public function build(string $from, string $to): array
    {
        $rows = ReportSummary::query()
            ->with('location')
            ->whereDate('summary_date', '>=', $from)
            ->whereDate('summary_date', '<=', $to)
            ->orderBy('summary_date')
            ->orderBy('location_id')
            ->get();

        return $rows->map(function (ReportSummary $row): array {
            $updated = $row->updated_at;

            return [
                'location_id' => $row->location_id,
                'date' => $row->summary_date->format('Y-m-d'),
                'timezone' => $row->location !== null ? $row->location->timezone : null,
                'available_resource_minutes' => $row->available_resource_minutes,
                'allocated_resource_minutes' => $row->allocated_resource_minutes,
                'utilization_bps' => $row->utilization_bps,
                'bookings_created' => $row->bookings_created,
                'bookings_converted' => $row->bookings_converted,
                'conversion_bps' => $row->conversion_bps,
                'average_order_cents' => $row->average_order_cents,
                'gross_cents' => $row->gross_cents,
                'revenue_per_resource_hour_cents' => $row->revenue_per_resource_hour_cents,
                'updated_at' => $updated !== null ? $updated->utc()->toIso8601String() : null,
            ];
        })->all();
    }

    public function toCsv(string $from, string $to): string
    {
        $rows = [];

        foreach ($this->build($from, $to) as $row) {
            $rows[] = [
                $row['location_id'],
                $row['date'],
                $row['timezone'],
                $row['available_resource_minutes'],
                $row['allocated_resource_minutes'],
                $row['utilization_bps'],
                $row['bookings_created'],
                $row['bookings_converted'],
                $row['conversion_bps'],
                $row['average_order_cents'],
                $row['gross_cents'],
                $row['revenue_per_resource_hour_cents'],
                $row['updated_at'],
            ];
        }

        return $this->csv->render([
            'location_id',
            'date',
            'timezone',
            'available_resource_minutes',
            'allocated_resource_minutes',
            'utilization_bps',
            'bookings_created',
            'bookings_converted',
            'conversion_bps',
            'average_order_cents',
            'gross_cents',
            'revenue_per_resource_hour_cents',
            'updated_at',
        ], $rows);
    }
}
