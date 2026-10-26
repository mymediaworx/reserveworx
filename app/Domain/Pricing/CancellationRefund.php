<?php

namespace App\Domain\Pricing;

use App\Domain\Fees\Rounding;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Picks the refund percent for a cancellation from the tenant's saved windows.
 * Pure: no database access and no Stripe calls.
 */
final class CancellationRefund
{
    /**
     * Whole minutes from `$at` until the start. One second after the start is -1,
     * so a window of 0 minutes does not match a visit that has already begun.
     */
    public static function minutesUntil(CarbonImmutable $startsAt, CarbonImmutable $at): int
    {
        $seconds = $startsAt->getTimestamp() - $at->getTimestamp();

        if ($seconds < 0) {
            return -1;
        }

        return intdiv($seconds, 60);
    }

    /**
     * The longest lead that still fits. No matching window, including an empty
     * list, is 0. The default does not invent a refund.
     *
     * @param  list<array{lead_minutes: int, refund_percent: int}>  $tiers
     */
    public static function percent(array $tiers, int $minutesUntilStart): int
    {
        $bestLead = null;
        $percent = 0;

        foreach ($tiers as $tier) {
            $lead = $tier['lead_minutes'];

            if ($lead <= $minutesUntilStart && ($bestLead === null || $lead > $bestLead)) {
                $bestLead = $lead;
                $percent = $tier['refund_percent'];
            }
        }

        return $percent;
    }

    /**
     * `$percent` percent of an amount in cents, rounded half-up.
     */
    public static function share(int $amountCents, int $percent): int
    {
        if ($amountCents < 0) {
            throw new InvalidArgumentException('Amount must not be negative.');
        }

        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('Percent must be from 0 to 100.');
        }

        return Rounding::halfUp($amountCents * $percent, 100);
    }
}
