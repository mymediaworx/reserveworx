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
    public function __construct(
        public int $servicesDeltaCents,
        public int $discountDeltaCents,
        public int $basisCents,
        public int $taxCents,
        public BookingFeeQuote $quote,
        public PaymentFee $payment,
        public int $addOnsDeltaCents = 0,
    ) {}
}
