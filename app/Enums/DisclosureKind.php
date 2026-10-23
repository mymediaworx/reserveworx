<?php

namespace App\Enums;

enum DisclosureKind: string
{
    /** Plain-language booking fee refund terms (spec Section 7.6 item 4, generated from Section 7.7). */
    case BookingFeeRefundTerms = 'booking_fee_refund_terms';
}
