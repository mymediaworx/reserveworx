<?php

namespace App\Services\Notifications;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RentalCheckout;

/**
 * Queues the notification hooks staff and guest flows already perform.
 * Nothing here sends mail or calls GoHighLevel.
 */
final class BookingEvents
{
    public function __construct(private readonly NotificationHooks $hooks) {}

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

    public function returned(Booking $booking): void
    {
        $this->hooks->record(
            $booking->tenant_id,
            $booking->id,
            'booking.returned',
            'booking.returned:'.$booking->id,
            ['booking_id' => $booking->id],
        );
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
