<?php

namespace App\Rules;

use App\Services\Webhooks\PublicHttpsEndpoint;
use App\Services\Webhooks\UnsafeWebhookUrlException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PublicHttpsUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Use a public HTTPS URL.');

            return;
        }

        try {
            app(PublicHttpsEndpoint::class)->resolve($value);
        } catch (UnsafeWebhookUrlException) {
            $fail('Use a public HTTPS URL.');
        }
    }
}
