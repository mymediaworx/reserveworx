<?php

namespace Database\Factories;

use App\Enums\OperatorFeePricing;
use App\Models\Location;
use App\Models\OperatorFee;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperatorFee>
 */
class OperatorFeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'service_id' => null,
            'location_id' => null,
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'pricing' => OperatorFeePricing::Flat,
            'amount_cents' => 2_500,
            'percent_bps' => null,
            'active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (OperatorFee $fee): void {
            if ($fee->service_id !== null || $fee->location_id !== null) {
                return;
            }

            $fee->location_id = Location::factory()->create([
                'tenant_id' => $fee->tenant_id,
            ])->id;
        });
    }
}
