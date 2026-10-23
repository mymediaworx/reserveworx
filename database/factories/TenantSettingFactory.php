<?php

namespace Database\Factories;

use App\Enums\FeePayer;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSetting>
 */
class TenantSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'hold_minutes' => 15,
            'ledger_bucket_minutes' => 15,
            'fee_payer' => FeePayer::CustomerPaid,
            'direct_fee_bps' => 300,
            'third_party_fee_bps' => 100,
            'direct_fee_fixed_cents' => 0,
            'third_party_fee_fixed_cents' => 0,
            'direct_fee_minimum_cents' => null,
            'third_party_fee_minimum_cents' => null,
            'currency' => 'USD',
        ];
    }
}
