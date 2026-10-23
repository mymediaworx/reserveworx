<?php

namespace Database\Factories;

use App\Enums\LocationStatus;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->city().' Dock',
            'timezone' => 'America/New_York',
            'status' => LocationStatus::Active,
        ];
    }
}
