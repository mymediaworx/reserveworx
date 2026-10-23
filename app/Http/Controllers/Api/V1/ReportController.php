<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Reports\TodayReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
