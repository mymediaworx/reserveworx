<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDomainRequest;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DomainController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(StoreDomainRequest $request, Tenant $tenant): JsonResponse
    {
        $actor = $request->user() instanceof User ? $request->user() : null;
        $hostname = $request->hostname();

        $domain = DB::transaction(function () use ($request, $tenant, $hostname, $actor): Domain {
            if ($request->boolean('is_primary')) {
                Domain::withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $tenant->id)
                    ->update(['is_primary' => false]);
            }

            $domain = Domain::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $tenant->id,
                'hostname' => $hostname,
                'type' => $request->string('type')->toString(),
                'is_primary' => $request->boolean('is_primary'),
                'verified_at' => null,
            ]);

            $this->audit->log(
                action: 'domain.created',
                subject: $domain,
                properties: ['hostname' => $hostname, 'type' => $domain->type],
                tenantId: $tenant->id,
                actor: $actor,
                request: $request,
                useContextTenant: false,
            );

            return $domain;
        });

        return (new DomainResource($domain))->response()->setStatusCode(201);
    }

    public function verify(Request $request, Tenant $tenant, string $domain): DomainResource
    {
        $domain = Domain::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereKey($domain)
            ->firstOrFail();

        $actor = $request->user() instanceof User ? $request->user() : null;

        if ($domain->verified_at === null) {
            $domain->verified_at = now();
            $domain->save();

            $this->audit->log(
                action: 'domain.verified',
                subject: $domain,
                properties: [
                    'hostname' => $domain->hostname,
                    'method' => 'platform_attestation',
                ],
                tenantId: $tenant->id,
                actor: $actor,
                request: $request,
                useContextTenant: false,
            );
        }

        return new DomainResource($domain);
    }
}
