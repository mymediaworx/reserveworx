<?php

namespace Database\Factories;

use App\Models\IntegrationLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationLog>
 */
class IntegrationLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'channel' => IntegrationLog::CHANNEL_OUTBOX,
            'kind' => 'booking.confirmed',
            'attempt' => 1,
            'status' => 'deferred',
            'http_status' => null,
            'error' => null,
            'available_at' => null,
        ];
    }
}
