<?php

namespace App\Domain\Fees;

use App\Enums\FeePayer;
use InvalidArgumentException;

/**
 * Platform booking fee engine for spec Sections 7.4 and 7.5.
 *
 * Pure: no Stripe calls, no database access, integer cents only. Callers
 * load the tenant's rate and fee payer, build the fee basis from the
 * booking's line items, and persist the results in the pricing snapshot.
 */
final class FeeCalculator
{
    /** Recorded in pricing snapshots so later rule changes are traceable. */
    public const RULES_VERSION = 'spec-1.0/7.4-7.5';

    /** Spec 7.4: a deposit that is not more than the fee is raised to at least fee + $1.00. */
    public const DEPOSIT_MARGIN_CENTS = 100;

    /**
     * The fee on a basis: percentage (half-up to the cent), plus the fixed
     * amount if enabled, then raised to the minimum if enabled.
     *
     * A zero basis carries no fee: there is nothing to charge a fee on, and a
     * fixed amount or minimum must not create a fee on a free booking.
     */
    public function feeFor(FeeRate $rate, int $basisCents): int
    {
        if ($basisCents < 0) {
            throw new InvalidArgumentException('Fee basis must not be negative.');
        }

        if ($basisCents === 0) {
            return 0;
        }

        $fee = Rounding::basisPointsOf($basisCents, $rate->basisPoints) + $rate->fixedCents;

        if ($rate->minimumCents !== null) {
            $fee = max($fee, $rate->minimumCents);
        }

        return $fee;
    }

    /**
     * The booking fee for a booking's price and what the customer owes.
     */
    public function quote(FeeChannel $channel, FeeRate $rate, FeePayer $payer, FeeBasis $basis, int $taxCents = 0): BookingFeeQuote
    {
        $this->assertNotNegative($taxCents, 'Tax');

        $basisCents = $basis->cents();

        return new BookingFeeQuote(
            channel: $channel,
            rate: $rate,
            payer: $payer,
            basisCents: $basisCents,
            taxCents: $taxCents,
            feeCents: $this->feeFor($rate, $basisCents),
        );
    }

    /**
     * The fee on a price increase that will be paid through Stripe.
     *
     * Only the percentage is applied to the increase. The fixed amount and the
     * minimum are per-booking and were collected with the first payment, so
     * they are not charged again. The returned quote records the rate actually
     * applied so the snapshot shows it.
     */
    public function quoteIncrease(FeeChannel $channel, FeeRate $rate, FeePayer $payer, FeeBasis $increase, int $increaseTaxCents = 0): BookingFeeQuote
    {
        if ($increase->cents() <= 0) {
            throw new InvalidArgumentException('A price increase must be positive.');
        }

        return $this->quote($channel, new FeeRate($rate->basisPoints), $payer, $increase, $increaseTaxCents);
    }

    /**
     * The first Stripe payment for a booking, whether a deposit or the full amount.
     *
     * It carries the full booking fee. A deposit that is not more than the fee
     * is raised to fee + $1.00, capped at the customer total.
     */
    public function firstStripePayment(BookingFeeQuote $quote, int $requestedCents): PaymentFee
    {
        $total = $quote->customerTotalCents();
        $this->assertPaymentWithin($requestedCents, $total);

        $fee = $quote->feeCents;

        if ($fee === 0) {
            return new PaymentFee(PaymentKind::FirstStripePayment, $requestedCents, $requestedCents, 0);
        }

        if ($fee >= $total) {
            throw FeeExceedsChargeException::for($fee, $total);
        }

        $charge = $requestedCents;

        if ($charge <= $fee) {
            $charge = min($fee + self::DEPOSIT_MARGIN_CENTS, $total);
        }

        return new PaymentFee(PaymentKind::FirstStripePayment, $requestedCents, $charge, $fee);
    }

    /**
     * A later Stripe payment toward the same booking price. The fee was already
     * collected on the first payment, so none is added.
     */
    public function balanceStripePayment(int $amountCents): PaymentFee
    {
        $this->assertPositive($amountCents);

        return new PaymentFee(PaymentKind::BalanceStripePayment, $amountCents, $amountCents, 0);
    }

    /**
     * A Stripe payment for a price increase, charged in full with the fee on the increase.
     */
    public function priceIncreaseStripePayment(BookingFeeQuote $increaseQuote): PaymentFee
    {
        $charge = $increaseQuote->customerTotalCents();
        $fee = $increaseQuote->feeCents;

        if ($fee > 0 && $fee >= $charge) {
            throw FeeExceedsChargeException::for($fee, $charge);
        }

        return new PaymentFee(PaymentKind::PriceIncreaseStripePayment, $charge, $charge, $fee);
    }

    /**
     * Cash, check, external terminal, or bank transfer. No fee (D8).
     */
    public function offlinePayment(int $amountCents): PaymentFee
    {
        $this->assertPositive($amountCents);

        return new PaymentFee(PaymentKind::Offline, $amountCents, $amountCents, 0);
    }

    private function assertPaymentWithin(int $amountCents, int $totalCents): void
    {
        $this->assertPositive($amountCents);

        if ($amountCents > $totalCents) {
            throw new InvalidArgumentException('Payment cannot exceed the customer total.');
        }
    }

    private function assertPositive(int $amountCents): void
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }
    }

    private function assertNotNegative(int $value, string $label): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("{$label} must not be negative.");
        }
    }
}
