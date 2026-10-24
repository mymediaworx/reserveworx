<?php

namespace Database\Factories;

use App\Models\GhlSyncLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GhlSyncLog>
 */
class GhlSyncLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'booking_id' => null,
            'payment_id' => null,
            'kind' => 'connection.test',
            'attempt' => 1,
            'status' => 'skipped',
            'http_status' => null,
            'error' => null,
            'location_id' => 'loc_'.fake()->bothify('????????'),
        ];
    }
}
