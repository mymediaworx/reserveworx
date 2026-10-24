<?php

namespace Database\Factories;

use App\Enums\AddOnKind;
use App\Enums\AddOnPricing;
use App\Models\AddOn;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddOn>
 */
class AddOnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'kind' => AddOnKind::Addon,
            'pricing' => AddOnPricing::Flat,
            'amount_cents' => 1_500,
            'active' => true,
            'max_quantity' => null,
        ];
    }

    public function equipment(int $amountCents = 500, ?int $maxQuantity = null): static
    {
        return $this->state(fn (): array => [
            'kind' => AddOnKind::Equipment,
            'pricing' => AddOnPricing::Each,
            'amount_cents' => $amountCents,
            'max_quantity' => $maxQuantity,
        ]);
    }
}
