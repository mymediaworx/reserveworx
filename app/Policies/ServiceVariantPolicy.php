<?php

namespace App\Policies;

use App\Models\ServiceVariant;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ServiceVariantPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'settings.view');
    }

    public function view(User $user, ServiceVariant $variant): bool
    {
        return $this->sameTenant($variant->tenant_id) && $this->tenantAllows($user, 'settings.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'settings.update');
    }

    public function update(User $user, ServiceVariant $variant): bool
    {
        return $this->sameTenant($variant->tenant_id) && $this->tenantAllows($user, 'settings.update');
    }

    public function delete(User $user, ServiceVariant $variant): bool
    {
        return $this->sameTenant($variant->tenant_id) && $this->tenantAllows($user, 'settings.update');
    }
}
