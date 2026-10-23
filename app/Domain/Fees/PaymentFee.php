<?php

namespace App\Domain\Fees;

/**
 * What one payment charges and which application fee goes with it.
 */
final readonly class PaymentFee
{
    public function __construct(
        public PaymentKind $kind,
        public int $requestedCents,
        public int $chargeCents,
        public int $applicationFeeCents,
    ) {}

    /**
     * Value for Stripe's `application_fee_amount`, or null when it must be omitted.
     * Stripe requires a positive amount, so a zero fee is never sent.
     */
    public function stripeApplicationFeeAmount(): ?int
    {
        return $this->applicationFeeCents > 0 ? $this->applicationFeeCents : null;
    }

    /**
     * True when the deposit was raised to fee + $1.00 and the customer must be shown the new amount.
     */
    public function depositWasRaised(): bool
    {
        return $this->chargeCents > $this->requestedCents;
    }
}
