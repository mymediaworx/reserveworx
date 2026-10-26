<?php

namespace App\Domain\Pricing;

use App\Domain\Fees\Disclosures\Money;

/**
 * Plain-language description of the group or corporate rule a quote used.
 * The amounts themselves stay on the pricing snapshot.
 */
final class GroupPriceCopy
{
    /**
     * Party-size and corporate rules that changed this quote.
     *
     * @param  array<mixed>  $partyBands
     * @return list<array<string, mixed>>
     */
    public static function rules(array $partyBands): array
    {
        $rules = [];

        foreach ($partyBands as $band) {
            if (! is_array($band) || ! self::isGroupRule($band)) {
                continue;
            }

            $rules[] = $band;
        }

        return $rules;
    }

    /**
     * @param  list<array<string, mixed>>  $partyBands
     */
    public static function label(array $partyBands): string
    {
        foreach (self::rules($partyBands) as $band) {
            $account = $band['corporate_account_id'] ?? null;

            if (is_string($account) && $account !== '') {
                return 'Corporate price';
            }
        }

        return 'Group price';
    }

    /**
     * @param  list<array<string, mixed>>  $partyBands
     */
    public static function detail(array $partyBands, bool $publicOnly = false): ?string
    {
        $lines = [];

        foreach (self::rules($partyBands) as $band) {
            $account = $band['corporate_account_id'] ?? null;

            if ($publicOnly && is_string($account) && $account !== '') {
                continue;
            }

            $line = self::line($band);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        if ($lines === []) {
            return null;
        }

        return implode('; ', $lines);
    }

    /**
     * @param  array<string, mixed>  $band
     */
    private static function isGroupRule(array $band): bool
    {
        $id = $band['price_rule_id'] ?? null;
        $percent = $band['percent_off'] ?? null;
        $account = $band['corporate_account_id'] ?? null;
        $minimum = $band['min_participants'] ?? null;

        if (is_string($id) && $id !== '') {
            return true;
        }

        if (is_numeric($percent) && (int) $percent > 0) {
            return true;
        }

        if (is_string($account) && $account !== '') {
            return true;
        }

        return is_numeric($minimum);
    }

    /**
     * @param  array<string, mixed>  $band
     */
    private static function line(array $band): string
    {
        $parts = [];
        $account = $band['corporate_account_name'] ?? null;

        if (is_string($account) && $account !== '') {
            $parts[] = $account;
        }

        $minimum = $band['min_participants'] ?? null;
        $maximum = $band['max_participants'] ?? null;

        if (is_numeric($minimum)) {
            $from = (int) $minimum;
            $parts[] = is_numeric($maximum)
                ? $from.'–'.((int) $maximum).' people'
                : $from.' or more people';
        }

        $percent = $band['percent_off'] ?? null;

        if (is_numeric($percent) && (int) $percent > 0) {
            $parts[] = ((int) $percent).'% off';
        } elseif (($band['kind'] ?? null) === 'per_person' && is_numeric($band['amount_cents'] ?? null)) {
            $parts[] = Money::format((int) $band['amount_cents']).' per person';
        } elseif (is_numeric($band['amount_cents'] ?? null) && (int) $band['amount_cents'] > 0) {
            $parts[] = Money::format((int) $band['amount_cents']);
        }

        $name = $band['name'] ?? null;

        if (is_string($name) && $name !== '') {
            $parts[] = $name;
        }

        return implode(', ', $parts);
    }
}
