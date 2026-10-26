<?php

namespace App\Services\Dispatch;

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
        return $this->startsAt->timezone($this->timezone)->format('Y-m-d H:i');
    }

    public function localReturn(): string
    {
        return $this->returnDue->timezone($this->timezone)->format('Y-m-d H:i');
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
}
