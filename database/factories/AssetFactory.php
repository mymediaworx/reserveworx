<?php

namespace Database\Factories;

use App\Enums\ResourceStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'resource_category_id' => null,
            'name' => fake()->unique()->bothify('Boat ??'),
            'status' => ResourceStatus::Active,
            'capacity' => 1,
            'prep_buffer_minutes' => 0,
            'return_buffer_minutes' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Asset $asset): void {
            $location = Location::withoutGlobalScope(TenantScope::class)->find($asset->location_id);

            if ($location !== null) {
                $asset->tenant_id = $location->tenant_id;
            }
        });
    }
}
