<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class LocationPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, Location $location): bool
    {
        return $this->sameTenant($location->tenant_id) && $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }

    public function update(User $user, Location $location): bool
    {
        return $this->sameTenant($location->tenant_id) && $this->tenantAllows($user, 'inventory.manage');
    }
}
