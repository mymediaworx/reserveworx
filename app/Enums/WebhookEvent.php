<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case BookingCreated = 'booking.created';
    case BookingCancelled = 'booking.cancelled';
    case BookingChanged = 'booking.changed';
    case PaymentSucceeded = 'payment.succeeded';
    case RefundCreated = 'refund.created';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
