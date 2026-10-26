<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\GhlMapping;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GhlMapping>
 */
class GhlMappingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => function (array $attributes): string {
                $tenantId = $attributes['tenant_id'] ?? null;

                return Customer::factory()->create([
                    'tenant_id' => is_string($tenantId) ? $tenantId : Tenant::factory(),
                ])->id;
            },
            'location_id' => 'loc_'.fake()->unique()->bothify('????????'),
            'contact_id' => 'contact-'.fake()->unique()->bothify('????????'),
        ];
    }
}
