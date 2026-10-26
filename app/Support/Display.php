<?php

namespace App\Support;

use App\Domain\Fees\Disclosures\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Formatting for screens. Times are always shown in the location's own zone.
 */
final class Display
{
    public static function time(DateTimeInterface $at, string $zone): string
    {
        return CarbonImmutable::instance($at)->timezone($zone)->format('g:i A');
    }

    public static function date(DateTimeInterface $at, string $zone): string
    {
        return CarbonImmutable::instance($at)->timezone($zone)->format('D, M j');
    }

    public static function dateTime(DateTimeInterface $at, string $zone): string
    {
        return CarbonImmutable::instance($at)->timezone($zone)->format('D, M j, g:i A');
    }

    public static function range(DateTimeInterface $start, DateTimeInterface $end, string $zone): string
    {
        $from = CarbonImmutable::instance($start)->timezone($zone);
        $to = CarbonImmutable::instance($end)->timezone($zone);

        if ($from->isSameDay($to)) {
            return $from->format('D, M j').', '.$from->format('g:i A').' – '.$to->format('g:i A');
        }

        return $from->format('D, M j g:i A').' – '.$to->format('D, M j g:i A');
    }

    /**
     * One instant in the location zone, with that zone's abbreviation.
     */
    public static function dateTimeWithZone(DateTimeInterface $at, string $zone): string
    {
        $local = CarbonImmutable::instance($at)->timezone($zone);

        return $local->format('D, M j, g:i A').' '.$local->format('T');
    }

    /**
     * Start and end in the location zone, with the zone abbreviation.
     * A span that crosses a daylight-saving change names both abbreviations.
     */
    public static function rangeWithZone(DateTimeInterface $start, DateTimeInterface $end, string $zone): string
    {
        $from = CarbonImmutable::instance($start)->timezone($zone);
        $to = CarbonImmutable::instance($end)->timezone($zone);
        $fromZone = $from->format('T');
        $toZone = $to->format('T');

        if ($fromZone === $toZone) {
            if ($from->isSameDay($to)) {
                return $from->format('D, M j, g:i A').' – '.$to->format('g:i A').' '.$fromZone;
            }

            return $from->format('D, M j, g:i A').' – '.$to->format('D, M j, g:i A').' '.$fromZone;
        }

        if ($from->isSameDay($to)) {
            return $from->format('D, M j, g:i A').' '.$fromZone.' – '.$to->format('g:i A').' '.$toZone;
        }

        return $from->format('D, M j, g:i A').' '.$fromZone.' – '.$to->format('D, M j, g:i A').' '.$toZone;
    }

    public static function minutes(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days.' '.($days === 1 ? 'day' : 'days');
        }

        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        if ($minutes > 60) {
            return intdiv($minutes, 60).' h '.($minutes % 60).' min';
        }

        return $minutes.' min';
    }

    public static function money(int $cents, string $currency = 'USD'): string
    {
        return Money::format($cents, $currency);
    }

    public static function basisPoints(?int $basisPoints): string
    {
        if ($basisPoints === null) {
            return '—';
        }

        return number_format($basisPoints / 100, 1).'%';
    }

    public static function status(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
