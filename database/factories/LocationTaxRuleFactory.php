<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocationTaxRule>
 */
class LocationTaxRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'location_id' => function (array $attributes): string {
                return Location::factory()->create([
                    'tenant_id' => $attributes['tenant_id'],
                ])->id;
            },
            'name' => 'Sales tax',
            'percent_bps' => 800,
            'applies_to_rentals' => true,
            'applies_to_add_ons' => false,
            'applies_to_fees' => false,
        ];
    }
}
