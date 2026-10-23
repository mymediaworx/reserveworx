<?php

namespace App\Policies;

use App\Models\ResourceCategory;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class ResourceCategoryPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, ResourceCategory $category): bool
    {
        return $this->sameTenant($category->tenant_id) && $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }
}
