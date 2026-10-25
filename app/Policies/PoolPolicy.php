<?php

namespace App\Policies;

use App\Models\Pool;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class PoolPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, Pool $pool): bool
    {
        return $this->sameTenant($pool->tenant_id)
            && $this->tenantAllows($user, 'inventory.view')
            && $this->locationAllowed($user, $pool->location_id);
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }

    public function update(User $user, Pool $pool): bool
    {
        return $this->sameTenant($pool->tenant_id)
            && $this->tenantAllows($user, 'inventory.manage')
            && $this->locationAllowed($user, $pool->location_id);
    }

    public function delete(User $user, Pool $pool): bool
    {
        return $this->update($user, $pool);
    }
}
