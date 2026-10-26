<?php

namespace App\Services\Auth;

use App\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hostname;
use App\Support\Hosts;
use App\Tenancy\Access;
use App\Tenancy\TenancyException;
use App\Tenancy\TenantScope;
use Illuminate\Http\Request;

/**
 * Staff API sign-in follows the address. A platform host has no workspace.
 * A verified workspace address is that workspace only.
 */
final class StaffApiHost
{
    public const TENANT_LOGIN_FAILED = 'That email and password do not match a team member of this workspace.';

    public function __construct(private readonly Access $access) {}

    public function tenant(Request $request): ?Tenant
    {
        if (Hosts::isPlatform($request)) {
            $this->rejectClientTenantId($request, null);

            return null;
        }

        $domain = Domain::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('hostname', Hostname::normalize($request->getHost()))
            ->whereNotNull('verified_at')
            ->first();

        if (! $domain instanceof Domain) {
            throw TenancyException::notFound();
        }

        $tenant = $domain->tenant;

        if (! $tenant instanceof Tenant) {
            throw TenancyException::notFound();
        }

        if (in_array($tenant->status, [TenantStatus::Suspended, TenantStatus::Terminated], true)) {
            throw TenancyException::unavailable();
        }

        $this->rejectClientTenantId($request, $tenant->id);

        return $tenant;
    }

    public function membership(User $user, Tenant $tenant): ?Membership
    {
        return $this->access->membership($user, $tenant->id);
    }

    private function rejectClientTenantId(Request $request, ?string $tenantId): void
    {
        $supplied = [];

        if ($request->headers->has('X-Tenant-Id')) {
            $supplied[] = $request->headers->get('X-Tenant-Id');
        }

        if ($request->exists('tenant_id')) {
            $supplied[] = $request->input('tenant_id');
        }

        foreach ($supplied as $value) {
            if (! is_string($value) || $tenantId === null || $value !== $tenantId) {
                throw TenancyException::mismatch();
            }
        }
    }
}
