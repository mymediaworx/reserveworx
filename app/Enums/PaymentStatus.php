<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case DepositPaid = 'deposit_paid';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
    case OfflinePaid = 'offline_paid';
}
