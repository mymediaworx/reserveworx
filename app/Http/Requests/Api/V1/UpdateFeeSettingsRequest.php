<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direct_fee_bps' => ['required', 'integer', 'between:0,10000'],
            'third_party_fee_bps' => ['required', 'integer', 'between:0,10000'],
            'direct_fee_fixed_cents' => ['required', 'integer', 'min:0', 'max:1000000'],
            'third_party_fee_fixed_cents' => ['required', 'integer', 'min:0', 'max:1000000'],
            'direct_fee_minimum_cents' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'third_party_fee_minimum_cents' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
