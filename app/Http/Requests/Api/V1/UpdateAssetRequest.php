<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ResourceStatus;
use App\Models\Asset;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $asset = $this->route('asset');

        return $user instanceof User && $asset instanceof Asset && $user->can('update', $asset);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = $this->attributes->get('tenant');
        $tenantId = $tenantId instanceof Tenant ? $tenantId->id : null;

        return [
            'location_id' => ['sometimes', 'string', Rule::exists('locations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'resource_category_id' => ['sometimes', 'nullable', 'string', Rule::exists('resource_categories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(ResourceStatus::writable())],
            'capacity' => ['sometimes', 'integer', 'between:1,10000'],
            'is_guide' => ['sometimes', 'boolean'],
            'prep_buffer_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'return_buffer_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'turnaround_minutes' => ['sometimes', 'integer', 'between:0,1440'],
        ];
    }
}
