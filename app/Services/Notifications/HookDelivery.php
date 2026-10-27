<?php

namespace App\Services\Notifications;

use App\Models\Booking;
use App\Models\NotificationHook;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Models\TenantSetting;
use App\Notifications\BookingUpdateNotification;
use App\Services\Branding\CurrentWorkspaceBranding;
use App\Services\Integrations\GhlMessages;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Emails queued customer hooks for the current workspace, through the mailer the
 * deploy configured. Kinds with their own sender (confirmation, reminders,
 * feedback) and staff-only kinds are recorded as not emailed. Each hook ends
 * `sent`, `skipped` with the reason, or `failed`. PHP mail() is not used.
 */
final class HookDelivery
{
    public const NO_PROVIDER = 'No transactional email provider is configured.';

    public const RECEIPT_OFF = 'The workspace does not email payment receipts.';

    /**
     * A hook left queued longer than this (for example while cron was not
     * running) is not emailed, so a customer never gets a stale message.
     */
    public const MAX_AGE_HOURS = 24;

    public function __construct(
        private readonly TenantManager $tenants,
        private readonly TransactionalEmailPreferences $preferences,
        private readonly BookingUpdateMail $mail,
        private readonly GhlMessages $ghl,
    ) {}

    public function deliverQueued(): int
    {
        $tenant = $this->tenants->get();

        if (! $tenant instanceof Tenant) {
            throw new TenantContextException('Refusing to deliver notifications without a workspace.');
        }

        $hooks = NotificationHook::query()
            ->where('delivery_status', 'queued')
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($hooks as $hook) {
            if ($hook->tenant_id !== $tenant->id) {
                throw new TenantContextException('Refusing to deliver a notification for a different workspace.');
            }

            [$status, $error] = $this->deliver($hook, $tenant);
            $hook->delivery_status = $status;
            $hook->delivery_error = $error;
            $hook->attempted_at = Carbon::now('UTC');
            $hook->save();
        }

        return $hooks->count();
    }

    /**
     * @return array{0: 'sent'|'skipped'|'failed', 1: string|null}
     */
    private function deliver(NotificationHook $hook, Tenant $tenant): array
    {
        if ($hook->kind === 'payment.completed' && ! $this->paymentReceiptsEnabled($tenant)) {
            return ['skipped', self::RECEIPT_OFF];
        }

        if (! $this->canSend()) {
            return ['skipped', self::NO_PROVIDER];
        }

        if (! BookingUpdateMail::handles($hook->kind)) {
            return ['skipped', 'Not emailed to the customer from this queue.'];
        }

        if ($hook->created_at !== null && $hook->created_at->lessThan(Carbon::now()->subHours(self::MAX_AGE_HOURS))) {
            return ['skipped', 'Too old to send.'];
        }

        if ($this->ghl->handlesMessages($tenant->id)) {
            return ['skipped', 'Sent through GoHighLevel.'];
        }

        if (! $this->preferences->enabled($tenant->id, BookingUpdateMail::KINDS[$hook->kind]->value)) {
            return ['skipped', 'The workspace turned this message off.'];
        }

        $booking = $hook->booking_id !== null
            ? Booking::query()->with(['customer', 'service', 'location'])->find($hook->booking_id)
            : null;
        $email = $booking?->customer?->email;

        if (! $booking instanceof Booking || ! is_string($email) || $email === '') {
            return ['skipped', 'Customer has no email.'];
        }

        $message = $this->mail->compose($hook, $booking, $this->businessName($tenant));

        if ($message === null) {
            return ['skipped', 'Nothing to tell the customer.'];
        }

        try {
            Notification::route('mail', $email)->notify(new BookingUpdateNotification(
                $message['subject'],
                $this->businessName($tenant),
                $message['lines'],
                $this->replyTo($tenant),
            ));
        } catch (Throwable $exception) {
            return ['failed', 'The mailer refused the message ('.class_basename($exception).').'];
        }

        return ['sent', null];
    }

    private function paymentReceiptsEnabled(Tenant $tenant): bool
    {
        $setting = TenantSetting::query()->where('tenant_id', $tenant->id)->first();

        return $setting instanceof TenantSetting && $setting->email_payment_receipt;
    }

    private function canSend(): bool
    {
        $mailer = config('mail.default');

        return is_string($mailer) && $mailer !== '' && ! in_array($mailer, ['log', 'array'], true);
    }

    private function businessName(Tenant $tenant): string
    {
        $branding = app(CurrentWorkspaceBranding::class)->resolve();

        return $branding->displayName !== '' ? $branding->displayName : $tenant->name;
    }

    private function replyTo(Tenant $tenant): ?string
    {
        $setting = TenantNotificationSetting::query()->where('tenant_id', $tenant->id)->first();
        $replyTo = $setting instanceof TenantNotificationSetting ? $setting->reply_to : null;

        return is_string($replyTo) && $replyTo !== '' ? $replyTo : null;
    }
}
