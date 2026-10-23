<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreResourceCategoryRequest;
use App\Http\Resources\ResourceCategoryResource;
use App\Models\ResourceCategory;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ResourceCategoryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ResourceCategory::class);

        return ResourceCategoryResource::collection(ResourceCategory::query()->orderBy('name')->get());
    }

    public function store(StoreResourceCategoryRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $category = ResourceCategory::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $request->string('name')->toString(),
            'slug' => $request->string('slug')->toString(),
        ]);

        $this->audit->log('resource_category.created', $category, [
            'slug' => $category->slug,
        ], request: $request);

        return (new ResourceCategoryResource($category))->response()->setStatusCode(201);
    }
}
