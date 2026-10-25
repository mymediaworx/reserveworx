<?php

namespace App\Support;

use App\Models\TenantSetting;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Whole minutes past due plus the workspace grace period, and the fee those
 * minutes produce. The count is floored. A null rate or a null grace means none.
 */
final class LateFee
{
    public static function graceMinutes(?TenantSetting $setting): int
    {
        $grace = $setting?->late_grace_minutes;

        if (! is_int($grace) || $grace < 1) {
            return 0;
        }

        return $grace;
    }

    public static function billableMinutes(DateTimeInterface $dueAt, DateTimeInterface $asOf, int $graceMinutes): int
    {
        $deadline = CarbonImmutable::instance($dueAt)->utc()->addMinutes(max(0, $graceMinutes));

        return Lateness::minutes($deadline, $asOf);
    }

    public static function amountCents(int $billableMinutes, ?int $rateCents): int
    {
        if ($billableMinutes < 1 || $rateCents === null || $rateCents < 1) {
            return 0;
        }

        return $billableMinutes * $rateCents;
    }
}
