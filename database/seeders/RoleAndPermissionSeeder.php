<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * System roles shared by every tenant. Platform administration is not a tenant role.
     */
    public function run(): void
    {
        $permissions = [
            'settings.view' => 'View workspace settings',
            'settings.update' => 'Update workspace settings',
            'members.view' => 'View members',
            'members.manage' => 'Add and revoke members',
            'audit.view' => 'View the audit log',
            'inventory.view' => 'View locations and resources',
            'inventory.manage' => 'Create and update locations and resources',
            'bookings.view' => 'View bookings and the calendar',
            'bookings.manage' => 'Hold, confirm, extend, and cancel bookings',
            'reminders.manage' => 'Read and update booking reminder offsets',
        ];

        $saved = [];

        foreach ($permissions as $slug => $name) {
            $saved[$slug] = Permission::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );
        }

        $roles = [
            'owner' => ['name' => 'Owner', 'permissions' => array_keys($permissions)],
            'manager' => ['name' => 'Manager', 'permissions' => [
                'settings.view',
                'settings.update',
                'members.view',
                'audit.view',
                'inventory.view',
                'inventory.manage',
                'bookings.view',
                'bookings.manage',
                'reminders.manage',
            ]],
            'staff' => ['name' => 'Staff', 'permissions' => [
                'settings.view',
                'members.view',
                'inventory.view',
                'bookings.view',
                'bookings.manage',
            ]],
        ];

        foreach ($roles as $slug => $definition) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $definition['name']],
            );

            $role->permissions()->sync(array_map(
                fn (string $permission): string => $saved[$permission]->id,
                $definition['permissions'],
            ));
        }
    }
}
