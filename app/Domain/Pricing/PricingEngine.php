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
     * @param  list<array<string, mixed>>  $addOnLines
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
        $quote = $this->fees->quote($channel, $rate, $payer, new FeeBasis($services, addOnsCents: $addOnsCents, discountsCents: $discount), $tax);
        $requested = Rounding::halfUp($quote->customerTotalCents() * $depositPercent, 100);

        return new PricedBooking($services, $discount, $tax, $quote, $this->fees->firstStripePayment($quote, $requested), $promoCode, $addOnsCents, $addOnLines, $timeWindows);
    }

    /**
     * Price the booking as it stands and charge only the amount above the quoted snapshot.
     *
     * The fixed amount and the minimum were collected on the first payment, so
     * the increase uses the percentage alone. Add-on lines are part of the fee
     * basis. Tax stays on the discounted service total.
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
        int $quotedAddOnsCents = 0,
        int $addOnsCents = 0,
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
        $servicesDelta = $services - $quotedServicesCents;
        $discountDelta = $discount - $quotedDiscountCents;
        $addOnsDelta = $addOnsCents - $quotedAddOnsCents;
        $basisDelta = $servicesDelta - $discountDelta + $addOnsDelta;
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
            $addOnsDelta,
            $timeWindows,
        );
    }

    /**
     * Price the booking as it stands and measure the drop below the quoted snapshot.
     *
     * A lower tax is refunded with the subtotal. A higher tax is not collected here.
     * Add-on lines are part of the subtotal. Tax stays on the discounted service total.
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
        int $quotedAddOnsCents = 0,
        int $addOnsCents = 0,
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
        $basisDelta = ($services - $quotedServicesCents) - ($discount - $quotedDiscountCents) + ($addOnsCents - $quotedAddOnsCents);

        if ($basisDelta >= 0) {
            throw new InvalidArgumentException('This booking has no price decrease.');
        }

        $taxReduction = $tax < $quotedTaxCents ? $quotedTaxCents - $tax : 0;

        return new PriceDecrease($services, $discount, -$basisDelta, $taxReduction, $addOnsCents, $timeWindows);
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
