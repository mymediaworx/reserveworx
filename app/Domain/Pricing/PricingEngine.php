<?php

namespace App\Domain\Pricing;

use App\Domain\Catalog\OperatorFeeCharge;
use App\Domain\Fees\FeeBasis;
use App\Domain\Fees\FeeCalculator;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Fees\Refunds\FeeRefundCalculator;
use App\Domain\Fees\Rounding;
use App\Enums\FeePayer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PricingEngine
{
    public function __construct(private readonly FeeCalculator $fees) {}

    /**
     * @param  list<PriceRuleInput>  $rules
     * @param  list<array<string, mixed>>  $addOnLines
     * @param  list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null}>  $operatorFeeDefinitions
     * @param  list<array<string, mixed>>  $locationTaxRules  Empty keeps the single rental rate in $taxBasisPoints.
     * @param  bool  $taxBookingFee  When true, a customer-paid booking fee is taxed with the rental rates.
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
        int $addOnsCents = 0,
        array $addOnLines = [],
        array $operatorFeeDefinitions = [],
        array $locationTaxRules = [],
        bool $taxBookingFee = false,
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

        if ($addOnsCents < 0) {
            throw new InvalidArgumentException('Add-ons must not be negative.');
        }

        [$services, $discount, $tax, $timeWindows] = $this->components(
            $rules,
            $participants,
            $durationMinutes,
            $on,
            $taxBasisPoints,
            $percentOff,
            $amountOffCents,
        );
        $operatorLines = OperatorFeeCharge::lines($operatorFeeDefinitions, $participants, $services);
        $operatorCents = OperatorFeeCharge::cents($operatorLines);
        $basis = new FeeBasis(
            $services,
            addOnsCents: $addOnsCents,
            mandatoryOperatorFeesCents: $operatorCents,
            discountsCents: $discount,
        );
        $preliminary = $this->resolvedTax($services, $discount, $addOnsCents, $operatorCents, $locationTaxRules, $tax, $taxBasisPoints, 0);
        $quote = $this->fees->quote($channel, $rate, $payer, $basis, $preliminary['cents']);
        $bookingFeeCents = $taxBookingFee && $payer === FeePayer::CustomerPaid ? $quote->feeCents : 0;
        $final = $bookingFeeCents > 0
            ? $this->resolvedTax($services, $discount, $addOnsCents, $operatorCents, $locationTaxRules, $tax, $taxBasisPoints, $bookingFeeCents)
            : $preliminary;

        if ($final['cents'] !== $preliminary['cents']) {
            $quote = $this->fees->quote($channel, $rate, $payer, $basis, $final['cents']);
        }

        $requested = Rounding::halfUp($quote->customerTotalCents() * $depositPercent, 100);

        return new PricedBooking(
            $services,
            $discount,
            $final['cents'],
            $quote,
            $this->fees->firstStripePayment($quote, $requested),
            $promoCode,
            $addOnsCents,
            $addOnLines,
            $timeWindows,
            $operatorCents,
            $operatorLines,
            $final['lines'] ?? [],
            $final['includes_booking_fee'],
        );
    }

    /**
     * The pre-discount service total for a party, before tax, promos, add-ons, and fees.
     *
     * @param  list<PriceRuleInput>  $rules
     */
    public function serviceCents(array $rules, int $participants, int $durationMinutes, CarbonImmutable $on): int
    {
        [$services] = $this->components($rules, $participants, $durationMinutes, $on, 0, null, null);

        return $services;
    }

    /**
     * Price the booking as it stands and charge only the amount above the quoted snapshot.
     *
     * The fixed amount and the minimum were collected on the first payment, so
     * the increase uses the percentage alone. Add-on lines are part of the fee
     * basis. With no location rates, tax stays on the discounted service total.
     * Stored location rates can also include add-ons and required fees. The
     * booking fee stays out of the fee basis. It stays out of the tax unless
     * the quote already taxed it. The new customer booking-fee line is taxed
     * with the rental rates.
     *
     * @param  list<PriceRuleInput>  $rules
     * @param  list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null}>  $operatorFeeDefinitions
     * @param  list<array<string, mixed>>  $locationTaxRules
     * @param  bool  $taxBookingFee  True when the stored quote taxed the customer booking fee.
     * @param  int  $quotedCustomerFeeCents  Customer booking-fee line already on that quote.
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
        int $quotedAddOnsCents = 0,
        int $addOnsCents = 0,
        int $quotedOperatorFeesCents = 0,
        array $operatorFeeDefinitions = [],
        array $locationTaxRules = [],
        bool $taxBookingFee = false,
        int $quotedCustomerFeeCents = 0,
    ): PriceIncrease {
        if ($quotedAddOnsCents < 0 || $addOnsCents < 0) {
            throw new InvalidArgumentException('Add-ons must not be negative.');
        }

        [$services, $discount, $tax, $timeWindows] = $this->components(
            $rules,
            $participants,
            $durationMinutes,
            $on,
            $taxBasisPoints,
            $percentOff,
            $amountOffCents,
        );
        $operatorLines = OperatorFeeCharge::lines($operatorFeeDefinitions, $participants, $services);
        $operatorCents = OperatorFeeCharge::cents($operatorLines);
        $servicesDelta = $services - $quotedServicesCents;
        $discountDelta = $discount - $quotedDiscountCents;
        $addOnsDelta = $addOnsCents - $quotedAddOnsCents;
        $operatorDelta = $operatorCents - $quotedOperatorFeesCents;
        $basisDelta = $servicesDelta - $discountDelta + $addOnsDelta + $operatorDelta;

        if ($basisDelta <= 0) {
            throw new InvalidArgumentException('This booking has no price increase.');
        }

        $bookingFeeCents = 0;

        if ($taxBookingFee && $payer === FeePayer::CustomerPaid) {
            $preview = $this->fees->quoteIncrease($channel, $rate, $payer, new FeeBasis($basisDelta), 0);
            $bookingFeeCents = $quotedCustomerFeeCents + $preview->feeCents;
        }

        $final = $this->resolvedTax($services, $discount, $addOnsCents, $operatorCents, $locationTaxRules, $tax, $taxBasisPoints, $bookingFeeCents);
        $taxDelta = $final['cents'] - $quotedTaxCents;

        if ($taxDelta < 0) {
            $taxDelta = 0;
        }

        $replacement = $final['lines'] !== null && $final['cents'] > $quotedTaxCents ? $final['lines'] : null;
        $increase = $this->fees->quoteIncrease($channel, $rate, $payer, new FeeBasis($basisDelta), $taxDelta);

        return new PriceIncrease(
            $servicesDelta,
            $discountDelta,
            $basisDelta,
            $taxDelta,
            $increase,
            $this->fees->priceIncreaseStripePayment($increase),
            $addOnsDelta,
            $timeWindows,
            $operatorDelta,
            $operatorLines,
            $replacement,
        );
    }

    /**
     * Price the booking as it stands and measure the drop below the quoted snapshot.
     *
     * A lower tax is refunded with the subtotal. A higher tax is not collected here.
     * Add-on lines are part of the subtotal. With no location rates, tax stays
     * on the discounted service total. Stored location rates can also include
     * add-ons and required fees. The booking fee stays out of the tax unless
     * the quote already taxed it. The share that remains is taxed with the
     * rental rates, using the same proportion as the fee refund.
     *
     * @param  list<PriceRuleInput>  $rules
     * @param  list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null}>  $operatorFeeDefinitions
     * @param  list<array<string, mixed>>  $locationTaxRules
     * @param  bool  $taxBookingFee  True when the stored quote taxed the customer booking fee.
     * @param  int  $quotedCustomerFeeCents  Customer booking-fee line already on that quote.
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
        int $quotedAddOnsCents = 0,
        int $addOnsCents = 0,
        int $quotedOperatorFeesCents = 0,
        array $operatorFeeDefinitions = [],
        array $locationTaxRules = [],
        bool $taxBookingFee = false,
        int $quotedCustomerFeeCents = 0,
    ): PriceDecrease {
        if ($quotedAddOnsCents < 0 || $addOnsCents < 0) {
            throw new InvalidArgumentException('Add-ons must not be negative.');
        }

        [$services, $discount, $tax, $timeWindows] = $this->components(
            $rules,
            $participants,
            $durationMinutes,
            $on,
            $taxBasisPoints,
            $percentOff,
            $amountOffCents,
        );
        $operatorLines = OperatorFeeCharge::lines($operatorFeeDefinitions, $participants, $services);
        $operatorCents = OperatorFeeCharge::cents($operatorLines);
        $basisDelta = ($services - $quotedServicesCents)
            - ($discount - $quotedDiscountCents)
            + ($addOnsCents - $quotedAddOnsCents)
            + ($operatorCents - $quotedOperatorFeesCents);

        if ($basisDelta >= 0) {
            throw new InvalidArgumentException('This booking has no price decrease.');
        }

        $bookingFeeCents = 0;

        if ($taxBookingFee && $quotedCustomerFeeCents > 0) {
            $quotedBasis = max(0, $quotedServicesCents - $quotedDiscountCents + $quotedAddOnsCents + $quotedOperatorFeesCents);
            $share = min(-$basisDelta, $quotedBasis);
            $refundedFee = $quotedBasis > 0
                ? FeeRefundCalculator::proportionalFeeFor($quotedCustomerFeeCents, $quotedBasis, $share)
                : 0;
            $bookingFeeCents = max(0, $quotedCustomerFeeCents - $refundedFee);
        }

        $final = $this->resolvedTax($services, $discount, $addOnsCents, $operatorCents, $locationTaxRules, $tax, $taxBasisPoints, $bookingFeeCents);
        $taxReduction = $final['cents'] < $quotedTaxCents ? $quotedTaxCents - $final['cents'] : 0;
        $replacement = $final['lines'] !== null && $final['cents'] < $quotedTaxCents ? $final['lines'] : null;

        return new PriceDecrease($services, $discount, -$basisDelta, $taxReduction, $addOnsCents, $timeWindows, $operatorCents, $operatorLines, $replacement);
    }

    /**
     * Location rates replace the single rental rate. Null keeps that rate.
     *
     * @param  list<array<string, mixed>>  $locationTaxRules
     * @return array{cents: int, lines: list<array<string, mixed>>}|null
     */
    private function locationTax(int $services, int $discount, int $addOnsCents, int $operatorCents, array $locationTaxRules, int $bookingFeeCents = 0): ?array
    {
        if ($locationTaxRules === []) {
            return null;
        }

        return LocationTax::assess($locationTaxRules, max(0, $services - $discount), $addOnsCents, $operatorCents, $bookingFeeCents);
    }

    /**
     * Tax for the current price. A customer booking fee is included only when
     * the caller passes one. Location rates that apply to rentals take it.
     * With no location rates, the single rental rate takes it.
     *
     * @param  list<array<string, mixed>>  $locationTaxRules
     * @return array{cents: int, lines: list<array<string, mixed>>|null, includes_booking_fee: bool}
     */
    private function resolvedTax(
        int $services,
        int $discount,
        int $addOnsCents,
        int $operatorCents,
        array $locationTaxRules,
        int $singleRateTaxCents,
        int $taxBasisPoints,
        int $bookingFeeCents,
    ): array {
        if ($bookingFeeCents < 0) {
            throw new InvalidArgumentException('Tax bases must not be negative.');
        }

        $assessed = $this->locationTax($services, $discount, $addOnsCents, $operatorCents, $locationTaxRules, $bookingFeeCents);

        if ($assessed !== null) {
            return [
                'cents' => $assessed['cents'],
                'lines' => $assessed['lines'],
                'includes_booking_fee' => $bookingFeeCents > 0 && $this->rentalRateExists($locationTaxRules),
            ];
        }

        $cents = $singleRateTaxCents;

        if ($bookingFeeCents > 0 && $taxBasisPoints > 0) {
            $cents += Rounding::basisPointsOf($bookingFeeCents, $taxBasisPoints);
        }

        return [
            'cents' => $cents,
            'lines' => null,
            'includes_booking_fee' => $bookingFeeCents > 0 && $taxBasisPoints > 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    private function rentalRateExists(array $rules): bool
    {
        foreach ($rules as $rule) {
            $applies = $rule['applies_to_rentals'] ?? false;

            if ($applies === true || $applies === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PriceRuleInput>  $rules
     * @return array{0: int, 1: int, 2: int, 3: list<array<string, mixed>>}
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
        $timeWindows = [];
        $peakPresent = false;

        foreach ($rules as $rule) {
            if ($rule->hasTimeWindow()) {
                $peakPresent = true;
                break;
            }
        }

        if ($peakPresent) {
            [$services, $matched, $timeWindows] = $this->pricedWithPeaks($rules, $participants, $durationMinutes, $on);
        } else {
            foreach ($rules as $rule) {
                if (! $rule->applies($on, $durationMinutes)) {
                    continue;
                }

                $matched = true;
                $services += $rule->kind === 'per_person' ? $rule->amountCents * $participants : $rule->amountCents;
            }
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

        return [$services, $discount, Rounding::basisPointsOf($services - $discount, $taxBasisPoints), $timeWindows];
    }

    /**
     * Spec Section 7.1 names peak rates and does not say how a booking that
     * crosses the window is priced. Each rate is still a price for the whole
     * booking. Minutes inside the winning clock window use that rate. The other
     * minutes use the rates with no clock. One half-up average is the charge.
     * A per-person rate is multiplied by the party size before that rounding.
     *
     * @param  list<PriceRuleInput>  $rules
     * @return array{0: int, 1: bool, 2: list<array<string, mixed>>}
     */
    private function pricedWithPeaks(array $rules, int $participants, int $durationMinutes, CarbonImmutable $on): array
    {
        if ($durationMinutes < 1) {
            throw new InvalidArgumentException('Choose a valid interval.');
        }

        /** @var array<string, list<array{index: int, rule: PriceRuleInput}>> $kinds */
        $kinds = ['base' => [], 'per_person' => [], 'duration' => []];

        foreach ($rules as $index => $rule) {
            $kinds[$rule->kind][] = ['index' => $index, 'rule' => $rule];
        }

        $services = 0;
        $matched = false;
        $windows = [];
        $startUtc = $on->utc();
        $timezone = $on->getTimezone();

        foreach ($kinds as $kind => $indexed) {
            $openAmount = 0;
            $openMatched = false;
            $peaks = [];

            foreach ($indexed as $row) {
                $rule = $row['rule'];

                if ($rule->hasTimeWindow()) {
                    if ($rule->kind === 'duration' && $rule->durationMinutes !== $durationMinutes) {
                        continue;
                    }

                    $peaks[] = $row;

                    continue;
                }

                if ($rule->applies($on, $durationMinutes)) {
                    $openMatched = true;
                    $openAmount += $rule->amountCents;
                }
            }

            if ($peaks === []) {
                if (! $openMatched) {
                    continue;
                }

                $matched = true;
                $services += $kind === 'per_person' ? $openAmount * $participants : $openAmount;

                continue;
            }

            $numerator = 0;
            $peakMinutes = [];

            foreach ($peaks as $position => $row) {
                $peakMinutes[$position] = 0;
            }

            $pricedMinutes = 0;

            for ($minute = 0; $minute < $durationMinutes; $minute++) {
                $local = $startUtc->addMinutes($minute)->timezone($timezone);
                $winner = $this->winningPeak($peaks, $local);

                if ($winner !== null) {
                    $numerator += $peaks[$winner]['rule']->amountCents;
                    $peakMinutes[$winner]++;
                    $pricedMinutes++;

                    continue;
                }

                if ($openMatched) {
                    $numerator += $openAmount;
                    $pricedMinutes++;
                }
            }

            if ($pricedMinutes === 0) {
                continue;
            }

            $matched = true;
            $services += $kind === 'per_person'
                ? Rounding::halfUp($numerator * $participants, $durationMinutes)
                : Rounding::halfUp($numerator, $durationMinutes);

            foreach ($peaks as $position => $row) {
                if ($peakMinutes[$position] < 1) {
                    continue;
                }

                $windows[] = $row['rule']->timeWindowSnapshot($peakMinutes[$position]);
            }
        }

        return [$services, $matched, $windows];
    }

    /**
     * @param  list<array{index: int, rule: PriceRuleInput}>  $peaks
     */
    private function winningPeak(array $peaks, CarbonImmutable $local): ?int
    {
        $winner = null;
        $bestSpecificity = -1;
        $bestIndex = PHP_INT_MAX;

        foreach ($peaks as $position => $row) {
            if (! $row['rule']->covers($local)) {
                continue;
            }

            $specificity = $row['rule']->specificity();
            $index = $row['index'];

            if ($winner === null || $specificity > $bestSpecificity || ($specificity === $bestSpecificity && $index < $bestIndex)) {
                $winner = $position;
                $bestSpecificity = $specificity;
                $bestIndex = $index;
            }
        }

        return $winner;
    }
}
