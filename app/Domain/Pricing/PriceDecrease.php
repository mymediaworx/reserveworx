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
    /**
     * @param  list<array<string, mixed>>  $timeWindows
     * @param  list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null, quantity: int, total_cents: int}>  $operatorFeeLines
     */
    public function __construct(
        public int $servicesCents,
        public int $discountCents,
        public int $basisReductionCents,
        public int $taxReductionCents,
        public int $addOnsCents,
        public array $timeWindows = [],
        public int $operatorFeesCents = 0,
        public array $operatorFeeLines = [],
    ) {}
}
