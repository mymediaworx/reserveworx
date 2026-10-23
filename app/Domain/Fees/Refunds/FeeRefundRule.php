<?php

namespace App\Domain\Fees\Refunds;

/**
 * One row of the booking fee refund table in spec Section 7.7.
 */
enum FeeRefundRule: string
{
    case OperatorCancels = 'operator_cancels';
    case PlatformFault = 'platform_fault';
    case CustomerFullRefund = 'customer_full_refund';
    case CustomerPartialRefund = 'customer_partial_refund';
    case CustomerNoRefund = 'customer_no_refund';
    case PriceReduction = 'price_reduction';
    case DisputeLost = 'dispute_lost';
    case DisputeWon = 'dispute_won';
    case NoShow = 'no_show';

    /**
     * The rule as published, for audit records and disclosures.
     */
    public function description(): string
    {
        return match ($this) {
            self::OperatorCancels => 'Operator cancels (including weather, safety, or a tour below its minimum): 100% of the booking fee is refunded to the tenant; the customer gets a full refund including the booking fee.',
            self::PlatformFault => 'Platform or system fault: 100% of the booking fee is refunded to the tenant; the customer gets a full refund including the booking fee.',
            self::CustomerFullRefund => 'Customer cancels with a full refund under the tenant policy: 100% of the booking fee is refunded to the tenant; the customer gets a full refund including the booking fee.',
            self::CustomerPartialRefund => 'Customer cancels with a partial refund under the tenant policy: the booking fee is refunded in proportion to the share of the subtotal refunded.',
            self::CustomerNoRefund => 'Customer cancels with no refund under the tenant policy: no booking fee is refunded.',
            self::PriceReduction => 'Booking modified to a lower price: the booking fee is refunded in proportion to the subtotal reduction.',
            self::DisputeLost => 'Dispute or chargeback lost by the tenant: 100% of the booking fee is refunded to the tenant; the customer is handled by the dispute process.',
            self::DisputeWon => 'Dispute won by the tenant: no booking fee is refunded.',
            self::NoShow => 'Customer no-show: follows the tenant no-show policy, treated as a customer cancellation.',
        };
    }
}
