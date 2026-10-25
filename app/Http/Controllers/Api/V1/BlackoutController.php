<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Blackout;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Tenancy\StaffLocationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BlackoutController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Blackout::class);

        $rows = Blackout::query()->orderBy('starts_on')->get();

        return response()->json(['data' => $rows->map(fn (Blackout $row): array => $this->payload($row))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Blackout::class);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $access = app(StaffLocationAccess::class);
        $validated = $request->validate([
            'location_id' => ['nullable', 'string', $access->locationExistsRule($tenant->id)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $locationId = isset($validated['location_id']) && is_string($validated['location_id']) ? $validated['location_id'] : null;

        if (! $access->allows($locationId)) {
            throw ValidationException::withMessages([
                'location_id' => 'Choose a location you can use.',
            ]);
        }

        $blackout = Blackout::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $validated['location_id'] ?? null,
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'],
            'reason' => $validated['reason'] ?? null,
        ]);

        $this->audit->log('blackout.created', $blackout, [
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'],
        ], request: $request);

        return response()->json(['data' => $this->payload($blackout)], 201);
    }

    public function destroy(Request $request, Blackout $blackout): JsonResponse
    {
        $this->authorize('delete', $blackout);
        $blackout->delete();
        $this->audit->log('blackout.deleted', $blackout, [], request: $request);

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Blackout $blackout): array
    {
        return [
            'id' => $blackout->id,
            'location_id' => $blackout->location_id,
            'starts_on' => $blackout->starts_on->toDateString(),
            'ends_on' => $blackout->ends_on->toDateString(),
            'reason' => $blackout->reason,
        ];
    }
}
