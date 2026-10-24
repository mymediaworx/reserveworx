<?php

namespace App\Services\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RentalCheckout;
use App\Services\Feedback\FeedbackRequests;

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
        $this->confirmationMail->send($booking);
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
     * @param  list<array{asset_id: string, condition: string, notes: ?string, recorded_by: string}>  $conditions
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
    }

    public function paymentFailed(string $tenantId, string $bookingId, string $dedupeKey): void
    {
        $this->hooks->record(
            $tenantId,
            $bookingId,
            'payment.failed',
            $dedupeKey,
            ['booking_id' => $bookingId],
        );
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
    }
}
