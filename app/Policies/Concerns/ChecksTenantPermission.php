<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;

trait ChecksTenantPermission
{
    protected function tenantAllows(User $user, string $permission): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return $tenantId !== null && app(Access::class)->allows($user, $tenantId, $permission);
    }

    protected function sameTenant(?string $tenantId): bool
    {
        $current = app(TenantManager::class)->id();

        return $current !== null && $current === $tenantId;
    }
}
