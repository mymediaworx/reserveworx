<?php

namespace App\Domain\Fees;

use App\Enums\FeePayer;

/**
 * The booking fee for a booking's current price, and what the customer owes
 * under the tenant's fee payer mode (spec Sections 7.4 and 7.5).
 */
final readonly class BookingFeeQuote
{
    public function __construct(
        public FeeChannel $channel,
        public FeeRate $rate,
        public FeePayer $payer,
        public int $basisCents,
        public int $taxCents,
        public int $feeCents,
    ) {}

    /**
     * Customer-paid: a separate "Booking fee" line equal to the fee.
     * Operator-absorbed: no customer-facing fee line.
     */
    public function customerFeeLineCents(): ?int
    {
        return $this->payer === FeePayer::CustomerPaid ? $this->feeCents : null;
    }

    /**
     * Customer-paid: subtotal + taxes + booking fee.
     * Operator-absorbed: subtotal + taxes.
     */
    public function customerTotalCents(): int
    {
        return $this->basisCents + $this->taxCents + ($this->customerFeeLineCents() ?? 0);
    }

    /**
     * Values for the booking's pricing snapshot (spec Section 7.1).
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'rules_version' => FeeCalculator::RULES_VERSION,
            'channel' => $this->channel->value,
            'rate' => $this->rate->toArray(),
            'fee_payer' => $this->payer->value,
            'basis_cents' => $this->basisCents,
            'tax_cents' => $this->taxCents,
            'fee_cents' => $this->feeCents,
            'customer_fee_line_cents' => $this->customerFeeLineCents(),
            'customer_total_cents' => $this->customerTotalCents(),
        ];
    }
}
