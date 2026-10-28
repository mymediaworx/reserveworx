<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A local clock token for one location day.
 *
 * A normal hour is `H:i`. The repeated hour on a fall-back day is `H:i` plus
 * that instant's offset, so 1:00 AM EDT and 1:00 AM EST stay distinct.
 */
final class LocalClock
{
    public static function token(CarbonImmutable $instant): string
    {
        $clock = $instant->format('H:i');

        if (! self::repeated($instant)) {
            return $clock;
        }

        return $clock.$instant->format('P');
    }

    public static function repeated(CarbonImmutable $instant): bool
    {
        $clock = $instant->format('Y-m-d H:i');

        foreach ([60, -60] as $minutes) {
            $shifted = $instant->addMinutes($minutes);

            if ($shifted->getTimestamp() !== $instant->getTimestamp() && $shifted->format('Y-m-d H:i') === $clock) {
                return true;
            }
        }

        return false;
    }

    public static function parse(string $date, string $token, string $zone): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        if (! in_array($zone, timezone_identifiers_list(), true)) {
            return null;
        }

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)([+-]\d{2}:\d{2})$/', $token, $offset) === 1) {
            $parsed = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i:sP',
                $date.'T'.$offset[1].':'.$offset[2].':00'.$offset[3],
            );

            if (! $parsed instanceof CarbonImmutable) {
                return null;
            }

            $local = $parsed->timezone($zone);

            if ($local->format('H:iP') !== $token || $local->toDateString() !== $date) {
                return null;
            }

            return $local;
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $token) !== 1) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$token, $zone);

        return $parsed instanceof CarbonImmutable ? $parsed : null;
    }
}
