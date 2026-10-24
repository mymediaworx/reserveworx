<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Inventory\OpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AvailabilityController extends Controller
{
    public function __construct(
        private readonly OpeningHours $hours,
        private readonly Allocator $allocator,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Service::class);

        $validated = $request->validate([
            'service_id' => ['required', 'string'],
            'variant_id' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $service = Service::query()->with('location')->findOrFail($validated['service_id']);
        $this->authorize('view', $service);
        $variantId = $this->variantId($service, $validated['variant_id'] ?? null);
        $location = $service->location;
        abort_unless($location instanceof Location, 404);

        $start = CarbonImmutable::parse($validated['starts_at'])->utc();
        $end = CarbonImmutable::parse($validated['ends_at'])->utc();
        $assessment = $this->hours->assess($location, $start, $end);
        $tenant = $request->attributes->get('tenant');
        $durationOk = true;
        $offered = [];
        $available = $assessment['open'];
        $capacityChecked = false;

        if ($assessment['open'] && $tenant instanceof Tenant) {
            $hint = $this->allocator->hint(new AllocationIntent(
                $tenant->id,
                $service->id,
                $start,
                $end,
                1,
                null,
                null,
                null,
                variantId: $variantId,
            ));
            $durationOk = $hint['duration_ok'];
            $offered = $hint['offered_durations'];
            $available = $hint['available'];
            $capacityChecked = true;
        }

        return response()->json([
            'data' => [
                'authoritative' => false,
                'open' => $assessment['open'],
                'blackout' => $assessment['blackout'],
                'capacity_checked' => $capacityChecked,
                'duration_ok' => $durationOk,
                'available' => $available,
                'offered_durations' => $offered,
                'variant_id' => $variantId,
            ],
        ]);
    }

    private function variantId(Service $service, mixed $variantId): ?string
    {
        if (! is_string($variantId) || $variantId === '') {
            return null;
        }

        $variant = ServiceVariant::query()->where('service_id', $service->id)->find($variantId);

        if (! $variant instanceof ServiceVariant) {
            throw ValidationException::withMessages([
                'variant_id' => 'That variant is not on this service.',
            ]);
        }

        return $variant->id;
    }
}
