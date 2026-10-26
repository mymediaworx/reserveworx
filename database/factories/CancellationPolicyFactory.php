<?php

namespace Database\Factories;

use App\Enums\CancellationPolicyKind;
use App\Models\CancellationPolicy;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CancellationPolicy>
 */
class CancellationPolicyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => CancellationPolicyKind::CustomerCancel,
            'lead_minutes' => 1440,
            'refund_percent' => 100,
        ];
    }
}
