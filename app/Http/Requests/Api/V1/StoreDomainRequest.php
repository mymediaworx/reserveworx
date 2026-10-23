<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Hostname;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDomainRequest extends FormRequest
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
            'hostname' => ['required', 'string', 'max:253'],
            'type' => ['required', Rule::in(['subdomain', 'custom'])],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $host = Hostname::normalize((string) $this->input('hostname'));

            if (! Hostname::isValid($host)) {
                $validator->errors()->add('hostname', 'Enter a hostname such as booking.example.com.');

                return;
            }

            if (in_array($host, config('reserveworx.platform_domains', []), true)) {
                $validator->errors()->add('hostname', 'That hostname is reserved for the platform.');
            }
        });
    }

    public function hostname(): string
    {
        return Hostname::normalize((string) $this->input('hostname'));
    }
}
