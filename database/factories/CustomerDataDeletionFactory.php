<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerDataDeletion;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerDataDeletion>
 */
class CustomerDataDeletionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'actor_user_id' => User::factory(),
            'export_id' => null,
            'confirmed' => false,
            'anonymized_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CustomerDataDeletion $deletion): void {
            $customer = Customer::withoutGlobalScope(TenantScope::class)->find($deletion->customer_id);

            if ($customer instanceof Customer) {
                $deletion->tenant_id = $customer->tenant_id;
            }
        });
    }
}
