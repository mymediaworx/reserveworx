<?php

namespace App\Policies;

use App\Models\User;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;

class AuditLogPolicy
{
    public function __construct(
        private readonly Access $access,
        private readonly TenantManager $tenants,
    ) {}

    public function viewAny(User $user): bool
    {
        $tenantId = $this->tenants->id();

        return $tenantId !== null && $this->access->allows($user, $tenantId, 'audit.view');
    }
}
