<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreServiceVariantRequest;
use App\Http\Requests\Api\V1\UpdateServiceVariantRequest;
use App\Http\Resources\ServiceVariantResource;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Services\AuditLogger;
use App\Services\Catalog\VariantResourceWriter;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceVariantController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly VariantResourceWriter $resources,
    ) {}

    public function index(Request $request, Service $service): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ServiceVariant::class);

        $variants = $service->variants()->with('resources')->orderBy('name')->get();

        return ServiceVariantResource::collection($variants);
    }

    public function store(StoreServiceVariantRequest $request, Service $service): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $variant = DB::transaction(function () use ($request, $service, $tenant): ServiceVariant {
            try {
                $variant = ServiceVariant::query()->create([
                    'tenant_id' => $tenant->id,
                    'service_id' => $service->id,
                    'name' => $request->string('name')->toString(),
                    'duration_minutes' => $request->integer('duration_minutes'),
                    'price_override_cents' => $request->filled('price_override_cents') ? $request->integer('price_override_cents') : null,
                ]);
            } catch (QueryException $exception) {
                $this->rethrowDuplicateName($exception);
            }

            $this->resources->replace($variant, $service, $this->resourceRows($request->input('resources', [])));
            $variant->load('resources');

            $this->audit->log('service_variant.created', $variant, [
                'service_id' => $service->id,
                'name' => $variant->name,
                'duration_minutes' => $variant->duration_minutes,
                'price_override_cents' => $variant->price_override_cents,
            ], request: $request);

            return $variant;
        });

        return (new ServiceVariantResource($variant))->response()->setStatusCode(201);
    }

    public function show(Request $request, Service $service, ServiceVariant $service_variant): ServiceVariantResource
    {
        $this->owned($service, $service_variant);
        $this->authorize('view', $service_variant);
        $service_variant->load('resources');

        return new ServiceVariantResource($service_variant);
    }

    public function update(UpdateServiceVariantRequest $request, Service $service, ServiceVariant $service_variant): ServiceVariantResource
    {
        $this->owned($service, $service_variant);

        DB::transaction(function () use ($request, $service, $service_variant): void {
            $service_variant->fill($request->safe()->only(['name', 'duration_minutes']));

            if ($request->exists('price_override_cents')) {
                $service_variant->price_override_cents = $request->filled('price_override_cents')
                    ? $request->integer('price_override_cents')
                    : null;
            }

            try {
                $service_variant->save();
            } catch (QueryException $exception) {
                $this->rethrowDuplicateName($exception);
            }

            if ($request->exists('resources')) {
                $this->resources->replace($service_variant, $service, $this->resourceRows($request->input('resources', [])));
            }

            $service_variant->load('resources');
            $this->audit->log('service_variant.updated', $service_variant, [
                'service_id' => $service->id,
                'name' => $service_variant->name,
            ], request: $request);
        });

        return new ServiceVariantResource($service_variant);
    }

    public function destroy(Request $request, Service $service, ServiceVariant $service_variant): JsonResponse
    {
        $this->owned($service, $service_variant);
        $this->authorize('delete', $service_variant);

        if ($this->inUse($service_variant)) {
            return response()->json([
                'message' => 'This variant is on a booking. It stays so that booking can still be priced.',
            ], 409);
        }

        $this->audit->log('service_variant.deleted', $service_variant, [
            'service_id' => $service->id,
            'name' => $service_variant->name,
        ], request: $request);
        $service_variant->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resourceRows(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $rows = [];

        foreach ($input as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = [];

            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function owned(Service $service, ServiceVariant $variant): void
    {
        if ($variant->service_id !== $service->id) {
            abort(404);
        }
    }

    private function inUse(ServiceVariant $variant): bool
    {
        return Booking::query()->where('service_variant_id', $variant->id)->exists()
            || WaitlistEntry::query()->where('service_variant_id', $variant->id)->exists();
    }

    private function rethrowDuplicateName(QueryException $exception): never
    {
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw ValidationException::withMessages([
                'name' => 'This service already has a variant with that name.',
            ]);
        }

        throw $exception;
    }
}
