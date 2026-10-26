<?php

namespace App\Policies;

use App\Models\FleetItem;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;
use App\Tenancy\StaffLocationAccess;

class FleetItemPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, FleetItem $fleetItem): bool
    {
        return $this->sameTenant($fleetItem->tenant_id)
            && $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, FleetItem $fleetItem): bool
    {
        return $this->sameTenant($fleetItem->tenant_id) && $this->canManage($user);
    }

    public function delete(User $user, FleetItem $fleetItem): bool
    {
        return $this->update($user, $fleetItem);
    }

    /**
     * The count is shared by every location, so a member limited to some
     * locations cannot change it.
     */
    private function canManage(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage')
            && ! app(StaffLocationAccess::class)->isRestricted($user);
    }
}
