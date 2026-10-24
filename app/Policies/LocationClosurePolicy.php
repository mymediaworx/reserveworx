<?php

namespace App\Policies;

use App\Models\LocationClosure;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class LocationClosurePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'settings.view');
    }

    public function view(User $user, LocationClosure $closure): bool
    {
        return $this->sameTenant($closure->tenant_id) && $this->tenantAllows($user, 'settings.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'settings.update');
    }

    public function update(User $user, LocationClosure $closure): bool
    {
        return $this->sameTenant($closure->tenant_id) && $this->tenantAllows($user, 'settings.update');
    }

    public function delete(User $user, LocationClosure $closure): bool
    {
        return $this->sameTenant($closure->tenant_id) && $this->tenantAllows($user, 'settings.update');
    }
}
