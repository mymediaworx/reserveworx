<?php

namespace App\Domain\Fees;

use DomainException;

/**
 * Stripe requires the application fee to be less than the charge. When it
 * cannot be (for example, an operator-absorbed fee that is at least the whole
 * booking total), checkout must be refused rather than adjusted.
 */
final class FeeExceedsChargeException extends DomainException
{
    public static function for(int $feeCents, int $chargeCents): self
    {
        return new self("Booking fee of {$feeCents} cents is not less than the charge of {$chargeCents} cents. Refuse this checkout.");
    }
}
