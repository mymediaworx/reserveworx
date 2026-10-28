<?php

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Enums\TenantStatus;
use App\Events\BookingReminderDue;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\NotificationHook;
use App\Models\Tenant;
use App\Notifications\BookingReminderNotification;
use App\Services\Integrations\GhlMessages;
use App\Services\Notifications\NotificationHooks;
use App\Services\Notifications\TransactionalEmailPreferences;
use App\Support\BookingReference;
use App\Support\Display;
use App\Support\DisplayZone;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendBookingReminder implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(
        private readonly TenantManager $tenants,
        private readonly NotificationHooks $hooks,
        private readonly TransactionalEmailPreferences $preferences,
        private readonly GhlMessages $ghlMessages,
    ) {}

    public function handle(BookingReminderDue $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if (! $tenant instanceof Tenant || $tenant->status === TenantStatus::Terminated || $tenant->status === TenantStatus::Suspended) {
            return;
        }

        $previous = $this->tenants->get();
        $this->tenants->set($tenant);

        try {
            DB::transaction(function () use ($event, $tenant): void {
                $reminder = BookingReminder::query()
                    ->where('booking_id', $event->bookingId)
                    ->where('offset_minutes', $event->offsetMinutes)
                    ->lockForUpdate()
                    ->first();

                if (! $reminder instanceof BookingReminder || $reminder->dispatched_at !== null) {
                    return;
                }

                if ($reminder->tenant_id !== $tenant->id) {
                    throw new TenantContextException('Refusing to send a reminder for a different workspace.');
                }

                $booking = Booking::query()->with(['location', 'customer', 'service'])->find($event->bookingId);
                $now = Carbon::now('UTC');
                $starts = $booking instanceof Booking ? CarbonImmutable::instance($booking->starts_at) : null;

                if (
                    ! $booking instanceof Booking
                    || $booking->tenant_id !== $tenant->id
                    || $booking->status !== BookingStatus::Confirmed
                    || ! $starts instanceof CarbonImmutable
                    || $starts->lessThanOrEqualTo($now)
                ) {
                    $reminder->dispatched_at = $now;
                    $reminder->save();

                    return;
                }

                $zone = $this->timezone($booking);
                $localStart = $starts->timezone($zone);
                $dedupeKey = 'booking.reminder:'.$booking->id.':'.$event->offsetMinutes;

                $this->hooks->record(
                    $tenant->id,
                    $booking->id,
                    'booking.reminder',
                    $dedupeKey,
                    [
                        'booking_id' => $booking->id,
                        'offset_minutes' => $event->offsetMinutes,
                        'starts_at' => $starts->utc()->toIso8601String(),
                        'local_starts_at' => $localStart->toIso8601String(),
                        'timezone' => $zone,
                    ],
                );

                $hook = NotificationHook::query()->where('dedupe_key', $dedupeKey)->first();
                $email = $booking->customer?->email;
                $mailer = config('mail.default');
                $mailer = is_string($mailer) ? $mailer : '';
                $sentThroughGhl = $this->ghlMessages->queueReminder($booking, $event->offsetMinutes);

                if ($hook instanceof NotificationHook) {
                    if ($sentThroughGhl) {
                        $hook->delivery_status = 'skipped';
                        $hook->delivery_error = 'GoHighLevel sends this reminder.';
                        $hook->attempted_at = $now;
                        $hook->save();
                    } elseif (! $this->preferences->enabled($tenant->id, 'booking.reminder')) {
                        $hook->delivery_status = 'skipped';
                        $hook->delivery_error = 'The workspace turned off booking reminder email.';
                        $hook->attempted_at = $now;
                        $hook->save();
                    } elseif (! $this->delivers($mailer)) {
                        $hook->delivery_status = 'skipped';
                        $hook->delivery_error = $this->skipReason($mailer);
                        $hook->attempted_at = $now;
                        $hook->save();
                    } elseif (! is_string($email) || $email === '') {
                        $hook->delivery_status = 'skipped';
                        $hook->delivery_error = 'The booking has no customer email.';
                        $hook->attempted_at = $now;
                        $hook->save();
                    } else {
                        $serviceName = trim((string) $booking->service?->name);

                        try {
                            Notification::route('mail', $email)->notify(new BookingReminderNotification(
                                $serviceName !== '' ? $serviceName : 'your booking',
                                Display::rangeWithZone($booking->starts_at, $booking->ends_at, $zone),
                                BookingReference::short($booking->id),
                                $event->offsetMinutes,
                            ));
                        } catch (Throwable $exception) {
                            $hook->delivery_status = 'failed';
                            $hook->delivery_error = 'The mailer refused the message ('.class_basename($exception).').';
                            $hook->attempted_at = $now;
                            $hook->save();
                            $reminder->dispatched_at = $now;
                            $reminder->save();

                            return;
                        }

                        $hook->delivery_status = 'sent';
                        $hook->delivery_error = null;
                        $hook->attempted_at = $now;
                        $hook->save();
                    }
                }

                $reminder->dispatched_at = $now;
                $reminder->save();
            });
        } finally {
            if ($previous instanceof Tenant) {
                $this->tenants->set($previous);
            } else {
                $this->tenants->forget();
            }
        }
    }

    private function delivers(string $mailer): bool
    {
        return $mailer !== '' && ! in_array($mailer, ['log', 'array'], true);
    }

    private function skipReason(string $mailer): string
    {
        if ($mailer === 'log') {
            return 'The log mailer does not send reminder email.';
        }

        if ($mailer === 'array') {
            return 'The array mailer does not send reminder email.';
        }

        return 'The mailer does not send reminder email.';
    }

    private function timezone(Booking $booking): string
    {
        return DisplayZone::forLocation($booking->location);
    }
}
