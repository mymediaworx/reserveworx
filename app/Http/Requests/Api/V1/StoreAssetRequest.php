<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ResourceStatus;
use App\Models\Asset;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\StaffLocationAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Asset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'location_id' => ['required', 'string', app(StaffLocationAccess::class)->locationExistsRule($tenantId)],
            'resource_category_id' => ['nullable', 'string', $this->owned('resource_categories', $tenantId)],
            'name' => ['required', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(ResourceStatus::writable())],
            'capacity' => ['sometimes', 'integer', 'between:1,10000'],
            'is_guide' => ['sometimes', 'boolean'],
            'prep_buffer_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'return_buffer_minutes' => ['sometimes', 'integer', 'between:0,1440'],
            'turnaround_minutes' => ['sometimes', 'integer', 'between:0,1440'],
        ];
    }

    private function tenantId(): ?string
    {
        $tenant = $this->attributes->get('tenant');

        return $tenant instanceof Tenant ? $tenant->id : null;
    }

    private function owned(string $table, ?string $tenantId): Exists
    {
        return Rule::exists($table, 'id')->where(
            fn ($query) => $query->where('tenant_id', $tenantId),
        );
    }
}
