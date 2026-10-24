<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TenantBranding;
use App\Models\User;
use App\Services\Branding\WorkspaceProfiles;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTenantBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $tenantId = app(TenantManager::class)->id();

        if (! $user instanceof User || ! is_string($tenantId)) {
            return false;
        }

        if (! app(Access::class)->allows($user, $tenantId, 'settings.update')) {
            return false;
        }

        $branding = app(WorkspaceProfiles::class)->branding();

        return $user->can('update', $branding);
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('display_name') && is_string($this->input('display_name'))) {
            $this->merge(['display_name' => trim($this->input('display_name'))]);
        }

        foreach (['primary_color', 'accent_color'] as $field) {
            if ($this->exists($field) && is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }

        foreach (['support_email', 'logo_url'] as $field) {
            if ($this->exists($field) && is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }

        if ($this->exists('support_email') && is_string($this->input('support_email'))) {
            $this->merge(['support_email' => strtolower($this->input('support_email'))]);
        }

        if ($this->exists('support_phone') && is_string($this->input('support_phone'))) {
            $phone = trim($this->input('support_phone'));
            $this->merge(['support_phone' => $phone === '' ? null : $phone]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:120'],
            'logo' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:2048'],
            'logo_base64' => ['sometimes', 'nullable', 'string', 'max:2800000'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'remove_logo' => ['sometimes', 'boolean'],
            'primary_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'accent_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'support_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[0-9+().\\-\\s]+$/', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }

                $digits = preg_replace('/\D+/', '', $value);
                $count = is_string($digits) ? strlen($digits) : 0;

                if ($count < 7 || $count > 15) {
                    $fail('The support phone must contain 7 to 15 digits.');
                }
            }],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasFile = $this->hasFile('logo');
            $hasEncoded = $this->filled('logo_base64');
            $hasUrl = $this->exists('logo_url') && is_string($this->input('logo_url')) && $this->input('logo_url') !== '';
            $remove = $this->boolean('remove_logo');

            if (($hasFile && $hasEncoded) || (($hasFile || $hasEncoded) && $hasUrl)) {
                $validator->errors()->add('logo', 'Send a logo file, a base64 logo, or a logo URL.');
            }

            if ($remove && ($hasFile || $hasEncoded || $hasUrl)) {
                $validator->errors()->add('remove_logo', 'Remove the logo on its own.');
            }

            $logoUrl = $this->input('logo_url');

            if (is_string($logoUrl) && $logoUrl !== '') {
                $parts = parse_url($logoUrl);

                if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || str_contains($logoUrl, '/api/v1/branding/logo')) {
                    $validator->errors()->add('logo_url', 'The logo URL must be an http or https address without a username or password.');
                }
            }
        });
    }

    public function branding(): TenantBranding
    {
        return app(WorkspaceProfiles::class)->branding();
    }
}
