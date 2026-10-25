<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\CsvImport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CsvImport>
 */
class CsvImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'type' => ImportType::Customers,
            'status' => ImportStatus::Uploaded,
            'disk' => 'local',
            'path' => 'imports/example.csv',
            'original_name' => 'customers.csv',
            'delimiter' => ',',
            'headers' => ['name', 'email', 'phone'],
            'mapping' => ['name' => 0, 'email' => 1, 'phone' => 2],
            'row_count' => 0,
            'processed_rows' => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'next_row' => 2,
            'data_offset' => 0,
            'byte_offset' => 0,
            'skipped' => [],
        ];
    }
}
