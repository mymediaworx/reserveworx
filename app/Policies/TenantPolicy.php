<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;

class TenantPolicy
{
    public function __construct(
        private readonly Access $access,
        private readonly TenantManager $tenants,
    ) {}

    public function view(User $user, Tenant $tenant): bool
    {
        return $this->tenants->id() === $tenant->id
            && $this->access->hasActiveMembership($user, $tenant->id);
    }
}
