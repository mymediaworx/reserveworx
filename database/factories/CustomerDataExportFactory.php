<?php

namespace Database\Factories;

use App\Enums\CustomerDataExportStatus;
use App\Models\Customer;
use App\Models\CustomerDataExport;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerDataExport>
 */
class CustomerDataExportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'actor_user_id' => User::factory(),
            'disk' => CustomerDataExport::DISK,
            'path' => null,
            'bytes' => null,
            'row_counts' => null,
            'status' => CustomerDataExportStatus::Queued,
            'completed_at' => null,
            'failed_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CustomerDataExport $export): void {
            $customer = Customer::withoutGlobalScope(TenantScope::class)->find($export->customer_id);

            if ($customer instanceof Customer) {
                $export->tenant_id = $customer->tenant_id;
            }
        });
    }
}
