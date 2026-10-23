<?php

namespace App\Domain\Fees;

use InvalidArgumentException;

/**
 * The amount a booking fee is calculated on (spec Section 7.4):
 * services + add-ons + mandatory operator fees − discounts.
 *
 * Taxes and the booking fee itself have no field here, so they cannot be
 * included by mistake.
 */
final readonly class FeeBasis
{
    public function __construct(
        public int $servicesCents,
        public int $addOnsCents = 0,
        public int $mandatoryOperatorFeesCents = 0,
        public int $discountsCents = 0,
    ) {
        foreach ([
            'services' => $servicesCents,
            'add-ons' => $addOnsCents,
            'mandatory operator fees' => $mandatoryOperatorFeesCents,
            'discounts' => $discountsCents,
        ] as $label => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("Fee basis {$label} must not be negative. Enter discounts as a positive amount.");
            }
        }

        if ($discountsCents > $servicesCents + $addOnsCents + $mandatoryOperatorFeesCents) {
            throw new InvalidArgumentException('Discounts cannot exceed the booking subtotal.');
        }
    }

    public static function ofSubtotal(int $subtotalCents): self
    {
        return new self($subtotalCents);
    }

    public function cents(): int
    {
        return $this->servicesCents + $this->addOnsCents + $this->mandatoryOperatorFeesCents - $this->discountsCents;
    }
}
