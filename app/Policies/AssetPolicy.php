<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class AssetPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, Asset $asset): bool
    {
        return $this->sameTenant($asset->tenant_id) && $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->sameTenant($asset->tenant_id) && $this->tenantAllows($user, 'inventory.manage');
    }
}
