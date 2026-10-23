<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Reports\TodayReport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly TodayReport $reports) {}

    public function show(Request $request): View|Response
    {
        $this->authorize('viewAny', Booking::class);

        try {
            $report = $this->reports->build($this->dateQuery($request));
        } catch (InvalidArgumentException $exception) {
            return response()->view('admin.dashboard', [
                'report' => null,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return view('admin.dashboard', [
            'report' => $report,
            'error' => null,
        ]);
    }

    private function dateQuery(Request $request): ?string
    {
        $date = $request->query('date');

        if ($date === null || $date === '') {
            return null;
        }

        if (! is_string($date)) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }

        return $date;
    }
}
