<?php

namespace App\Domain\Pricing;

use App\Domain\Fees\BookingFeeQuote;
use App\Domain\Fees\PaymentFee;

final readonly class PricedBooking
{
    /**
     * @param  list<array<string, mixed>>  $addOnLines
     * @param  list<array<string, mixed>>  $timeWindows
     * @param  list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null, quantity: int, total_cents: int}>  $operatorFeeLines
     * @param  list<array<string, mixed>>  $taxLines
     * @param  list<array<string, mixed>>  $partyBands
     */
    public function __construct(
        public int $servicesCents,
        public int $discountCents,
        public int $taxCents,
        public BookingFeeQuote $quote,
        public PaymentFee $payment,
        public ?string $promoCode,
        public int $addOnsCents = 0,
        public array $addOnLines = [],
        public array $timeWindows = [],
        public int $operatorFeesCents = 0,
        public array $operatorFeeLines = [],
        public array $taxLines = [],
        public bool $taxIncludesBookingFee = false,
        public array $partyBands = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        $snapshot = $this->quote->toSnapshot() + [
            'services_cents' => $this->servicesCents,
            'add_ons_cents' => $this->addOnsCents,
            'add_ons' => $this->addOnLines,
            'mandatory_operator_fees_cents' => $this->operatorFeesCents,
            'operator_fees' => $this->operatorFeeLines,
            'discount_cents' => $this->discountCents,
            'promo_code' => $this->promoCode,
            'tax_includes_booking_fee' => $this->taxIncludesBookingFee,
            'charge_cents' => $this->payment->chargeCents,
            'application_fee_cents' => $this->payment->applicationFeeCents,
            'deposit_was_raised' => $this->payment->depositWasRaised(),
            'time_windows' => $this->timeWindows,
            'party_bands' => $this->partyBands,
            'group_rules' => GroupPriceCopy::rules($this->partyBands),
        ];

        if ($this->taxLines !== []) {
            $snapshot['tax_lines'] = $this->taxLines;
        }

        return $snapshot;
    }
}
