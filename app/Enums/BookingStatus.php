<?php

namespace App\Enums;

enum BookingStatus: string
{
    case PendingPayment = 'pending_payment';
    case Held = 'held';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case PaymentException = 'payment_exception';

    /**
     * A hold that still occupies inventory. Pending payment is that same hold
     * after a card checkout has started.
     */
    public function isOpenHold(): bool
    {
        return $this === self::Held || $this === self::PendingPayment;
    }
}
