<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TransactionalEmail;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Services\Branding\WorkspaceProfiles;
use App\Services\Feedback\FeedbackRequests;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNotificationSettingRequest extends FormRequest
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

        $setting = app(WorkspaceProfiles::class)->notificationSettings();

        return $user->can('update', $setting);
    }

    protected function prepareForValidation(): void
    {
        $recipients = $this->input('staff_alert_recipients');

        if (is_array($recipients)) {
            $normalized = [];

            foreach ($recipients as $recipient) {
                $normalized[] = is_string($recipient) ? strtolower(trim($recipient)) : $recipient;
            }

            $this->merge(['staff_alert_recipients' => $normalized]);
        }

        if ($this->exists('reply_to') && is_string($this->input('reply_to'))) {
            $reply = strtolower(trim($this->input('reply_to')));
            $this->merge(['reply_to' => $reply === '' ? null : $reply]);
        }

        $emails = $this->input('transactional_emails');

        if (is_array($emails)) {
            $normalized = [];

            foreach ($emails as $key => $value) {
                $normalized[is_string($key) ? strtolower(trim($key)) : $key] = $value;
            }

            $this->merge(['transactional_emails' => $normalized]);
        }

        if ($this->exists('review_url') && is_string($this->input('review_url'))) {
            $review = trim($this->input('review_url'));
            $this->merge(['review_url' => $review === '' ? null : $review]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reply_to' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'staff_alert_recipients' => ['sometimes', 'array', 'max:20'],
            'staff_alert_recipients.*' => ['required', 'string', 'email', 'max:255', 'distinct'],
            'transactional_emails' => ['sometimes', 'array'],
            'transactional_emails.*' => ['required', 'boolean'],
            'feedback_delay_minutes' => ['sometimes', 'integer', 'min:0', 'max:'.FeedbackRequests::MAX_DELAY_MINUTES],
            'review_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $emails = $this->input('transactional_emails');

            if (is_array($emails)) {
                $unknown = array_diff(array_map(strval(...), array_keys($emails)), TransactionalEmail::values());

                if ($unknown !== []) {
                    $validator->errors()->add('transactional_emails', 'Unknown transactional email: '.implode(', ', $unknown));
                }
            }

            if (! $this->exists('review_url')) {
                return;
            }

            $review = $this->input('review_url');

            if ($review === null) {
                return;
            }

            if (! is_string($review) || FeedbackRequests::acceptableReviewUrl($review) === null) {
                $validator->errors()->add('review_url', 'The review link must be an https address.');
            }
        });
    }

    public function settings(): TenantNotificationSetting
    {
        return app(WorkspaceProfiles::class)->notificationSettings();
    }
}
