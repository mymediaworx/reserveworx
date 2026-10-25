<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Industry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:tenants,slug'],
            'industry' => ['sometimes', 'nullable', 'string', Rule::enum(Industry::class)],
        ];
    }
}
