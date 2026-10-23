<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'hostname' => 'shop-'.Str::lower(Str::random(8)).'.test',
            'type' => 'custom',
            'verified_at' => now(),
            'is_primary' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['verified_at' => null]);
    }
}
