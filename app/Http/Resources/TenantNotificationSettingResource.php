<?php

namespace App\Http\Resources;

use App\Models\TenantNotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantNotificationSetting */
class TenantNotificationSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'reply_to' => $this->reply_to,
            'staff_alert_recipients' => $this->recipients(),
            'transactional_emails' => $this->preferences(),
            'feedback_delay_minutes' => $this->feedbackDelayMinutes(),
            'review_url' => $this->review_url,
        ];
    }
}
