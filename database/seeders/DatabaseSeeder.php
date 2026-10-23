<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed reference data only. This seeder does not create users or tenants.
     */
    public function run(): void
    {
        $this->call(RoleAndPermissionSeeder::class);
    }
}
