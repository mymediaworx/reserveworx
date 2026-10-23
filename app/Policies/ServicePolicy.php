<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ServicePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, Service $service): bool
    {
        return $this->sameTenant($service->tenant_id) && $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }

    public function update(User $user, Service $service): bool
    {
        return $this->sameTenant($service->tenant_id) && $this->tenantAllows($user, 'inventory.manage');
    }
}
