<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ResourceStatus;
use App\Models\Pool;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Pool::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->attributes->get('tenant');
        $tenantId = $tenant instanceof Tenant ? $tenant->id : null;

        return [
            'location_id' => ['required', 'string', Rule::exists('locations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'resource_category_id' => ['nullable', 'string', Rule::exists('resource_categories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'name' => ['required', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'between:0,100000'],
            'status' => ['sometimes', Rule::enum(ResourceStatus::class)],
            'prep_buffer_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'return_buffer_minutes' => ['sometimes', 'integer', 'between:0,1440'],
        ];
    }
}
