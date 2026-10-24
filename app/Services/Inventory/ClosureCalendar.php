<?php

namespace App\Services\Inventory;

use App\Enums\ResourceType;
use App\Models\Asset;
use App\Models\LocationClosure;
use App\Models\Pool;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Turns a closure row into half-open instants in the location timezone.
 * A yearly row repeats its month and day. A clock time that does not exist
 * on a spring-forward morning is the instant the clock jumps ahead. A clock
 * time that happens twice on a fall-back morning uses the earlier instant as
 * a start and the later instant as an end, so the closed window covers both.
 */
final class ClosureCalendar
{
    /**
     * Location-wide rows apply to every check. A category or resource row
     * applies only when that resource is the one being checked.
     */
    public function applies(LocationClosure $closure, ?string $resourceType, ?string $resourceId, ?string $categoryId): bool
    {
        if ($closure->resource_id !== null && $closure->resource_id !== '') {
            return $resourceType === $closure->resource_type && $resourceId === $closure->resource_id;
        }

        if ($closure->resource_category_id !== null && $closure->resource_category_id !== '') {
            return $categoryId !== null && $categoryId === $closure->resource_category_id;
        }

        return true;
    }

    public function categoryId(?string $resourceType, ?string $resourceId): ?string
    {
        if ($resourceType === null || $resourceId === null || $resourceId === '') {
            return null;
        }

        if ($resourceType === ResourceType::Asset->value) {
            $category = Asset::query()->whereKey($resourceId)->value('resource_category_id');

            return is_string($category) ? $category : null;
        }

        if ($resourceType === ResourceType::Pool->value) {
            $category = Pool::query()->whereKey($resourceId)->value('resource_category_id');

            return is_string($category) ? $category : null;
        }

        return null;
    }

    public function covers(LocationClosure $closure, string $timezone, CarbonImmutable $startUtc, CarbonImmutable $endUtc): bool
    {
        return $this->windows($closure, $timezone, $startUtc, $endUtc) !== [];
    }

    /**
     * Concrete windows that overlap the half-open query interval.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function windows(LocationClosure $closure, string $timezone, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $rangeStart = $rangeStart->utc();
        $rangeEnd = $rangeEnd->utc();

        if ($rangeEnd->lessThanOrEqualTo($rangeStart)) {
            return [];
        }

        $windows = [];

        foreach ($this->occurrences($closure, $timezone, $rangeStart, $rangeEnd) as [$startsOn, $endsOn]) {
            $window = $this->concrete(
                $timezone,
                $closure->all_day,
                $startsOn,
                $endsOn,
                $this->nullableClock($closure->starts_local),
                $this->nullableClock($closure->ends_local),
            );

            if ($window[1]->greaterThan($window[0]) && $this->overlaps($window[0], $window[1], $rangeStart, $rangeEnd)) {
                $windows[] = $window;
            }
        }

        return $windows;
    }

    /**
     * The stored dates as one half-open window, ignoring yearly repetition.
     * The end is not after the start when the clocks collapse the range.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function concrete(string $timezone, bool $allDay, string $startsOn, string $endsOn, ?string $startsLocal, ?string $endsLocal): array
    {
        if ($allDay) {
            $start = $this->localInstant($startsOn, '00:00:00', $timezone, false);
            $endDate = CarbonImmutable::createFromFormat('!Y-m-d', $endsOn, $timezone);

            if (! $endDate instanceof CarbonImmutable) {
                throw new InvalidArgumentException('Choose a real end date.');
            }

            return [$start, $this->localInstant($endDate->addDay()->toDateString(), '00:00:00', $timezone, false)];
        }

        return [
            $this->localInstant($startsOn, $this->clock($startsLocal), $timezone, false),
            $this->localInstant($endsOn, $this->clock($endsLocal), $timezone, true),
        ];
    }

    public function localInstant(string $date, string $clock, string $timezone, bool $latest): CarbonImmutable
    {
        $clock = $this->clock($clock);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Choose a real date.');
        }

        $zone = new DateTimeZone($timezone);
        $midnight = new DateTimeImmutable($date.' 00:00:00', $zone);
        $horizon = $midnight->modify('+36 hours');
        $transitions = $zone->getTransitions($midnight->getTimestamp() - 3600, $horizon->getTimestamp());
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

            $local = CarbonImmutable::createFromTimestampUTC($wanted)->timezone($timezone);

            if ($local->format('Y-m-d H:i:s') === $date.' '.$clock) {
                $matches[] = $wanted;
            }
        }

        if ($matches !== []) {
            return CarbonImmutable::createFromTimestampUTC($latest ? max($matches) : min($matches));
        }

        foreach ($transitions as $transition) {
            $at = CarbonImmutable::createFromTimestampUTC((int) $transition['ts'])->timezone($timezone);

            if ($at->format('Y-m-d') !== $date) {
                continue;
            }

            $before = $at->subSecond();

            if ($before->format('Y-m-d') !== $date) {
                continue;
            }

            $beforeClock = $before->format('H:i:s');
            $afterClock = $at->format('H:i:s');

            if ($afterClock > $beforeClock && $clock > $beforeClock && $clock < $afterClock) {
                return CarbonImmutable::createFromTimestampUTC((int) $transition['ts']);
            }
        }

        return CarbonImmutable::parse($date.' '.$clock, $timezone)->utc();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function occurrences(LocationClosure $closure, string $timezone, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $startsOn = $closure->starts_on->toDateString();
        $endsOn = $closure->ends_on->toDateString();

        if (! $closure->recurs_yearly) {
            return [[$startsOn, $endsOn]];
        }

        $localStart = $rangeStart->timezone($timezone);
        $localEnd = $rangeEnd->subSecond()->timezone($timezone);
        $startMonth = (int) $closure->starts_on->format('n');
        $startDay = (int) $closure->starts_on->format('j');
        $endMonth = (int) $closure->ends_on->format('n');
        $endDay = (int) $closure->ends_on->format('j');
        $wraps = $this->wraps($startMonth, $startDay, $endMonth, $endDay, $closure->all_day, $closure->starts_local, $closure->ends_local);
        $rows = [];

        for ($year = $localStart->year - 1; $year <= $localEnd->year + 1; $year++) {
            $start = $this->dateInYear($year, $startMonth, $startDay);
            $end = $this->dateInYear($wraps ? $year + 1 : $year, $endMonth, $endDay);

            if ($start === null || $end === null) {
                continue;
            }

            $rows[] = [$start, $end];
        }

        return $rows;
    }

    private function wraps(int $startMonth, int $startDay, int $endMonth, int $endDay, bool $allDay, mixed $startsLocal, mixed $endsLocal): bool
    {
        $start = [$startMonth, $startDay];
        $end = [$endMonth, $endDay];

        if ($end < $start) {
            return true;
        }

        if ($end > $start || $allDay) {
            return false;
        }

        return $this->clock($this->nullableClock($endsLocal)) <= $this->clock($this->nullableClock($startsLocal));
    }

    private function dateInYear(int $year, int $month, int $day): ?string
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function overlaps(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): bool
    {
        return $start->getTimestamp() < $rangeEnd->getTimestamp()
            && $end->getTimestamp() > $rangeStart->getTimestamp();
    }

    private function civilUtc(string $date, string $clock): int
    {
        return (new DateTimeImmutable($date.' '.$clock, new DateTimeZone('UTC')))->getTimestamp();
    }

    private function nullableClock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->clock($value);
    }

    private function clock(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        $text = substr((string) $value, 0, 8);

        if (strlen($text) === 5) {
            $text .= ':00';
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $text) !== 1) {
            return '00:00:00';
        }

        return $text;
    }
}
