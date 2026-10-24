<?php

namespace App\Http\Resources;

use App\Models\TenantSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantSetting */
class TenantSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'hold_minutes' => $this->hold_minutes,
            'guest_cancel_before_minutes' => $this->guest_cancel_before_minutes,
            'late_fee_per_minute_cents' => $this->late_fee_per_minute_cents,
            'ledger_bucket_minutes' => $this->ledger_bucket_minutes,
            'fee_payer' => $this->fee_payer->value,
            'direct_fee_bps' => $this->direct_fee_bps,
            'third_party_fee_bps' => $this->third_party_fee_bps,
            'direct_fee_fixed_cents' => $this->direct_fee_fixed_cents,
            'third_party_fee_fixed_cents' => $this->third_party_fee_fixed_cents,
            'direct_fee_minimum_cents' => $this->direct_fee_minimum_cents,
            'third_party_fee_minimum_cents' => $this->third_party_fee_minimum_cents,
            'currency' => $this->currency,
            'pay_at_pickup' => $this->pay_at_pickup,
        ];
    }
}
