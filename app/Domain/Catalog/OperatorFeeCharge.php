<?php

namespace App\Domain\Catalog;

use App\Domain\Fees\Rounding;
use App\Enums\OperatorFeePricing;
use InvalidArgumentException;

/**
 * The cents one required operator fee adds to a booking.
 *
 * A flat line is charged once. A per-person line uses the party size.
 * A percent line is basis points of the pre-discount service total, rounded
 * half-up to the cent. A promo does not reduce that base.
 */
final class OperatorFeeCharge
{
    public const MAX_TOTAL_CENTS = 2_000_000_000;

    /**
     * @param  list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null}>  $definitions
     * @return list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null, quantity: int, total_cents: int}>
     */
    public static function lines(array $definitions, int $participants, int $serviceCents): array
    {
        if ($participants < 1) {
            throw new InvalidArgumentException('A party needs at least one person.');
        }

        if ($serviceCents < 0) {
            throw new InvalidArgumentException('The service total must not be negative.');
        }

        $lines = [];
        $sum = 0;

        foreach ($definitions as $definition) {
            $total = self::total($definition, $participants, $serviceCents);
            $sum += $total;

            if ($sum > self::MAX_TOTAL_CENTS) {
                throw new InvalidArgumentException('Mandatory fees are too large.');
            }

            $lines[] = [
                'operator_fee_id' => $definition['operator_fee_id'],
                'name' => $definition['name'],
                'pricing' => $definition['pricing'],
                'scope' => $definition['scope'],
                'amount_cents' => $definition['amount_cents'],
                'percent_bps' => $definition['percent_bps'],
                'quantity' => $definition['pricing'] === OperatorFeePricing::PerPerson->value ? $participants : 1,
                'total_cents' => $total,
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array{total_cents: int}>  $lines
     */
    public static function cents(array $lines): int
    {
        $total = 0;

        foreach ($lines as $line) {
            $total += $line['total_cents'];
        }

        return $total;
    }

    /**
     * @param  array{pricing: string, amount_cents: int|null, percent_bps: int|null}  $definition
     */
    public static function total(array $definition, int $participants, int $serviceCents): int
    {
        $pricing = OperatorFeePricing::tryFrom($definition['pricing']);

        if (! $pricing instanceof OperatorFeePricing) {
            throw new InvalidArgumentException('A mandatory fee must be flat, per person, or a percent.');
        }

        if ($participants < 1) {
            throw new InvalidArgumentException('A party needs at least one person.');
        }

        if ($serviceCents < 0) {
            throw new InvalidArgumentException('The service total must not be negative.');
        }

        if ($pricing === OperatorFeePricing::Percent) {
            $basisPoints = $definition['percent_bps'];

            if (! is_int($basisPoints) || $basisPoints < 1 || $basisPoints > 10_000) {
                throw new InvalidArgumentException('A percent fee needs a rate between 1 and 10000 basis points.');
            }

            return Rounding::basisPointsOf($serviceCents, $basisPoints);
        }

        $amount = $definition['amount_cents'];

        if (! is_int($amount) || $amount < 0) {
            throw new InvalidArgumentException('A mandatory fee amount must not be negative.');
        }

        if ($pricing === OperatorFeePricing::Flat) {
            return $amount;
        }

        $total = $amount * $participants;

        if ($total > self::MAX_TOTAL_CENTS) {
            throw new InvalidArgumentException('Mandatory fees are too large.');
        }

        return $total;
    }
}
