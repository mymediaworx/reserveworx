<?php

namespace App\Domain\Fees\Refunds;

/**
 * Reason code recorded on every fee refund (spec Section 7.7). Each code maps
 * to exactly one row of the refund table and one calculation method.
 */
enum RefundReason: string
{
    case OperatorCancelled = 'operator_cancelled';

    case PlatformOversell = 'platform_oversell';
    case PlatformPaymentAfterHoldExpiry = 'platform_payment_after_hold_expiry';
    case PlatformDuplicateCharge = 'platform_duplicate_charge';
    case PlatformError = 'platform_error';

    case CustomerCancelledFullRefund = 'customer_cancelled_full_refund';
    case CustomerCancelledPartialRefund = 'customer_cancelled_partial_refund';
    case CustomerCancelledNoRefund = 'customer_cancelled_no_refund';

    case PriceReduced = 'price_reduced';

    case DisputeLost = 'dispute_lost';
    case DisputeWon = 'dispute_won';

    case NoShowFullRefund = 'no_show_full_refund';
    case NoShowPartialRefund = 'no_show_partial_refund';
    case NoShowNoRefund = 'no_show_no_refund';

    public function rule(): FeeRefundRule
    {
        return match ($this) {
            self::OperatorCancelled => FeeRefundRule::OperatorCancels,
            self::PlatformOversell,
            self::PlatformPaymentAfterHoldExpiry,
            self::PlatformDuplicateCharge,
            self::PlatformError => FeeRefundRule::PlatformFault,
            self::CustomerCancelledFullRefund => FeeRefundRule::CustomerFullRefund,
            self::CustomerCancelledPartialRefund => FeeRefundRule::CustomerPartialRefund,
            self::CustomerCancelledNoRefund => FeeRefundRule::CustomerNoRefund,
            self::PriceReduced => FeeRefundRule::PriceReduction,
            self::DisputeLost => FeeRefundRule::DisputeLost,
            self::DisputeWon => FeeRefundRule::DisputeWon,
            self::NoShowFullRefund,
            self::NoShowPartialRefund,
            self::NoShowNoRefund => FeeRefundRule::NoShow,
        };
    }

    public function method(): RefundMethod
    {
        return match ($this) {
            self::OperatorCancelled,
            self::PlatformOversell,
            self::PlatformPaymentAfterHoldExpiry,
            self::PlatformDuplicateCharge,
            self::PlatformError,
            self::CustomerCancelledFullRefund,
            self::NoShowFullRefund => RefundMethod::Full,
            self::CustomerCancelledPartialRefund,
            self::PriceReduced,
            self::NoShowPartialRefund => RefundMethod::Proportional,
            self::CustomerCancelledNoRefund,
            self::DisputeWon,
            self::NoShowNoRefund => RefundMethod::None,
            self::DisputeLost => RefundMethod::FeeOnly,
        };
    }
}
