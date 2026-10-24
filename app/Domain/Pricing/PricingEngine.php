<?php

namespace App\Domain\Pricing;

use App\Domain\Fees\FeeBasis;
use App\Domain\Fees\FeeCalculator;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Fees\Rounding;
use App\Enums\FeePayer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PricingEngine
{
    public function __construct(private readonly FeeCalculator $fees) {}

    /**
     * @param  list<PriceRuleInput>  $rules
     */
    public function price(
        FeeChannel $channel,
        FeeRate $rate,
        FeePayer $payer,
        array $rules,
        int $participants,
        int $durationMinutes,
        CarbonImmutable $on,
        int $taxBasisPoints,
        ?int $percentOff,
        ?int $amountOffCents,
        ?string $promoCode,
        int $depositPercent,
    ): PricedBooking {
        if ($participants < 1) {
            throw new InvalidArgumentException('A party needs at least one person.');
        }

        if ($taxBasisPoints < 0 || $taxBasisPoints > 10_000) {
            throw new InvalidArgumentException('Tax basis points must be between 0 and 10000.');
        }

        if ($depositPercent < 1 || $depositPercent > 100) {
            throw new InvalidArgumentException('A deposit percent must be between 1 and 100.');
        }

        [$services, $discount, $tax] = $this->components(
            $rules,
            $participants,
            $durationMinutes,
            $on,
            $taxBasisPoints,
            $percentOff,
            $amountOffCents,
        );
        $quote = $this->fees->quote($channel, $rate, $payer, new FeeBasis($services, discountsCents: $discount), $tax);
        $requested = Rounding::halfUp($quote->customerTotalCents() * $depositPercent, 100);

        return new PricedBooking($services, $discount, $tax, $quote, $this->fees->firstStripePayment($quote, $requested), $promoCode);
    }

    /**
     * Price the booking as it stands and charge only the amount above the quoted snapshot.
     *
     * The fixed amount and the minimum were collected on the first payment, so
     * the increase uses the percentage alone.
     *
     * @param  list<PriceRuleInput>  $rules
     */
    public function increaseFromQuote(
        FeeChannel $channel,
        FeeRate $rate,
        FeePayer $payer,
        array $rules,
        int $participants,
        int $durationMinutes,
        CarbonImmutable $on,
        int $taxBasisPoints,
        ?int $percentOff,
        ?int $amountOffCents,
        int $quotedServicesCents,
        int $quotedDiscountCents,
        int $quotedTaxCents,
    ): PriceIncrease {
        [$services, $discount, $tax] = $this->components(
            $rules,
            $participants,
            $durationMinutes,
            $on,
            $taxBasisPoints,
            $percentOff,
            $amountOffCents,
        );
        $servicesDelta = $services - $quotedServicesCents;
        $discountDelta = $discount - $quotedDiscountCents;
        $basisDelta = $servicesDelta - $discountDelta;
        $taxDelta = $tax - $quotedTaxCents;

        if ($basisDelta <= 0) {
            throw new InvalidArgumentException('This booking has no price increase.');
        }

        if ($taxDelta < 0) {
            $taxDelta = 0;
        }

        $increase = $this->fees->quoteIncrease($channel, $rate, $payer, new FeeBasis($basisDelta), $taxDelta);

        return new PriceIncrease(
            $servicesDelta,
            $discountDelta,
            $basisDelta,
            $taxDelta,
            $increase,
            $this->fees->priceIncreaseStripePayment($increase),
        );
    }

    /**
     * Price the booking as it stands and measure the drop below the quoted snapshot.
     *
     * A lower tax is refunded with the subtotal. A higher tax is not collected here.
     *
     * @param  list<PriceRuleInput>  $rules
     */
    public function decreaseFromQuote(
        array $rules,
        int $participants,
        int $durationMinutes,
        CarbonImmutable $on,
        int $taxBasisPoints,
        ?int $percentOff,
        ?int $amountOffCents,
        int $quotedServicesCents,
        int $quotedDiscountCents,
        int $quotedTaxCents,
    ): PriceDecrease {
        [$services, $discount, $tax] = $this->components(
            $rules,
            $participants,
            $durationMinutes,
            $on,
            $taxBasisPoints,
            $percentOff,
            $amountOffCents,
        );
        $basisDelta = ($services - $quotedServicesCents) - ($discount - $quotedDiscountCents);

        if ($basisDelta >= 0) {
            throw new InvalidArgumentException('This booking has no price decrease.');
        }

        $taxReduction = $tax < $quotedTaxCents ? $quotedTaxCents - $tax : 0;

        return new PriceDecrease($services, $discount, -$basisDelta, $taxReduction);
    }

    /**
     * @param  list<PriceRuleInput>  $rules
     * @return array{0: int, 1: int, 2: int}
     */
    private function components(
        array $rules,
        int $participants,
        int $durationMinutes,
        CarbonImmutable $on,
        int $taxBasisPoints,
        ?int $percentOff,
        ?int $amountOffCents,
    ): array {
        if ($participants < 1) {
            throw new InvalidArgumentException('A party needs at least one person.');
        }

        if ($taxBasisPoints < 0 || $taxBasisPoints > 10_000) {
            throw new InvalidArgumentException('Tax basis points must be between 0 and 10000.');
        }

        $services = 0;
        $matched = false;

        foreach ($rules as $rule) {
            if (! $rule->applies($on, $durationMinutes)) {
                continue;
            }

            $matched = true;
            $services += $rule->kind === 'per_person' ? $rule->amountCents * $participants : $rule->amountCents;
        }

        if (! $matched) {
            throw new InvalidArgumentException('This service has no price for that date.');
        }

        $discount = 0;

        if ($percentOff !== null) {
            $discount = Rounding::basisPointsOf($services, $percentOff * 100);
        }

        if ($amountOffCents !== null) {
            $discount += $amountOffCents;
        }

        $discount = min($discount, $services);

        return [$services, $discount, Rounding::basisPointsOf($services - $discount, $taxBasisPoints)];
    }
}
