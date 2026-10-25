<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\FeePayer;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $setting = TenantSetting::query()->first();

        return $user instanceof User
            && $setting instanceof TenantSetting
            && $user->can('update', $setting);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hold_minutes' => ['sometimes', 'integer', 'between:5,120'],
            'guest_cancel_before_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,43200'],
            'late_fee_per_minute_cents' => ['sometimes', 'nullable', 'integer', 'between:0,100000'],
            'late_grace_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,10080'],
            'ledger_bucket_minutes' => ['sometimes', 'integer', Rule::in([5, 10, 15, 30, 60])],
            'fee_payer' => ['sometimes', Rule::enum(FeePayer::class)],
            'pay_at_pickup' => ['sometimes', 'boolean'],
            'direct_fee_bps' => ['prohibited'],
            'third_party_fee_bps' => ['prohibited'],
            'direct_fee_fixed_cents' => ['prohibited'],
            'third_party_fee_fixed_cents' => ['prohibited'],
            'direct_fee_minimum_cents' => ['prohibited'],
            'third_party_fee_minimum_cents' => ['prohibited'],
            'currency' => ['prohibited'],
        ];
    }
}
