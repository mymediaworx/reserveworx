<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ResourceCategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', ResourceCategory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->attributes->get('tenant');
        $tenantId = $tenant instanceof Tenant ? $tenant->id : null;

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('resource_categories', 'slug')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId),
                ),
            ],
        ];
    }
}
