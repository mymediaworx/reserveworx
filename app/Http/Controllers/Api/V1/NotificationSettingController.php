<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateNotificationSettingRequest;
use App\Http\Resources\TenantNotificationSettingResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Branding\WorkspaceProfiles;
use Illuminate\Http\Request;

class NotificationSettingController extends Controller
{
    public function __construct(
        private readonly WorkspaceProfiles $profiles,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): TenantNotificationSettingResource
    {
        $setting = $this->profiles->notificationSettings();
        $this->authorize('view', $setting);

        return new TenantNotificationSettingResource($setting);
    }

    public function update(UpdateNotificationSettingRequest $request): TenantNotificationSettingResource
    {
        $setting = $request->settings();
        $before = $setting->auditSnapshot();

        if ($request->exists('reply_to')) {
            $reply = $request->validated('reply_to');
            $setting->reply_to = is_string($reply) && $reply !== '' ? $reply : null;
        }

        if ($request->exists('staff_alert_recipients')) {
            $recipients = $request->validated('staff_alert_recipients');
            $setting->staff_alert_recipients = is_array($recipients) ? array_values($recipients) : [];
        }

        if ($request->exists('transactional_emails')) {
            $incoming = $request->validated('transactional_emails');
            $preferences = $setting->preferences();

            if (is_array($incoming)) {
                foreach ($incoming as $kind => $enabled) {
                    if (is_string($kind)) {
                        $preferences[$kind] = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
                    }
                }
            }

            $setting->transactional_emails = $preferences;
        }

        $setting->save();

        $actor = $request->user();

        $this->audit->log(
            action: 'notification_settings.updated',
            subject: $setting,
            properties: [
                'before' => $before,
                'after' => $setting->auditSnapshot(),
            ],
            actor: $actor instanceof User ? $actor : null,
            request: $request,
        );

        return new TenantNotificationSettingResource($setting);
    }
}
