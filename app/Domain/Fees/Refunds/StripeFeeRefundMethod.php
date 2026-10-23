<?php

namespace App\Domain\Fees\Refunds;

/**
 * How the Stripe layer should return the application fee (spec Section 7.7, Implementation).
 * This service never calls Stripe; it only says which mechanism matches the amounts.
 */
enum StripeFeeRefundMethod: string
{
    /** No application fee to return. */
    case None = 'none';

    /** A full refund with nothing refunded before: refund with `refund_application_fee=true`. */
    case RefundApplicationFeeFlag = 'refund_application_fee_flag';

    /**
     * Refund the customer without the flag (if there is a customer refund), then create an
     * application fee refund for exactly `applicationFeeRefundCents`. Never rely on Stripe's
     * proportional default, which is based on the whole charge including tax and the fee.
     */
    case ApplicationFeeRefund = 'application_fee_refund';
}
