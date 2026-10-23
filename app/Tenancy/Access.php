<?php

namespace App\Tenancy;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;

class Access
{
    public function hasActiveMembership(User $user, string $tenantId): bool
    {
        return $this->membership($user, $tenantId) !== null;
    }

    public function allows(User $user, string $tenantId, string $permission): bool
    {
        $membership = $this->membership($user, $tenantId);

        if ($membership === null) {
            return false;
        }

        return $membership->role->permissions->contains(
            fn ($item): bool => $item->slug === $permission,
        );
    }

    public function membership(User $user, string $tenantId): ?Membership
    {
        return Membership::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->with('role.permissions')
            ->first();
    }
}
