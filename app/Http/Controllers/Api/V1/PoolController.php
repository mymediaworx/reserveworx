<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePoolRequest;
use App\Http\Requests\Api\V1\UpdatePoolRequest;
use App\Http\Resources\PoolResource;
use App\Models\Pool;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PoolController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Pool::class);

        return PoolResource::collection(Pool::query()->orderBy('name')->get());
    }

    public function store(StorePoolRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $pool = Pool::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $request->string('location_id')->toString(),
            'resource_category_id' => $request->input('resource_category_id'),
            'name' => $request->string('name')->toString(),
            'quantity' => $request->integer('quantity'),
            'status' => $request->enum('status', ResourceStatus::class) ?? ResourceStatus::Active,
            'prep_buffer_minutes' => $request->integer('prep_buffer_minutes'),
            'return_buffer_minutes' => $request->integer('return_buffer_minutes'),
        ]);

        $this->audit->log('pool.created', $pool, [
            'name' => $pool->name,
            'quantity' => $pool->quantity,
        ], request: $request);

        return (new PoolResource($pool))->response()->setStatusCode(201);
    }

    public function show(Request $request, Pool $pool): PoolResource
    {
        $this->authorize('view', $pool);

        return new PoolResource($pool);
    }

    public function update(UpdatePoolRequest $request, Pool $pool): PoolResource
    {
        $pool->fill($request->safe()->only([
            'location_id',
            'resource_category_id',
            'name',
            'quantity',
            'status',
            'prep_buffer_minutes',
            'return_buffer_minutes',
        ]));
        $pool->save();

        $this->audit->log('pool.updated', $pool, ['quantity' => $pool->quantity], request: $request);

        return new PoolResource($pool);
    }
}
