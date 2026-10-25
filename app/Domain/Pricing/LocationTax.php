<?php

namespace App\Domain\Pricing;

use App\Domain\Fees\Rounding;
use InvalidArgumentException;

/**
 * Named per-location tax rates. Each rate is a percent of the bases it names:
 * the discounted rental, add-ons, and required operator fees. The booking fee
 * is not a base. An empty rule list is the caller's signal to keep the older
 * single rate.
 */
final class LocationTax
{
    /**
     * @param  list<array<string, mixed>>  $rules
     * @return array{cents: int, lines: list<array<string, mixed>>}
     */
    public static function assess(array $rules, int $rentalCents, int $addOnCents, int $feeCents): array
    {
        if ($rentalCents < 0 || $addOnCents < 0 || $feeCents < 0) {
            throw new InvalidArgumentException('Tax bases must not be negative.');
        }

        $total = 0;
        /** @var list<array<string, mixed>> $lines */
        $lines = [];

        foreach ($rules as $rule) {
            $line = self::line($rule, $rentalCents, $addOnCents, $feeCents);
            $total += $line['cents'];
            $lines[] = $line;
        }

        return ['cents' => $total, 'lines' => $lines];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array{location_tax_rule_id: string, name: string, percent_bps: int, applies_to_rentals: bool, applies_to_add_ons: bool, applies_to_fees: bool, basis_cents: int, cents: int}
     */
    private static function line(array $rule, int $rentalCents, int $addOnCents, int $feeCents): array
    {
        $id = $rule['id'] ?? null;
        $name = $rule['name'] ?? null;

        if (! is_string($id) || $id === '' || strlen($id) > 26) {
            throw new InvalidArgumentException('A tax rate needs a name.');
        }

        if (! is_string($name) || trim($name) === '' || strlen($name) > 80) {
            throw new InvalidArgumentException('A tax rate needs a name.');
        }

        $basisPoints = self::basisPoints($rule['percent_bps'] ?? null);
        $rentals = self::flag($rule['applies_to_rentals'] ?? null);
        $addOns = self::flag($rule['applies_to_add_ons'] ?? null);
        $fees = self::flag($rule['applies_to_fees'] ?? null);

        if (! $rentals && ! $addOns && ! $fees) {
            throw new InvalidArgumentException('A tax rate has to apply to rentals, add-ons, or fees.');
        }

        $basis = ($rentals ? $rentalCents : 0) + ($addOns ? $addOnCents : 0) + ($fees ? $feeCents : 0);
        $cents = Rounding::basisPointsOf($basis, $basisPoints);

        return [
            'location_tax_rule_id' => $id,
            'name' => $name,
            'percent_bps' => $basisPoints,
            'applies_to_rentals' => $rentals,
            'applies_to_add_ons' => $addOns,
            'applies_to_fees' => $fees,
            'basis_cents' => $basis,
            'cents' => $cents,
        ];
    }

    private static function basisPoints(mixed $value): int
    {
        if (is_int($value)) {
            $basisPoints = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $basisPoints = (int) $value;
        } else {
            throw new InvalidArgumentException('A tax rate must be a whole number of basis points.');
        }

        if ($basisPoints < 1 || $basisPoints > 10_000) {
            throw new InvalidArgumentException('A tax rate must be between 0.01 and 100 percent.');
        }

        return $basisPoints;
    }

    private static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        throw new InvalidArgumentException('Choose whether a tax rate applies.');
    }
}
