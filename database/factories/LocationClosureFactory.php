<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\LocationClosure;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocationClosure>
 */
class LocationClosureFactory extends Factory
{
    protected $model = LocationClosure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'location_id' => Location::factory(),
            'resource_category_id' => null,
            'resource_type' => null,
            'resource_id' => null,
            'reason' => 'Holiday',
            'all_day' => true,
            'recurs_yearly' => false,
            'starts_on' => '2026-07-04',
            'ends_on' => '2026-07-04',
            'starts_local' => null,
            'ends_local' => null,
        ];
    }
}
