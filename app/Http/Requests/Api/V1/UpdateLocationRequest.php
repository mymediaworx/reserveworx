<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LocationStatus;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $location = $this->route('location');

        return $user instanceof User
            && $location instanceof Location
            && $user->can('update', $location);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
        ];
    }
}
