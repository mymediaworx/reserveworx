<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\WebhookEvent;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Rules\PublicHttpsUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $endpoint = $this->route('webhook_endpoint');

        return $user instanceof User
            && $endpoint instanceof WebhookEndpoint
            && $user->can('update', $endpoint);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'url' => ['sometimes', 'string', 'max:2000', new PublicHttpsUrl],
            'events' => ['sometimes', 'array', 'min:1', 'max:5'],
            'events.*' => ['required', 'string', 'distinct', Rule::in(WebhookEvent::values())],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
