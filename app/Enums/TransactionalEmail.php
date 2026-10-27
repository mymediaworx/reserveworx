<?php

namespace App\Enums;

/**
 * Transactional messages a workspace can turn on or off.
 * Kinds the booking engine already queues are included. A receipt uses
 * payment.completed, the same switch as a GoHighLevel receipt.
 */
enum TransactionalEmail: string
{
    case BookingConfirmed = 'booking.confirmed';
    case BookingCancelled = 'booking.cancelled';
    case BookingGuestCancelled = 'booking.guest_cancelled';
    case BookingGuestRescheduled = 'booking.guest_rescheduled';
    case BookingCheckedOut = 'booking.checked_out';
    case BookingReturned = 'booking.returned';
    case BookingReminder = 'booking.reminder';
    case PaymentCompleted = 'payment.completed';
    case PaymentFailed = 'payment.failed';
    case RefundIssued = 'refund.issued';
    case RentalOverdue = 'rental.overdue';
    case WaitlistJoined = 'waitlist.joined';
    case WaitlistPromoted = 'waitlist.promoted';
    case BookingFeedback = 'booking.feedback';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::cases() as $case) {
            $defaults[$case->value] = true;
        }

        return $defaults;
    }
}
