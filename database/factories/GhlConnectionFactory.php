<?php

namespace Database\Factories;

use App\Models\GhlConnection;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GhlConnection>
 */
class GhlConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'location_id' => 'loc_'.fake()->unique()->bothify('????????'),
            'private_integration_token' => 'pit-'.fake()->unique()->sha1(),
        ];
    }
}
