<?php

namespace App\Domain\Pricing;

use App\Models\Booking;
use App\Models\ServiceVariant;
use InvalidArgumentException;

/**
 * A variant price override replaces the open base rate. Peak clock windows of
 * that same kind still win the minutes they cover. Per-person and duration
 * rates stay. Operator fees are applied to the service total this produces.
 */
final class VariantPrice
{
    public static function forBooking(Booking $booking): ?int
    {
        $id = $booking->service_variant_id;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $variant = ServiceVariant::query()->where('service_id', $booking->service_id)->find($id);

        if (! $variant instanceof ServiceVariant) {
            return null;
        }

        return $variant->price_override_cents;
    }

    /**
     * @param  list<PriceRuleInput>  $rules
     * @return list<PriceRuleInput>
     */
    public static function overrideBase(array $rules, ?int $cents): array
    {
        if ($cents === null) {
            return $rules;
        }

        if ($cents < 0) {
            throw new InvalidArgumentException('A price must not be negative.');
        }

        $kept = [];

        foreach ($rules as $rule) {
            if ($rule->kind === 'base' && ! $rule->hasTimeWindow()) {
                continue;
            }

            $kept[] = $rule;
        }

        array_unshift($kept, new PriceRuleInput('base', $cents));

        return $kept;
    }
}
