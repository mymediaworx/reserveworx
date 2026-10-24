<?php

namespace App\Http\Resources;

use App\Models\WebhookDeliveryAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WebhookDeliveryAttempt */
class WebhookDeliveryAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt' => $this->attempt,
            'status' => $this->status,
            'response_code' => $this->response_code,
            'duration_ms' => $this->duration_ms,
            'response_body' => $this->response_body,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
