<?php

namespace App\Services\Notifications;

use App\Enums\BookingStatus;
use App\Enums\GhlMessageKind;
use App\Models\Booking;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\RentalCheckout;
use App\Services\Feedback\FeedbackRequests;
use App\Services\Integrations\GhlMessages;
use App\Services\Integrations\GhlSync;
use App\Tenancy\TenantScope;

/**
 * Queues the notification hooks staff and guest flows already perform.
 * A confirmed booking also sends its email when a delivering mailer is configured.
 * A return records one feedback request. That mail waits for feedback:dispatch.
 */
final class BookingEvents
{
    public function __construct(
        private readonly NotificationHooks $hooks,
        private readonly FeedbackRequests $feedback,
        private readonly BookingConfirmationMail $confirmationMail,
        private readonly GhlMessages $ghlMessages,
        private readonly GhlSync $ghlSync,
    ) {}

    public function confirmed(Booking $booking): void
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            return;
        }

        $this->hooks->record(
            $booking->tenant_id,
            $booking->id,
            'booking.confirmed',
            'booking.confirmed:'.$booking->id,
            ['booking_id' => $booking->id],
        );

        if (! $this->ghlMessages->queueBooking($booking, GhlMessageKind::Confirmed)) {
            $this->confirmationMail->send($booking);
        }
    }

    public function cancelled(Booking $booking): void
    {
        $this->hooks->record(
            $booking->tenant_id,
            $booking->id,
            'booking.cancelled',
            'booking.cancelled:'.$booking->id,
            ['booking_id' => $booking->id],
        );
        $this->ghlMessages->queueBooking($booking, GhlMessageKind::Cancelled);
    }

    public function checkedOut(Booking $booking): void
    {
        $this->hooks->record(
            $booking->tenant_id,
            $booking->id,
            'booking.checked_out',
            'booking.checked_out:'.$booking->id,
            ['booking_id' => $booking->id],
        );
    }

    /**
     * @param  list<array{asset_id: string|null, condition: string, notes: ?string, recorded_by: string|null, pool_id?: string|null, minutes_late?: int}>  $conditions
     */
    public function returned(Booking $booking, array $conditions = []): void
    {
        $this->hooks->record(
            $booking->tenant_id,
            $booking->id,
            'booking.returned',
            'booking.returned:'.$booking->id,
            [
                'booking_id' => $booking->id,
                'conditions' => $conditions,
            ],
        );
        $this->feedback->queue($booking, true);
    }

    public function paymentCompleted(Payment $payment): void
    {
        $this->hooks->record(
            $payment->tenant_id,
            $payment->booking_id,
            'payment.completed',
            'payment.completed:'.$payment->id,
            [
                'booking_id' => $payment->booking_id,
                'payment_id' => $payment->id,
                'method' => $payment->method,
                'amount_cents' => $payment->amount_cents,
            ],
        );
        $this->ghlMessages->queueReceipt($payment);
    }

    public function paymentFailed(string $tenantId, string $bookingId, string $dedupeKey, ?string $paymentId = null): void
    {
        $alreadyRecorded = NotificationHook::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('dedupe_key', $dedupeKey)
            ->exists();

        $this->hooks->record(
            $tenantId,
            $bookingId,
            'payment.failed',
            $dedupeKey,
            ['booking_id' => $bookingId],
        );

        if ($alreadyRecorded) {
            return;
        }

        $this->ghlSync->queue($tenantId, $bookingId, 'payment.failed', $paymentId);
    }

    public function overdue(RentalCheckout $checkout): void
    {
        $this->hooks->record(
            $checkout->tenant_id,
            $checkout->booking_id,
            'rental.overdue',
            'rental.overdue:'.$checkout->id,
            [
                'booking_id' => $checkout->booking_id,
                'checkout_id' => $checkout->id,
                'due_at' => $checkout->due_at->toIso8601String(),
            ],
        );
        $this->ghlMessages->queueOverdue($checkout);
    }
}
