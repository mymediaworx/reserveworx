<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Reports\RangeReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StaffReportController extends Controller
{
    public function export(Request $request, RangeReport $reports): Response
    {
        $this->authorize('viewAny', Booking::class);
        $window = $reports->window(
            $this->query($request, 'from'),
            $this->query($request, 'to'),
            $this->query($request, 'location'),
            $reports->defaultTimezone(),
        );

        return response($reports->toCsv($window['from'], $window['to'], $window['location_id']), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="reports.csv"',
        ]);
    }

    private function query(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }
}
