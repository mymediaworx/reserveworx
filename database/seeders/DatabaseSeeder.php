<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Roles first, then two demo workspaces. Demo passwords go to DEMO_LOGIN_FILE.
     */
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            DemoTenantSeeder::class,
            DemoShopSeeder::class,
        ]);
    }
}
