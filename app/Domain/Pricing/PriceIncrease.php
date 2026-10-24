<?php

namespace App\Domain\Pricing;

use App\Domain\Fees\BookingFeeQuote;
use App\Domain\Fees\PaymentFee;

/**
 * The extra amount owed after a booking's price goes up, with the fee on that
 * increase only (spec Section 7.4).
 */
final readonly class PriceIncrease
{
    /**
     * @param  list<array<string, mixed>>  $timeWindows
     * @param  list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null, quantity: int, total_cents: int}>  $operatorFeeLines
     */
    public function __construct(
        public int $servicesDeltaCents,
        public int $discountDeltaCents,
        public int $basisCents,
        public int $taxCents,
        public BookingFeeQuote $quote,
        public PaymentFee $payment,
        public int $addOnsDeltaCents = 0,
        public array $timeWindows = [],
        public int $operatorFeesDeltaCents = 0,
        public array $operatorFeeLines = [],
    ) {}
}
