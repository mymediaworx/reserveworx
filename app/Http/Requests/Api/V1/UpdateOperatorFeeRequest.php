<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\OperatorFeePricing;
use App\Models\Location;
use App\Models\OperatorFee;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOperatorFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $fee = $this->route('operator_fee');

        return $user instanceof User && $fee instanceof OperatorFee && $user->can('update', $fee);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->attributes->get('tenant');
        $tenantId = $tenant instanceof Tenant ? $tenant->id : null;
        $fee = $this->route('operator_fee');

        return [
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('operator_fees', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))->ignore($fee instanceof OperatorFee ? $fee->id : null)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pricing' => ['sometimes', Rule::enum(OperatorFeePricing::class)],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'percent_bps' => ['sometimes', 'nullable', 'integer', 'between:1,10000'],
            'service_id' => ['sometimes', 'nullable', 'string'],
            'location_id' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fee = $this->route('operator_fee');

            if (! $fee instanceof OperatorFee) {
                return;
            }

            $pricing = $this->input('pricing', $fee->pricing->value);
            $amount = $this->exists('amount_cents') ? $this->input('amount_cents') : $fee->amount_cents;
            $percent = $this->exists('percent_bps') ? $this->input('percent_bps') : $fee->percent_bps;
            $clearingAmount = $pricing === OperatorFeePricing::Percent->value && ! $this->exists('amount_cents');
            $clearingPercent = $pricing !== OperatorFeePricing::Percent->value && ! $this->exists('percent_bps');

            if ($pricing === OperatorFeePricing::Percent->value) {
                if ($this->exists('amount_cents') && $this->input('amount_cents') !== null) {
                    $validator->errors()->add('amount_cents', 'A percent fee does not take an amount.');
                }

                $rate = $clearingPercent ? null : $percent;

                if (! is_numeric($rate)) {
                    $validator->errors()->add('percent_bps', 'A percent fee needs a rate.');
                }
            } else {
                if ($this->exists('percent_bps') && $this->input('percent_bps') !== null) {
                    $validator->errors()->add('percent_bps', 'Only a percent fee takes a rate.');
                }

                $value = $clearingAmount ? null : $amount;

                if (! is_numeric($value)) {
                    $validator->errors()->add('amount_cents', 'A flat or per-person fee needs an amount.');
                }
            }

            $serviceId = $this->exists('service_id') ? $this->input('service_id') : $fee->service_id;
            $locationId = $this->exists('location_id') ? $this->input('location_id') : $fee->location_id;
            $hasService = is_string($serviceId) && $serviceId !== '';
            $hasLocation = is_string($locationId) && $locationId !== '';

            if ($hasService === $hasLocation) {
                $validator->errors()->add('service_id', 'Choose a service or a location.');

                return;
            }

            if ($hasService && ! Service::query()->whereKey($serviceId)->exists()) {
                $validator->errors()->add('service_id', 'That service is not in this workspace.');
            }

            if ($hasLocation && ! Location::query()->whereKey($locationId)->exists()) {
                $validator->errors()->add('location_id', 'That location is not in this workspace.');
            }
        });
    }
}
