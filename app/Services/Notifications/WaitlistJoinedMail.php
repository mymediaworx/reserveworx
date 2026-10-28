<?php

namespace App\Services\Notifications;

use App\Enums\TransactionalEmail;
use App\Models\NotificationHook;
use App\Models\TenantNotificationSetting;
use App\Models\WaitlistEntry;
use App\Notifications\WaitlistJoinedNotification;
use App\Services\Tenancy\WorkspaceOrigin;
use App\Support\Display;
use App\Support\DisplayZone;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Sends the waitlist confirmation when this workspace can deliver mail.
 * The log and array mailers leave the hook queued. A mailer that refuses the
 * message records that failure once and does not send it again. The party
 * stays on the list.
 */
final class WaitlistJoinedMail
{
    public function __construct(
        private readonly TransactionalEmailPreferences $preferences,
        private readonly NotificationHooks $hooks,
        private readonly WorkspaceOrigin $origins,
        private readonly TenantManager $tenants,
    ) {}

    public function send(WaitlistEntry $entry): bool
    {
        try {
            return $this->deliver($entry);
        } catch (Throwable) {
            return false;
        }
    }

    private function deliver(WaitlistEntry $entry): bool
    {
        $context = $this->tenants->id();

        if (! is_string($context) || $context !== $entry->tenant_id) {
            return false;
        }

        $email = $entry->customer_email;

        if (! is_string($email) || $email === '') {
            return false;
        }

        $origin = $this->origins->baseUrl($entry->tenant_id);

        if ($origin === null) {
            return false;
        }

        $leaveUrl = $this->origins->generate($origin, fn (): string => URL::signedRoute('waitlist.leave', [
            'waitlistEntry' => $entry->id,
        ]));

        if ($leaveUrl === '') {
            return false;
        }

        $this->hooks->record(
            $entry->tenant_id,
            null,
            TransactionalEmail::WaitlistJoined->value,
            'waitlist.joined:'.$entry->id,
            [
                'waitlist_entry_id' => $entry->id,
                'service_id' => $entry->service_id,
            ],
        );

        if (! $this->preferences->enabled($entry->tenant_id, TransactionalEmail::WaitlistJoined->value)) {
            return false;
        }

        $mailer = config('mail.default');
        $mailer = is_string($mailer) ? $mailer : '';

        if ($mailer === '' || in_array($mailer, ['log', 'array'], true)) {
            return false;
        }

        $entry->loadMissing(['service.location']);
        $serviceName = $entry->service?->name;
        $serviceName = is_string($serviceName) ? trim($serviceName) : '';

        if ($serviceName === '') {
            $serviceName = 'your booking';
        }

        $zone = DisplayZone::forLocation($entry->service?->location);
        $when = Display::dateTimeWithZone($entry->starts_at, $zone);
        $setting = TenantNotificationSetting::query()->where('tenant_id', $entry->tenant_id)->first();
        $replyTo = $setting instanceof TenantNotificationSetting ? $setting->reply_to : null;
        $replyTo = is_string($replyTo) && $replyTo !== '' ? $replyTo : null;
        $sent = false;

        DB::transaction(function () use ($entry, $email, $serviceName, $when, $leaveUrl, $replyTo, &$sent): void {
            $hook = NotificationHook::query()
                ->where('dedupe_key', 'waitlist.joined:'.$entry->id)
                ->lockForUpdate()
                ->first();

            if (! $hook instanceof NotificationHook || $hook->delivery_status !== 'queued' || $hook->tenant_id !== $entry->tenant_id) {
                return;
            }

            try {
                Notification::route('mail', $email)->notify(new WaitlistJoinedNotification(
                    $serviceName,
                    $when,
                    $entry->participants,
                    $leaveUrl,
                    $replyTo,
                ));
            } catch (Throwable $exception) {
                $hook->delivery_status = 'failed';
                $hook->delivery_error = 'The mailer refused the message ('.class_basename($exception).').';
                $hook->attempted_at = Carbon::now('UTC');
                $hook->save();

                return;
            }

            $hook->delivery_status = 'sent';
            $hook->delivery_error = null;
            $hook->attempted_at = Carbon::now('UTC');
            $hook->save();
            $sent = true;
        });

        return $sent;
    }
}
