<?php

namespace App\Enums;

/**
 * Booking communications spec Section 10 sends through GoHighLevel.
 * A receipt uses the payment-completed preference because that is the switch
 * for the payment message.
 */
enum GhlMessageKind: string
{
    case Confirmed = 'booking.confirmed';
    case Receipt = 'payment.receipt';
    case Reminder = 'booking.reminder';
    case Cancelled = 'booking.cancelled';
    case GuestCancelled = 'booking.guest_cancelled';
    case Rescheduled = 'booking.guest_rescheduled';
    case PaymentFailed = 'payment.failed';
    case RefundIssued = 'refund.issued';
    case Overdue = 'rental.overdue';

    public function preference(): string
    {
        return match ($this) {
            self::Receipt => TransactionalEmail::PaymentCompleted->value,
            self::Confirmed => TransactionalEmail::BookingConfirmed->value,
            self::Reminder => TransactionalEmail::BookingReminder->value,
            self::Cancelled => TransactionalEmail::BookingCancelled->value,
            self::GuestCancelled => TransactionalEmail::BookingGuestCancelled->value,
            self::Rescheduled => TransactionalEmail::BookingGuestRescheduled->value,
            self::PaymentFailed => TransactionalEmail::PaymentFailed->value,
            self::RefundIssued => TransactionalEmail::RefundIssued->value,
            self::Overdue => TransactionalEmail::RentalOverdue->value,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmation',
            self::Receipt => 'Receipt',
            self::Reminder => 'Reminder',
            self::Cancelled, self::GuestCancelled => 'Cancellation',
            self::Rescheduled => 'New time',
            self::PaymentFailed => 'Payment failed',
            self::RefundIssued => 'Refund',
            self::Overdue => 'Overdue rental',
        };
    }
}
