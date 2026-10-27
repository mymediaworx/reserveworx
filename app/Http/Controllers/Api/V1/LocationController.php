<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LocationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLocationRequest;
use App\Http\Requests\Api\V1\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Support\DisplayZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Location::class);

        return LocationResource::collection(Location::query()->orderBy('name')->get());
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $posted = $request->validated('timezone');
        $location = Location::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $request->string('name')->toString(),
            'timezone' => is_string($posted) && $posted !== '' ? $posted : DisplayZone::forTenant($tenant),
            'status' => $request->enum('status', LocationStatus::class) ?? LocationStatus::Active,
        ]);

        $this->audit->log('location.created', $location, [
            'name' => $location->name,
            'timezone' => $location->timezone,
        ], request: $request);

        return (new LocationResource($location))->response()->setStatusCode(201);
    }

    public function show(Request $request, Location $location): LocationResource
    {
        $this->authorize('view', $location);

        return new LocationResource($location);
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $location->fill($request->safe()->only(['name', 'timezone', 'status']));
        $location->save();

        $this->audit->log('location.updated', $location, $request->safe()->only(['name', 'timezone', 'status']), request: $request);

        return new LocationResource($location);
    }
}
