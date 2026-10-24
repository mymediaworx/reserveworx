<?php

namespace App\Domain\Pricing;

use App\Domain\Fees\BookingFeeQuote;
use App\Domain\Fees\PaymentFee;

final readonly class PricedBooking
{
    /**
     * @param  list<array<string, mixed>>  $addOnLines
     * @param  list<array<string, mixed>>  $timeWindows
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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return $this->quote->toSnapshot() + [
            'services_cents' => $this->servicesCents,
            'add_ons_cents' => $this->addOnsCents,
            'add_ons' => $this->addOnLines,
            'discount_cents' => $this->discountCents,
            'promo_code' => $this->promoCode,
            'tax_includes_booking_fee' => false,
            'charge_cents' => $this->payment->chargeCents,
            'application_fee_cents' => $this->payment->applicationFeeCents,
            'deposit_was_raised' => $this->payment->depositWasRaised(),
            'time_windows' => $this->timeWindows,
        ];
    }
}
