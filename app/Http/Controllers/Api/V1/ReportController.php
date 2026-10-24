<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Reports\CustomerReport;
use App\Services\Reports\ServiceRevenue;
use App\Services\Reports\SummaryReport;
use App\Services\Reports\TodayReport;
use App\Services\Reports\TourAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReportController extends Controller
{
    public function __construct(
        private readonly TodayReport $reports,
        private readonly ServiceRevenue $serviceRevenue,
        private readonly CustomerReport $customerReport,
        private readonly SummaryReport $summaryReport,
        private readonly TourAttendance $tourAttendance,
    ) {}

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
        [$from, $to] = $this->dateWindow($request, 'The summary window cannot be longer than 366 days.');

        return response()->json([
            'data' => $this->summaryReport->build($from, $to),
        ]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);
        [$from, $to] = $this->dateWindow($request, 'The revenue window cannot be longer than 366 days.');

        return response()->json([
            'data' => $this->serviceRevenue->build($from, $to),
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);
        [$from, $to] = $this->dateWindow($request, 'The customer window cannot be longer than 366 days.');

        return response()->json([
            'data' => $this->customerReport->build($from, $to),
        ]);
    }

    public function tours(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);
        [$from, $to] = $this->dateWindow($request, 'The tour window cannot be longer than 366 days.');

        return response()->json([
            'data' => $this->tourAttendance->build($from, $to),
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

        return $this->csv($csv, 'today.csv');
    }

    public function exportSummaries(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);
        [$from, $to] = $this->dateWindow($request, 'The summary window cannot be longer than 366 days.');

        return $this->csv($this->summaryReport->toCsv($from, $to), 'summaries.csv');
    }

    public function exportRevenue(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);
        [$from, $to] = $this->dateWindow($request, 'The revenue window cannot be longer than 366 days.');

        return $this->csv($this->serviceRevenue->toCsv($from, $to), 'revenue.csv');
    }

    public function exportCustomers(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);
        [$from, $to] = $this->dateWindow($request, 'The customer window cannot be longer than 366 days.');

        return $this->csv($this->customerReport->toCsv($from, $to), 'customers.csv');
    }

    public function exportTours(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);
        [$from, $to] = $this->dateWindow($request, 'The tour window cannot be longer than 366 days.');

        return $this->csv($this->tourAttendance->toCsv($from, $to), 'tours.csv');
    }

    private function csv(string $csv, string $filename): Response
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dateWindow(Request $request, string $tooLong): array
    {
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
            throw ValidationException::withMessages(['to' => $tooLong]);
        }

        return [$from, $to];
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
