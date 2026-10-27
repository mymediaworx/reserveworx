<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;
use App\Tenancy\StaffLocationAccess;

class LocationPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, Location $location): bool
    {
        return $this->sameTenant($location->tenant_id)
            && $this->tenantAllows($user, 'inventory.view')
            && $this->locationAllowed($user, $location->id);
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage')
            && ! app(StaffLocationAccess::class)->isRestricted($user);
    }

    public function update(User $user, Location $location): bool
    {
        return $this->sameTenant($location->tenant_id)
            && $this->tenantAllows($user, 'inventory.manage')
            && $this->locationAllowed($user, $location->id);
    }

    public function delete(User $user, Location $location): bool
    {
        return $this->sameTenant($location->tenant_id)
            && $this->tenantAllows($user, 'settings.update')
            && $this->locationAllowed($user, $location->id);
    }
}
