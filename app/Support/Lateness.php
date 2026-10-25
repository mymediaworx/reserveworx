<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Whole minutes a rental is past due. The count is floored, and it is only a record.
 */
final class Lateness
{
    public static function minutes(DateTimeInterface $dueAt, DateTimeInterface $asOf): int
    {
        $seconds = CarbonImmutable::instance($asOf)->utc()->getTimestamp()
            - CarbonImmutable::instance($dueAt)->utc()->getTimestamp();

        if ($seconds <= 0) {
            return 0;
        }

        return intdiv($seconds, 60);
    }

    public static function overdue(DateTimeInterface $dueAt, DateTimeInterface $asOf): bool
    {
        return CarbonImmutable::instance($dueAt)->utc()->lessThan(CarbonImmutable::instance($asOf)->utc());
    }

    public static function label(DateTimeInterface $dueAt, DateTimeInterface $asOf): string
    {
        if (! self::overdue($dueAt, $asOf)) {
            return '';
        }

        $minutes = self::minutes($dueAt, $asOf);

        if ($minutes < 1) {
            return 'Less than 1 minute late';
        }

        if ($minutes === 1) {
            return '1 minute late';
        }

        return $minutes.' minutes late';
    }
}
