<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Location;
use App\Services\Dispatch\DispatchManifest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class StaffManifestController extends Controller
{
    public function export(Request $request, DispatchManifest $manifest): Response
    {
        $this->authorize('viewAny', Booking::class);
        $date = $request->query('date');

        if (! is_string($date) || ! DispatchManifest::validDate($date)) {
            abort(422, 'Date must be YYYY-MM-DD.');
        }

        $locations = Location::query()->orderBy('name')->orderBy('id')->get();
        $locationId = $request->query('location');

        if (is_string($locationId) && $locationId !== '') {
            $selected = $locations->firstWhere('id', $locationId);
            abort_unless($selected instanceof Location, 404);
            $locations = new Collection([$selected]);
        }

        return response($manifest->toCsv($date, $locations), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="manifest-'.$date.'.csv"',
        ]);
    }
}
