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

class StoreOperatorFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', OperatorFee::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->attributes->get('tenant');
        $tenantId = $tenant instanceof Tenant ? $tenant->id : null;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('operator_fees', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'description' => ['nullable', 'string', 'max:500'],
            'pricing' => ['required', Rule::enum(OperatorFeePricing::class)],
            'amount_cents' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'percent_bps' => ['nullable', 'integer', 'between:1,10000'],
            'service_id' => ['nullable', 'string'],
            'location_id' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->assertPricing($validator, $this->input('pricing'), $this->input('amount_cents'), $this->input('percent_bps'));
            $this->assertScope($validator, $this->input('service_id'), $this->input('location_id'));
        });
    }

    private function assertPricing(Validator $validator, mixed $pricing, mixed $amount, mixed $percent): void
    {
        if ($pricing === OperatorFeePricing::Percent->value) {
            if ($amount !== null && $amount !== '') {
                $validator->errors()->add('amount_cents', 'A percent fee does not take an amount.');
            }

            if (! is_numeric($percent)) {
                $validator->errors()->add('percent_bps', 'A percent fee needs a rate.');
            }

            return;
        }

        if ($percent !== null && $percent !== '') {
            $validator->errors()->add('percent_bps', 'Only a percent fee takes a rate.');
        }

        if (! is_numeric($amount)) {
            $validator->errors()->add('amount_cents', 'A flat or per-person fee needs an amount.');
        }
    }

    private function assertScope(Validator $validator, mixed $serviceId, mixed $locationId): void
    {
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
    }
}
