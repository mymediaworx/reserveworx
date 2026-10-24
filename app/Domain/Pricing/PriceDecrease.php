<?php

namespace App\Domain\Pricing;

/**
 * How far a booking's current price sits below the quoted snapshot.
 *
 * The fee refund is not calculated here. Section 7.7 returns a share of the
 * fee already charged, in proportion to this subtotal reduction.
 */
final readonly class PriceDecrease
{
    public function __construct(
        public int $servicesCents,
        public int $discountCents,
        public int $basisReductionCents,
        public int $taxReductionCents,
    ) {}
}
