<?php

namespace App\Policies;

use App\Models\Blackout;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class BlackoutPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }

    public function delete(User $user, Blackout $blackout): bool
    {
        return $this->sameTenant($blackout->tenant_id)
            && $this->tenantAllows($user, 'inventory.manage')
            && $this->locationAllowed($user, $blackout->location_id);
    }
}
