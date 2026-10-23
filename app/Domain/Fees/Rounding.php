<?php

namespace App\Domain\Fees;

use InvalidArgumentException;

/**
 * Integer-cent arithmetic. No floats anywhere in the fee engine.
 */
final class Rounding
{
    /**
     * Divide and round half-up to the nearest integer. Both operands must be non-negative.
     */
    public static function halfUp(int $numerator, int $denominator): int
    {
        if ($numerator < 0) {
            throw new InvalidArgumentException('Numerator must not be negative.');
        }

        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        // remainder / denominator >= 1/2, compared without division.
        if ($remainder * 2 >= $denominator) {
            $quotient++;
        }

        return $quotient;
    }

    /**
     * Basis points of an amount, rounded half-up to the cent.
     */
    public static function basisPointsOf(int $amountCents, int $basisPoints): int
    {
        return self::halfUp($amountCents * $basisPoints, 10_000);
    }
}
