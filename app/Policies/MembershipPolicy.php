<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;

class MembershipPolicy
{
    public function __construct(
        private readonly Access $access,
        private readonly TenantManager $tenants,
    ) {}

    public function viewAny(User $user): bool
    {
        $tenantId = $this->tenants->id();

        return $tenantId !== null && $this->access->allows($user, $tenantId, 'members.view');
    }

    public function view(User $user, Membership $membership): bool
    {
        return $this->matchesContext($membership)
            && $this->access->allows($user, $membership->tenant_id, 'members.view');
    }

    public function create(User $user): bool
    {
        $tenantId = $this->tenants->id();

        return $tenantId !== null && $this->access->allows($user, $tenantId, 'members.manage');
    }

    public function update(User $user, Membership $membership): bool
    {
        return $this->matchesContext($membership)
            && $this->access->allows($user, $membership->tenant_id, 'members.manage');
    }

    private function matchesContext(Membership $membership): bool
    {
        return $this->tenants->id() !== null && $this->tenants->id() === $membership->tenant_id;
    }
}
