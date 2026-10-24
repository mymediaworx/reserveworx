<?php

namespace App\Policies;

use App\Models\AddOn;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class AddOnPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, AddOn $addOn): bool
    {
        return $this->sameTenant($addOn->tenant_id) && $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }

    public function update(User $user, AddOn $addOn): bool
    {
        return $this->sameTenant($addOn->tenant_id) && $this->tenantAllows($user, 'inventory.manage');
    }

    public function delete(User $user, AddOn $addOn): bool
    {
        return $this->sameTenant($addOn->tenant_id) && $this->tenantAllows($user, 'inventory.manage');
    }
}
