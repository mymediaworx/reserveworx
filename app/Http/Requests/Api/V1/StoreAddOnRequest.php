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

class StoreAddOnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', AddOn::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->attributes->get('tenant');
        $tenantId = $tenant instanceof Tenant ? $tenant->id : null;

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('add_ons', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'description' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', Rule::enum(AddOnKind::class)],
            'pricing' => ['required', Rule::enum(AddOnPricing::class)],
            'amount_cents' => ['required', 'integer', 'min:0', 'max:1000000'],
            'active' => ['sometimes', 'boolean'],
            'max_quantity' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('pricing') !== AddOnPricing::Each->value && $this->filled('max_quantity')) {
                $validator->errors()->add('max_quantity', 'Only an each-priced add-on has a maximum quantity.');
            }
        });
    }
}
