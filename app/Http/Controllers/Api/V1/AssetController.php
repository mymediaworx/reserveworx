<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAssetRequest;
use App\Http\Requests\Api\V1\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Operations\AffectedBookings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AffectedBookings $affected,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Asset::class);

        return AssetResource::collection(Asset::query()->orderBy('name')->get());
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $asset = Asset::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $request->string('location_id')->toString(),
            'resource_category_id' => $request->input('resource_category_id'),
            'name' => $request->string('name')->toString(),
            'status' => $request->enum('status', ResourceStatus::class) ?? ResourceStatus::Active,
            'capacity' => $request->integer('capacity', 1),
            'is_guide' => $request->boolean('is_guide'),
            'prep_buffer_minutes' => $request->integer('prep_buffer_minutes'),
            'return_buffer_minutes' => $request->integer('return_buffer_minutes'),
            'turnaround_minutes' => $request->integer('turnaround_minutes'),
        ]);

        $this->audit->log('asset.created', $asset, ['name' => $asset->name], request: $request);

        return (new AssetResource($asset))->response()->setStatusCode(201);
    }

    public function show(Request $request, Asset $asset): AssetResource
    {
        $this->authorize('view', $asset);

        return new AssetResource($asset);
    }

    public function update(UpdateAssetRequest $request, Asset $asset): AssetResource
    {
        $previous = $asset->status;
        $asset->fill($request->safe()->only([
            'location_id',
            'resource_category_id',
            'name',
            'status',
            'capacity',
            'is_guide',
            'prep_buffer_minutes',
            'return_buffer_minutes',
            'turnaround_minutes',
        ]));
        $asset->save();

        if ($asset->status !== $previous && in_array($asset->status, [ResourceStatus::Maintenance, ResourceStatus::Blocked], true)) {
            $this->affected->flag(
                $asset->id,
                null,
                CarbonImmutable::now()->utc(),
                CarbonImmutable::now()->utc()->addYear(),
                'Asset is '.$asset->status->value.'.',
            );
        }

        $this->audit->log('asset.updated', $asset, ['status' => $asset->status->value], request: $request);

        return new AssetResource($asset);
    }
}
