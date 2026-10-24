<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ResourceType;
use App\Models\ServiceVariant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', ServiceVariant::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'price_override_cents' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'resources' => ['sometimes', 'array', 'max:20'],
            'resources.*.resource_type' => ['required', Rule::enum(ResourceType::class)],
            'resources.*.resource_category_id' => ['required', 'string'],
            'resources.*.resource_id' => ['required', 'string', 'distinct'],
            'resources.*.quantity' => ['required', 'integer', 'min:1'],
            'resources.*.capacity' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
