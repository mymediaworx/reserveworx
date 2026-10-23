<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LocationStatus;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', Location::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
        ];
    }
}
