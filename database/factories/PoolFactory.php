<?php

namespace Database\Factories;

use App\Enums\ResourceStatus;
use App\Models\Location;
use App\Models\Pool;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pool>
 */
class PoolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'resource_category_id' => null,
            'name' => fake()->unique()->numerify('Kayaks ##'),
            'quantity' => 10,
            'status' => ResourceStatus::Active,
            'prep_buffer_minutes' => 0,
            'return_buffer_minutes' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Pool $pool): void {
            $location = Location::withoutGlobalScope(TenantScope::class)->find($pool->location_id);

            if ($location !== null) {
                $pool->tenant_id = $location->tenant_id;
            }
        });
    }
}
