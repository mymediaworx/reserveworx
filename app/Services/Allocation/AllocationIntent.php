<?php

namespace App\Services\Allocation;

use Carbon\CarbonImmutable;

readonly class AllocationIntent
{
    public function __construct(
        public string $tenantId,
        public string $serviceId,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $participants,
        public ?string $idempotencyKey,
        public ?string $customerName,
        public ?string $customerEmail,
        public ?string $departureId = null,
        public ?string $guideUserId = null,
    ) {}

    public function payloadHash(): string
    {
        $fields = [
            'ends_at' => $this->endsAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'participants' => $this->participants,
            'service_id' => $this->serviceId,
            'starts_at' => $this->startsAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($this->departureId !== null && $this->departureId !== '') {
            $fields['departure_id'] = $this->departureId;
        }

        if ($this->guideUserId !== null && $this->guideUserId !== '') {
            $fields['guide_user_id'] = $this->guideUserId;
        }

        $encoded = json_encode($fields, JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
    }
}
