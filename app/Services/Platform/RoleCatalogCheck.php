<?php

namespace App\Services\Platform;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RoleAndPermissionSeeder;
use Throwable;

/**
 * Compares stored roles with the seeder catalog.
 * The read does not write a role, a permission, a price, a tax, a fee, or a refund.
 */
final class RoleCatalogCheck
{
    /**
     * @return 'synced'|'out_of_sync'|'unchecked'
     */
    public function status(): string
    {
        try {
            return $this->matches() ? 'synced' : 'out_of_sync';
        } catch (Throwable) {
            return 'unchecked';
        }
    }

    private function matches(): bool
    {
        $catalog = RoleAndPermissionSeeder::catalog();
        $stored = [];

        foreach (Permission::query()->get(['slug', 'name']) as $permission) {
            if (isset($stored[$permission->slug])) {
                return false;
            }

            $stored[$permission->slug] = $permission->name;
        }

        ksort($stored);
        $expectedPermissions = $catalog['permissions'];
        ksort($expectedPermissions);

        if ($stored !== $expectedPermissions) {
            return false;
        }

        $found = [];

        foreach (Role::query()->with('permissions:id,slug')->get() as $role) {
            if (isset($found[$role->slug])) {
                return false;
            }

            $slugs = [];
            foreach ($role->permissions as $permission) {
                $slugs[] = $permission->slug;
            }
            sort($slugs);
            $found[$role->slug] = ['name' => $role->name, 'permissions' => $slugs];
        }

        ksort($found);
        $expectedRoles = [];
        foreach ($catalog['roles'] as $slug => $definition) {
            $permissions = $definition['permissions'];
            sort($permissions);
            $expectedRoles[$slug] = ['name' => $definition['name'], 'permissions' => $permissions];
        }
        ksort($expectedRoles);

        return $found === $expectedRoles;
    }
}
