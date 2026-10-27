<?php

namespace App\Services\Dispatch;

use App\Support\Display;
use Carbon\CarbonImmutable;

/**
 * One confirmed or no-show booking on the dispatch sheet.
 */
final readonly class ManifestBooking
{
    /**
     * @param  list<array{name: string, minor: bool, waiver: string}>  $people
     */
    public function __construct(
        public string $id,
        public string $service,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $returnDue,
        public string $resource,
        public string $guideName,
        public string $customerName,
        public string $customerPhone,
        public int $partySize,
        public array $people,
        public string $addOns,
        public ?int $balanceCents,
        public string $currency,
        public string $checkIn,
        public ?string $staffNotes,
        public string $timezone,
    ) {}

    public function localStart(): string
    {
        return $this->clock($this->startsAt);
    }

    public function localReturn(): string
    {
        return $this->clock($this->returnDue);
    }

    public function startLabel(): string
    {
        return Display::dateTimeWithZone($this->startsAt, $this->timezone);
    }

    public function returnLabel(): string
    {
        return Display::dateTimeWithZone($this->returnDue, $this->timezone);
    }

    public function visitLabel(): string
    {
        return Display::rangeWithZone($this->startsAt, $this->returnDue, $this->timezone);
    }

    public function participantSummary(): string
    {
        $parts = [];

        foreach ($this->people as $person) {
            if ($person['name'] === 'This booking') {
                continue;
            }

            $parts[] = $person['minor'] ? $person['name'].' (minor)' : $person['name'];
        }

        return implode('; ', $parts);
    }

    public function waiverSummary(): string
    {
        $parts = [];

        foreach ($this->people as $person) {
            $name = $person['name'];

            if ($person['minor']) {
                $name .= ' (minor)';
            }

            $parts[] = $name.': '.$person['waiver'];
        }

        return implode('; ', $parts);
    }

    private function clock(CarbonImmutable $at): string
    {
        $local = $at->timezone($this->timezone);

        return $local->format('Y-m-d H:i').' '.$local->format('T');
    }
}
