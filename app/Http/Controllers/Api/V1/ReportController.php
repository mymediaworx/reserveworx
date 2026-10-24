<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ReportSummary;
use App\Services\Reports\TodayReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReportController extends Controller
{
    public function __construct(private readonly TodayReport $reports) {}

    public function today(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        try {
            $report = $this->reports->build($this->dateQuery($request));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $report]);
    }

    public function summaries(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);
        $from = $validated['from'];
        $to = $validated['to'];

        if (! is_string($from) || ! is_string($to)) {
            throw ValidationException::withMessages(['from' => 'Date must be YYYY-MM-DD.']);
        }

        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }

        $start = CarbonImmutable::parse($from.' 00:00:00', 'UTC');
        $end = CarbonImmutable::parse($to.' 00:00:00', 'UTC');

        if (intdiv($end->getTimestamp() - $start->getTimestamp(), 86400) > 365) {
            throw ValidationException::withMessages(['to' => 'The summary window cannot be longer than 366 days.']);
        }

        $rows = ReportSummary::query()
            ->with('location')
            ->whereDate('summary_date', '>=', $from)
            ->whereDate('summary_date', '<=', $to)
            ->orderBy('summary_date')
            ->orderBy('location_id')
            ->get();

        return response()->json([
            'data' => $rows->map(function (ReportSummary $row): array {
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
            })->all(),
        ]);
    }

    public function export(Request $request): Response|JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        try {
            $csv = $this->reports->toCsv($this->dateQuery($request));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="today.csv"',
        ]);
    }

    private function dateQuery(Request $request): ?string
    {
        $date = $request->query('date');

        if ($date === null) {
            return null;
        }

        if (! is_string($date)) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }

        return $date;
    }
}
