<?php

namespace App\Services\Allocation;

use Carbon\CarbonImmutable;

readonly class AllocationIntent
{
    /**
     * @param  list<array{add_on_id: string, name: string, kind: string, pricing: string, unit_amount_cents: int, quantity: int, total_cents: int}>  $addOns
     */
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
        public ?string $customerPhone = null,
        public array $addOns = [],
        public ?string $variantId = null,
        public ?string $customerId = null,
        public bool $counterSale = false,
        public ?string $corporateAccountId = null,
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

        if ($this->addOns !== []) {
            $lines = $this->addOns;
            usort($lines, fn (array $left, array $right): int => $left['add_on_id'] <=> $right['add_on_id']);
            $fields['add_ons'] = array_map(fn (array $line): array => [
                'add_on_id' => $line['add_on_id'],
                'quantity' => $line['quantity'],
            ], $lines);
        }

        if ($this->variantId !== null && $this->variantId !== '') {
            $fields['variant_id'] = $this->variantId;
        }

        if ($this->corporateAccountId !== null && $this->corporateAccountId !== '') {
            $fields['corporate_account_id'] = $this->corporateAccountId;
        }

        $encoded = json_encode($fields, JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
    }
}
