<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AddOnKind;
use App\Enums\AddOnPricing;
use App\Models\AddOn;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAddOnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $addOn = $this->route('add_on');

        return $user instanceof User && $addOn instanceof AddOn && $user->can('update', $addOn);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->attributes->get('tenant');
        $tenantId = $tenant instanceof Tenant ? $tenant->id : null;
        $addOn = $this->route('add_on');

        return [
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('add_ons', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))->ignore($addOn instanceof AddOn ? $addOn->id : null)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'kind' => ['sometimes', Rule::enum(AddOnKind::class)],
            'pricing' => ['sometimes', Rule::enum(AddOnPricing::class)],
            'amount_cents' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'active' => ['sometimes', 'boolean'],
            'max_quantity' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $addOn = $this->route('add_on');

            if (! $addOn instanceof AddOn) {
                return;
            }

            $pricing = $this->input('pricing', $addOn->pricing->value);

            if ($pricing !== AddOnPricing::Each->value && $this->exists('max_quantity') && $this->input('max_quantity') !== null) {
                $validator->errors()->add('max_quantity', 'Only an each-priced add-on has a maximum quantity.');
            }
        });
    }
}
