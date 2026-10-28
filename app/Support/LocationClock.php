<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A civil clock in a location zone. Stored instants stay UTC.
 * A clock that happens twice on a fall-back morning is the earlier instant
 * or the later one. A clock that does not exist on a spring-forward morning
 * is not an instant.
 */
final class LocationClock
{
    public function parse(string $value, string $zone, bool $latest): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(Z|[+-]\d{2}:\d{2})?$/', $value, $match) !== 1) {
            return null;
        }

        $date = $match[1];
        $clock = $match[2].':00';
        $offset = $match[3] ?? '';

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        if ($offset !== '') {
            return $this->withOffset($date, $clock, $offset, $zone);
        }

        $matches = $this->matches($date, $clock, $zone);

        if ($matches === []) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($latest ? max($matches) : min($matches));
    }

    private function withOffset(string $date, string $clock, string $offset, string $zone): ?CarbonImmutable
    {
        $suffix = $offset === 'Z' ? '+00:00' : $offset;
        $instant = DateTimeImmutable::createFromFormat('Y-m-d H:i:sP', $date.' '.$clock.$suffix);

        if (! $instant instanceof DateTimeImmutable) {
            return null;
        }

        $local = CarbonImmutable::instance($instant)->timezone($zone);

        if ($local->format('Y-m-d H:i:s') !== $date.' '.$clock) {
            return null;
        }

        return CarbonImmutable::instance($instant)->utc();
    }

    /**
     * @return list<int>
     */
    private function matches(string $date, string $clock, string $zone): array
    {
        $timezone = new DateTimeZone($zone);
        $midnight = new DateTimeImmutable($date.' 00:00:00', $timezone);
        $horizon = $midnight->modify('+36 hours');
        $transitions = $timezone->getTransitions($midnight->getTimestamp() - 3600, $horizon->getTimestamp());

        if ($transitions === []) {
            return [];
        }

        $matches = [];
        $count = count($transitions);

        for ($index = 0; $index < $count; $index++) {
            $offset = (int) $transitions[$index]['offset'];
            $from = (int) $transitions[$index]['ts'];
            $until = $index + 1 < $count ? (int) $transitions[$index + 1]['ts'] : $horizon->getTimestamp();
            $wanted = $this->civilUtc($date, $clock) - $offset;

            if ($wanted < $from || $wanted >= $until) {
                continue;
            }

            $local = CarbonImmutable::createFromTimestampUTC($wanted)->timezone($zone);

            if ($local->format('Y-m-d H:i:s') === $date.' '.$clock) {
                $matches[] = $wanted;
            }
        }

        return $matches;
    }

    private function civilUtc(string $date, string $clock): int
    {
        return (new DateTimeImmutable($date.' '.$clock, new DateTimeZone('UTC')))->getTimestamp();
    }
}
