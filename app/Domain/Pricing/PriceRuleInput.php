<?php

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class PriceRuleInput
{
    /**
     * @param  list<int>|null  $weekdays  Carbon weekdays, 0 Sunday through 6 Saturday, or null for every day.
     */
    public function __construct(
        public string $kind,
        public int $amountCents,
        public string $window = 'always',
        public ?int $durationMinutes = null,
        public ?string $startsOn = null,
        public ?string $endsOn = null,
        public ?int $localStartMinute = null,
        public ?int $localEndMinute = null,
        public ?array $weekdays = null,
        public ?int $minParticipants = null,
        public ?int $maxParticipants = null,
    ) {
        if (! in_array($kind, ['base', 'per_person', 'duration'], true)) {
            throw new InvalidArgumentException('A price rule kind must be base, per_person, or duration.');
        }

        if (! in_array($window, ['always', 'weekday', 'weekend'], true)) {
            throw new InvalidArgumentException('A price window must be always, weekday, or weekend.');
        }

        if ($amountCents < 0) {
            throw new InvalidArgumentException('A price must not be negative.');
        }

        $hasStart = $localStartMinute !== null;
        $hasEnd = $localEndMinute !== null;

        if ($hasStart !== $hasEnd) {
            throw new InvalidArgumentException('A local time window needs a start and an end.');
        }

        if ($hasStart && ($localStartMinute < 0 || $localStartMinute > 1439 || $localEndMinute < 0 || $localEndMinute > 1439)) {
            throw new InvalidArgumentException('A local time window must use a minute from 00:00 through 23:59.');
        }

        if ($hasStart && $localStartMinute === $localEndMinute) {
            throw new InvalidArgumentException('A local time window must not start and end at the same minute.');
        }

        if ($weekdays !== null && ! $hasStart) {
            throw new InvalidArgumentException('Weekdays belong to a local time window.');
        }

        if ($weekdays === []) {
            throw new InvalidArgumentException('Name at least one weekday.');
        }

        if ($weekdays !== null) {
            $seen = [];

            foreach ($weekdays as $day) {
                if ($day < 0 || $day > 6 || isset($seen[$day])) {
                    throw new InvalidArgumentException('A weekday must be 0 for Sunday through 6 for Saturday.');
                }

                $seen[$day] = true;
            }
        }

        if ($maxParticipants !== null && $minParticipants === null) {
            throw new InvalidArgumentException('A party size needs a minimum.');
        }

        if ($minParticipants !== null && ($minParticipants < 1 || $minParticipants > 500)) {
            throw new InvalidArgumentException('A party size must be from 1 to 500.');
        }

        if ($maxParticipants !== null && ($maxParticipants < $minParticipants || $maxParticipants > 500)) {
            throw new InvalidArgumentException('A party size maximum must be at least the minimum and at most 500.');
        }

        if ($minParticipants !== null && $hasStart) {
            throw new InvalidArgumentException('A party size price cannot also name a clock window.');
        }
    }

    public function hasPartyBand(): bool
    {
        return $this->minParticipants !== null;
    }

    public function containsParty(int $participants): bool
    {
        if (! $this->hasPartyBand() || $this->minParticipants === null) {
            return false;
        }

        if ($participants < $this->minParticipants) {
            return false;
        }

        return $this->maxParticipants === null || $participants <= $this->maxParticipants;
    }

    /**
     * Higher wins among party-size prices of the same kind. A higher minimum
     * beats a lower one. The same minimum prefers a stated maximum, then the
     * narrower maximum.
     */
    public function partySpecificity(): int
    {
        $minimum = $this->minParticipants ?? 0;
        $maximum = $this->maxParticipants === null ? 0 : 10_000 - $this->maxParticipants;

        return ($minimum * 1_000_000) + $maximum;
    }

    /**
     * @return array<string, mixed>
     */
    public function partyBandSnapshot(): array
    {
        return [
            'kind' => $this->kind,
            'amount_cents' => $this->amountCents,
            'window' => $this->window,
            'min_participants' => $this->minParticipants,
            'max_participants' => $this->maxParticipants,
            'duration_minutes' => $this->durationMinutes,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
        ];
    }

    public function hasTimeWindow(): bool
    {
        return $this->localStartMinute !== null && $this->localEndMinute !== null;
    }

    /**
     * Date, weekday, and duration filters. A clock window is not part of this check.
     */
    public function applies(CarbonImmutable $on, int $durationMinutes): bool
    {
        if ($this->hasTimeWindow()) {
            return false;
        }

        return $this->matchesCalendar($on, $durationMinutes);
    }

    /**
     * Whether this clock window owns the local minute. The window is half-open.
     * An end earlier than the start crosses midnight.
     */
    public function covers(CarbonImmutable $local): bool
    {
        if (! $this->hasTimeWindow() || ! $this->matchesCalendar($local, $this->durationMinutes ?? 0, false)) {
            return false;
        }

        if ($this->weekdays !== null && ! in_array($local->dayOfWeek, $this->weekdays, true)) {
            return false;
        }

        $minute = ($local->hour * 60) + $local->minute;
        $start = $this->localStartMinute;
        $end = $this->localEndMinute;

        if ($start < $end) {
            return $minute >= $start && $minute < $end;
        }

        return $minute >= $start || $minute < $end;
    }

    /**
     * Higher wins among clock windows of the same kind. Date bounds, then an
     * explicit weekday list, then a weekday or weekend window.
     */
    public function specificity(): int
    {
        $score = 0;

        if ($this->startsOn !== null || $this->endsOn !== null) {
            $score += 4;
        }

        if ($this->weekdays !== null) {
            $score += 2;
        }

        if ($this->window !== 'always') {
            $score += 1;
        }

        return $score;
    }

    /**
     * @return array<string, mixed>
     */
    public function timeWindowSnapshot(int $minutes): array
    {
        $start = $this->localStartMinute;
        $end = $this->localEndMinute;

        return [
            'kind' => $this->kind,
            'amount_cents' => $this->amountCents,
            'window' => $this->window,
            'local_start' => self::formatMinute($start ?? 0),
            'local_end' => self::formatMinute($end ?? 0),
            'weekdays' => $this->weekdays,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'duration_minutes' => $this->durationMinutes,
            'minutes' => $minutes,
        ];
    }

    public static function formatMinute(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    public static function parseMinute(string $clock): int
    {
        [$hour, $minute] = explode(':', $clock, 2);

        return ((int) $hour * 60) + (int) $minute;
    }

    private function matchesCalendar(CarbonImmutable $on, int $durationMinutes, bool $checkDuration = true): bool
    {
        $weekend = in_array($on->dayOfWeek, [CarbonImmutable::SATURDAY, CarbonImmutable::SUNDAY], true);

        if ($this->window === 'weekday' && $weekend) {
            return false;
        }

        if ($this->window === 'weekend' && ! $weekend) {
            return false;
        }

        $day = $on->toDateString();

        if ($this->startsOn !== null && $day < $this->startsOn) {
            return false;
        }

        if ($this->endsOn !== null && $day > $this->endsOn) {
            return false;
        }

        if (! $checkDuration) {
            return true;
        }

        return $this->kind !== 'duration' || $this->durationMinutes === $durationMinutes;
    }
}
