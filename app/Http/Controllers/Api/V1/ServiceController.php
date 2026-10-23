<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceType;
use App\Enums\ServiceKind;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Pool;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Service::class);

        return response()->json([
            'data' => Service::query()->with('requirements')->orderBy('name')->get()->map(fn (Service $service): array => $this->payload($service)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Service::class);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $validated = $request->validate([
            'location_id' => ['required', 'string', Rule::exists('locations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::enum(ServiceKind::class)],
        ]);

        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $validated['location_id'],
            'name' => $validated['name'],
            'kind' => $validated['kind'],
        ]);

        $this->audit->log('service.created', $service, ['kind' => $service->kind->value], request: $request);

        return response()->json(['data' => $this->payload($service)], 201);
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        $this->authorize('view', $service);
        $service->load('requirements');

        return response()->json(['data' => $this->payload($service)]);
    }

    public function storeRequirement(Request $request, Service $service): JsonResponse
    {
        $this->authorize('update', $service);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $validated = $request->validate([
            'resource_type' => ['required', Rule::enum(ResourceType::class)],
            'resource_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'per_participants' => ['nullable', 'integer', 'min:1'],
        ]);

        $type = ResourceType::from($validated['resource_type']);
        $this->assertResource($service, $type, $validated['resource_id']);

        $requirement = ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => $type,
            'resource_id' => $validated['resource_id'],
            'quantity' => $validated['quantity'],
            'per_participants' => $validated['per_participants'] ?? null,
        ]);

        $this->audit->log('service.requirement_created', $requirement, [
            'service_id' => $service->id,
            'resource_type' => $type->value,
            'quantity' => $requirement->quantity,
        ], request: $request);

        return response()->json(['data' => [
            'id' => $requirement->id,
            'resource_type' => $requirement->resource_type->value,
            'resource_id' => $requirement->resource_id,
            'quantity' => $requirement->quantity,
            'per_participants' => $requirement->per_participants,
        ]], 201);
    }

    private function assertResource(Service $service, ResourceType $type, string $id): void
    {
        $resource = match ($type) {
            ResourceType::Asset => Asset::query()->whereKey($id)->first(),
            ResourceType::Pool => Pool::query()->whereKey($id)->first(),
        };

        if ($resource === null || $resource->location_id !== $service->location_id) {
            throw ValidationException::withMessages([
                'resource_id' => 'That resource is not at this service location.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Service $service): array
    {
        return [
            'id' => $service->id,
            'location_id' => $service->location_id,
            'name' => $service->name,
            'kind' => $service->kind->value,
            'requirements' => $service->relationLoaded('requirements')
                ? $service->requirements->map(fn (ResourceRequirement $requirement): array => [
                    'id' => $requirement->id,
                    'resource_type' => $requirement->resource_type->value,
                    'resource_id' => $requirement->resource_id,
                    'quantity' => $requirement->quantity,
                    'per_participants' => $requirement->per_participants,
                ])->all()
                : [],
        ];
    }
}
