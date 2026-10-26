<?php

namespace Database\Factories;

use App\Enums\ResourceStatus;
use App\Models\FleetItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetItem>
 */
class FleetItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->numerify('Kayaks ##'),
            'quantity' => 10,
            'status' => ResourceStatus::Active,
        ];
    }
}
