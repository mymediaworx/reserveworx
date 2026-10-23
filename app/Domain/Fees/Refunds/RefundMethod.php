<?php

namespace App\Domain\Fees\Refunds;

/**
 * How a reason code turns into amounts.
 */
enum RefundMethod: string
{
    /** Everything still refundable: the customer's payment and the whole remaining fee. */
    case Full = 'full';

    /** A share of the subtotal; the fee follows in the same proportion. */
    case Proportional = 'proportional';

    /** Nothing is refunded. */
    case None = 'none';

    /** The whole remaining fee returns to the tenant; the customer is handled by the dispute process. */
    case FeeOnly = 'fee_only';
}
