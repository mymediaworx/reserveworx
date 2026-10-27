<?php

namespace App\Support;

use App\Domain\Fees\FeeChannel;
use App\Models\PlatformFeeRefund;

/**
 * Words for the stored booking-fee ledger. The text comes from the row already
 * saved. It does not choose a refund rule or a new amount.
 */
final class FeeLedgerCopy
{
    public static function channel(string $channel): string
    {
        return match ($channel) {
            FeeChannel::Direct->value => 'Direct',
            FeeChannel::ThirdParty->value => 'Third-party',
            default => 'Direct',
        };
    }

    public static function reason(string $reason, string $rule): string
    {
        if ($rule === PlatformFeeRefund::RULE) {
            $text = trim($reason);

            return $text !== '' ? $text : 'Manual fee refund';
        }

        return match ($reason) {
            'operator_cancelled' => 'Operator cancelled',
            'platform_oversell' => 'Oversold',
            'platform_payment_after_hold_expiry' => 'Paid after the hold ended',
            'platform_duplicate_charge' => 'Duplicate charge',
            'platform_error' => 'Platform error',
            'customer_cancelled_full_refund' => 'Customer cancelled, full refund',
            'customer_cancelled_partial_refund' => 'Customer cancelled, partial refund',
            'customer_cancelled_no_refund' => 'Customer cancelled, no refund',
            'price_reduced' => 'Price reduced',
            'dispute_lost' => 'Dispute lost',
            'dispute_won' => 'Dispute won',
            'no_show_full_refund' => 'No-show, full refund',
            'no_show_partial_refund' => 'No-show, partial refund',
            'no_show_no_refund' => 'No-show, no refund',
            default => ($text = trim($reason)) !== '' ? $text : 'Fee refund',
        };
    }
}
