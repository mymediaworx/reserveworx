<?php

namespace App\Domain\Fees\Refunds;

use App\Enums\FeePayer;
use InvalidArgumentException;

/**
 * Turns each spec Section 7.7 scenario into a customer refund amount and an
 * application-fee refund amount, with the reason code and rule applied.
 *
 * Pure: no Stripe calls, no database access, integer cents only. The Stripe
 * layer executes the result using `stripeMethod`.
 */
final class FeeRefundCalculator
{
    public const RULES_VERSION = 'spec-1.0/7.7';

    /**
     * @param  int  $refundSubtotalCents  Proportional reasons only: subtotal refunded now
     *                                    (the tenant policy's share, or the price reduction).
     * @param  int  $refundTaxCents  Proportional reasons only: tax refunded with it.
     */
    public function calculate(
        FeeRefundState $state,
        RefundReason $reason,
        int $refundSubtotalCents = 0,
        int $refundTaxCents = 0,
    ): FeeRefund {
        $method = $reason->method();

        if ($method !== RefundMethod::Proportional && ($refundSubtotalCents !== 0 || $refundTaxCents !== 0)) {
            throw new InvalidArgumentException("Reason {$reason->value} does not take refund amounts.");
        }

        return match ($method) {
            RefundMethod::Full => $this->full($state, $reason),
            RefundMethod::Proportional => $this->proportional($state, $reason, $refundSubtotalCents, $refundTaxCents),
            RefundMethod::None => $this->result($state, $reason, 0, 0, 0, false),
            RefundMethod::FeeOnly => $this->result($state, $reason, 0, 0, $state->remainingFeeCents(), true),
        };
    }

    /**
     * The application fee to have refunded after `$cumulativeSubtotalCents` of the subtotal
     * has been refunded in total, rounded half-up to the cent.
     */
    public static function proportionalFeeFor(int $feeChargedCents, int $subtotalCents, int $cumulativeSubtotalCents): int
    {
        if ($feeChargedCents < 0 || $subtotalCents < 0 || $cumulativeSubtotalCents < 0) {
            throw new InvalidArgumentException('Amounts must not be negative.');
        }

        if ($cumulativeSubtotalCents > $subtotalCents) {
            throw new InvalidArgumentException('Refunded subtotal cannot exceed the subtotal.');
        }

        if ($subtotalCents === 0 || $feeChargedCents === 0) {
            return 0;
        }

        $numerator = $feeChargedCents * $cumulativeSubtotalCents;
        $quotient = intdiv($numerator, $subtotalCents);

        if (($numerator % $subtotalCents) * 2 >= $subtotalCents) {
            $quotient++;
        }

        return $quotient;
    }

    private function full(FeeRefundState $state, RefundReason $reason): FeeRefund
    {
        $fee = $state->remainingFeeCents();

        return $this->result(
            $state,
            $reason,
            $state->remainingPaidCents(),
            $state->feePayer === FeePayer::CustomerPaid ? $fee : 0,
            $fee,
            false,
        );
    }

    private function proportional(FeeRefundState $state, RefundReason $reason, int $refundSubtotalCents, int $refundTaxCents): FeeRefund
    {
        if ($refundSubtotalCents <= 0) {
            throw new InvalidArgumentException("Reason {$reason->value} needs a positive refunded subtotal.");
        }

        if ($refundTaxCents < 0) {
            throw new InvalidArgumentException('Refunded tax must not be negative.');
        }

        if ($refundSubtotalCents > $state->remainingSubtotalCents()) {
            throw new InvalidArgumentException('Refunded subtotal cannot exceed the subtotal not yet refunded.');
        }

        $target = self::proportionalFeeFor(
            $state->feeChargedCents,
            $state->subtotalCents,
            $state->refundedSubtotalCents + $refundSubtotalCents,
        );

        // A manual fee refund may already have returned more than the proportional share.
        $fee = max(0, $target - $state->refundedFeeCents);
        $customerFee = $state->feePayer === FeePayer::CustomerPaid ? $fee : 0;
        $customer = $refundSubtotalCents + $refundTaxCents + $customerFee;

        if ($customer > $state->remainingPaidCents()) {
            throw new InvalidArgumentException('Customer refund cannot exceed the amount paid and not yet refunded.');
        }

        return $this->result($state, $reason, $customer, $customerFee, $fee, false, $refundSubtotalCents, $refundTaxCents);
    }

    private function result(
        FeeRefundState $state,
        RefundReason $reason,
        int $customerCents,
        int $customerFeeCents,
        int $feeCents,
        bool $handledByDispute,
        int $refundSubtotalCents = 0,
        int $refundTaxCents = 0,
    ): FeeRefund {
        return new FeeRefund(
            reason: $reason,
            rule: $reason->rule(),
            method: $reason->method(),
            customerRefundCents: $customerCents,
            customerFeeRefundCents: $customerFeeCents,
            applicationFeeRefundCents: $feeCents,
            stripeMethod: $this->stripeMethod($state, $reason->method(), $feeCents),
            customerHandledByDispute: $handledByDispute,
            before: $state,
            refundSubtotalCents: $refundSubtotalCents,
            refundTaxCents: $refundTaxCents,
        );
    }

    private function stripeMethod(FeeRefundState $state, RefundMethod $method, int $feeCents): StripeFeeRefundMethod
    {
        if ($feeCents === 0) {
            return StripeFeeRefundMethod::None;
        }

        // Stripe's flag returns a share of the fee based on the whole charge. That only
        // matches the spec when the entire charge and the entire fee are refunded at once.
        if ($method === RefundMethod::Full && ! $state->hasPriorRefunds()) {
            return StripeFeeRefundMethod::RefundApplicationFeeFlag;
        }

        return StripeFeeRefundMethod::ApplicationFeeRefund;
    }
}
