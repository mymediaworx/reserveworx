<?php

namespace App\Services\Notifications;

use App\Enums\BookingStatus;
use App\Enums\TransactionalEmail;
use App\Models\Booking;
use App\Models\NotificationHook;
use App\Models\TenantNotificationSetting;
use App\Notifications\BookingConfirmedNotification;
use App\Services\CheckIn\CheckInPass;
use App\Services\CheckIn\QrSvg;
use App\Services\Waivers\WaiverLinks;
use App\Support\BookingReference;
use App\Support\Display;
use App\Support\DisplayZone;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends the confirmation email when this workspace can actually deliver mail.
 * The log and array mailers leave the hook queued. A later dispatch still records
 * that no provider is configured. A mailer that refuses the message records that
 * failure and does not send again. The failure does not undo the confirmation.
 */
final class BookingConfirmationMail
{
    public function __construct(
        private readonly CheckInPass $passes,
        private readonly QrSvg $qr,
        private readonly TransactionalEmailPreferences $preferences,
        private readonly TenantManager $tenants,
        private readonly WaiverLinks $waivers,
    ) {}

    public function send(Booking $booking): void
    {
        try {
            $this->deliver($booking);
        } catch (Throwable) {
            // The booking stays confirmed. The hook remains queued.
        }
    }

    private function deliver(Booking $booking): void
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            return;
        }

        $context = $this->tenants->id();

        if (! is_string($context) || $context !== $booking->tenant_id) {
            return;
        }

        if (! $this->preferences->enabled($booking->tenant_id, TransactionalEmail::BookingConfirmed->value)) {
            return;
        }

        $booking->loadMissing(['customer', 'service', 'location']);
        $email = $booking->customer?->email;

        if (! is_string($email) || $email === '') {
            return;
        }

        $mailer = config('mail.default');
        $mailer = is_string($mailer) ? $mailer : '';

        if ($mailer === '' || in_array($mailer, ['log', 'array'], true)) {
            return;
        }

        $url = $this->passes->url($booking);

        if ($url === null) {
            return;
        }

        $svg = $this->qr->render($url);
        $serviceName = $booking->service?->name;
        $serviceName = is_string($serviceName) ? trim($serviceName) : '';

        if ($serviceName === '') {
            $serviceName = 'your booking';
        }

        $setting = TenantNotificationSetting::query()->where('tenant_id', $booking->tenant_id)->first();
        $replyTo = $setting instanceof TenantNotificationSetting ? $setting->reply_to : null;
        $replyTo = is_string($replyTo) && $replyTo !== '' ? $replyTo : null;

        $when = Display::rangeWithZone($booking->starts_at, $booking->ends_at, DisplayZone::forLocation($booking->location));
        $reference = BookingReference::short($booking->id);

        DB::transaction(function () use ($booking, $email, $serviceName, $url, $svg, $replyTo, $when, $reference): void {
            $hook = NotificationHook::query()
                ->where('dedupe_key', 'booking.confirmed:'.$booking->id)
                ->lockForUpdate()
                ->first();

            if (! $hook instanceof NotificationHook || $hook->delivery_status !== 'queued' || $hook->tenant_id !== $booking->tenant_id) {
                return;
            }

            try {
                Notification::route('mail', $email)->notify(new BookingConfirmedNotification(
                    $serviceName,
                    $url,
                    $svg,
                    $replyTo,
                    $when,
                    $reference,
                    $this->waivers->urlIfNeeded($booking),
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
        });
    }
}
