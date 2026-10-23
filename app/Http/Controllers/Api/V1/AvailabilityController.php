<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Service;
use App\Services\Inventory\OpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(private readonly OpeningHours $hours) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Service::class);

        $validated = $request->validate([
            'service_id' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $service = Service::query()->with('location')->findOrFail($validated['service_id']);
        $this->authorize('view', $service);
        $location = $service->location;
        abort_unless($location instanceof Location, 404);

        $start = CarbonImmutable::parse($validated['starts_at'])->utc();
        $end = CarbonImmutable::parse($validated['ends_at'])->utc();
        $assessment = $this->hours->assess($location, $start, $end);

        return response()->json([
            'data' => [
                'authoritative' => false,
                'open' => $assessment['open'],
                'blackout' => $assessment['blackout'],
                'capacity_checked' => false,
            ],
        ]);
    }
}
