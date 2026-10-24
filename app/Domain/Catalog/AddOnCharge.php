<?php

namespace App\Domain\Catalog;

use App\Enums\AddOnPricing;
use InvalidArgumentException;

/**
 * The cents one chosen add-on adds to a booking.
 *
 * A flat line is charged once. A per-person line uses the party size.
 * An each line is the unit price times the quantity.
 */
final class AddOnCharge
{
    public static function total(AddOnPricing $pricing, int $unitAmountCents, int $quantity, int $participants): int
    {
        if ($unitAmountCents < 0) {
            throw new InvalidArgumentException('An add-on price must not be negative.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('An add-on quantity must be at least 1.');
        }

        if ($participants < 1) {
            throw new InvalidArgumentException('A party needs at least one person.');
        }

        if ($pricing === AddOnPricing::Flat) {
            if ($quantity !== 1) {
                throw new InvalidArgumentException('A flat add-on is charged once.');
            }

            return $unitAmountCents;
        }

        if ($pricing === AddOnPricing::PerPerson) {
            if ($quantity !== 1) {
                throw new InvalidArgumentException('A per-person add-on uses the party size.');
            }

            return $unitAmountCents * $participants;
        }

        return $unitAmountCents * $quantity;
    }
}
