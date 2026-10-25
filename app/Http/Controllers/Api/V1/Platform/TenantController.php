<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\Industry;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTenantRequest;
use App\Http\Requests\Api\V1\UpdateTenantStatusRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Platform\TenantExporter;
use App\Services\Platform\WorkspaceProvisioner;
use App\Services\TenantLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class TenantController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantLifecycle $lifecycle,
        private readonly TenantExporter $exports,
        private readonly WorkspaceProvisioner $workspaces,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenants = Tenant::query()->orderBy('name')->paginate(25);

        $this->audit->log(
            action: 'platform.tenants_listed',
            actor: $request->user() instanceof User ? $request->user() : null,
            request: $request,
            useContextTenant: false,
        );

        return TenantResource::collection($tenants);
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $actor = $request->user();
        $tenant = $this->workspaces->create(
            $request->string('name')->toString(),
            $request->string('slug')->toString(),
            $actor instanceof User ? $actor : null,
            $request,
            Industry::fromOptional($request->input('industry')),
        );

        return (new TenantResource($tenant))->response()->setStatusCode(201);
    }

    public function show(Request $request, Tenant $tenant): TenantResource
    {
        $this->audit->log(
            action: 'platform.tenant_viewed',
            subject: $tenant,
            tenantId: $tenant->id,
            actor: $request->user() instanceof User ? $request->user() : null,
            request: $request,
            useContextTenant: false,
        );

        return new TenantResource($tenant);
    }

    public function update(UpdateTenantStatusRequest $request, Tenant $tenant): TenantResource|JsonResponse
    {
        $next = TenantStatus::from($request->string('status')->toString());
        $actor = $request->user() instanceof User ? $request->user() : null;

        if ($next === TenantStatus::Terminated && ! $this->exports->isReady($tenant)) {
            $this->audit->log(
                action: 'platform.termination_refused',
                subject: $tenant,
                properties: ['reason' => 'data_export_not_available'],
                tenantId: $tenant->id,
                actor: $actor,
                request: $request,
                useContextTenant: false,
            );

            return response()->json([
                'message' => 'Termination is refused until a tenant data export exists.',
            ], 409);
        }

        if (! $this->lifecycle->canTransition($tenant->status, $next)) {
            throw ValidationException::withMessages([
                'status' => 'That status change is not allowed.',
            ]);
        }

        $before = $tenant->status->value;
        $tenant->status = $next;
        $tenant->save();

        $this->audit->log(
            action: 'tenant.status_changed',
            subject: $tenant,
            properties: ['before' => $before, 'after' => $tenant->status->value],
            tenantId: $tenant->id,
            actor: $actor,
            request: $request,
            useContextTenant: false,
        );

        return new TenantResource($tenant);
    }
}
