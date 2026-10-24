<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class LinkGhlSubAccountRequest extends FormRequest
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
            'location_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'private_integration_token' => ['required', 'string', 'min:20', 'max:2000'],
        ];
    }
}
