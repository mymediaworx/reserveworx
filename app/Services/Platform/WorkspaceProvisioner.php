<?php

namespace App\Services\Platform;

use App\Enums\Industry;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Opens a workspace the same way the platform does: onboarding status and a
 * settings row that keeps the database fee defaults. Callers cannot pass fee
 * rates. Branding and notification rows are created by the tenant model event.
 */
final class WorkspaceProvisioner
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $name, string $slug, ?User $actor, ?Request $request, Industry $industry = Industry::BoatRentals): Tenant
    {
        return DB::transaction(function () use ($name, $slug, $actor, $request, $industry): Tenant {
            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $slug,
                'status' => TenantStatus::Onboarding,
                'industry' => $industry,
            ]);

            TenantSetting::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $tenant->id,
            ]);

            $this->audit->log(
                action: 'tenant.created',
                subject: $tenant,
                properties: ['name' => $tenant->name, 'slug' => $tenant->slug, 'industry' => $industry->value],
                tenantId: $tenant->id,
                actor: $actor,
                request: $request,
                useContextTenant: false,
            );

            return $tenant;
        });
    }

    public function addOwner(Tenant $tenant, User $user, ?User $actor, ?Request $request): Membership
    {
        $role = Role::query()->where('slug', 'owner')->firstOrFail();

        $membership = Membership::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => MembershipStatus::Active,
        ]);

        $this->audit->log(
            action: 'member.created',
            subject: $membership,
            properties: ['role' => 'owner', 'by' => 'owner'],
            tenantId: $tenant->id,
            actor: $actor,
            request: $request,
            useContextTenant: false,
        );

        return $membership;
    }
}
