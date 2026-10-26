<?php

namespace Database\Factories;

use App\Models\GhlMessageLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GhlMessageLog>
 */
class GhlMessageLogFactory extends Factory
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
            'refund_id' => null,
            'checkout_id' => null,
            'kind' => 'booking.confirmed',
            'channel' => null,
            'dedupe_key' => 'booking.confirmed:'.fake()->unique()->bothify('????????'),
            'status' => 'queued',
            'attempts' => 0,
            'http_status' => null,
            'error' => null,
            'location_id' => null,
            'ghl_message_id' => null,
            'offset_minutes' => null,
        ];
    }
}
