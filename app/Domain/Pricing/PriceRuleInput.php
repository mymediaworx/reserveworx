<?php

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class PriceRuleInput
{
    public function __construct(
        public string $kind,
        public int $amountCents,
        public string $window = 'always',
        public ?int $durationMinutes = null,
        public ?string $startsOn = null,
        public ?string $endsOn = null,
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
    }

    public function applies(CarbonImmutable $on, int $durationMinutes): bool
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

        return $this->kind !== 'duration' || $this->durationMinutes === $durationMinutes;
    }
}
