<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WebhookEvent;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Rules\PublicHttpsUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', WebhookEndpoint::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:80'],
            'url' => ['required', 'string', 'max:2000', new PublicHttpsUrl],
            'events' => ['required', 'array', 'min:1', 'max:5'],
            'events.*' => ['required', 'string', 'distinct', Rule::in(WebhookEvent::values())],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
