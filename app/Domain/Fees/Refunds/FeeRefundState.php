<?php

namespace App\Domain\Fees\Refunds;

use App\Enums\FeePayer;
use InvalidArgumentException;

/**
 * What has been charged and refunded so far on a booking (or on one charge,
 * such as a duplicate charge), in integer cents.
 *
 * Refunds are computed from cumulative totals so repeated partial refunds
 * never drift: the fee refunded after any sequence of refunds is the
 * proportional share of the total subtotal refunded, rounded once.
 */
final readonly class FeeRefundState
{
    /**
     * @param  int  $subtotalCents  Fee basis the booking fee was charged on (taxes excluded).
     * @param  int  $feeChargedCents  Application fee collected on Stripe payments.
     * @param  int  $paidCents  Amount the customer has paid through Stripe.
     * @param  int  $refundedSubtotalCents  Subtotal already refunded.
     * @param  int  $refundedFeeCents  Application fee already refunded to the tenant.
     * @param  int  $refundedCustomerCents  Amount already refunded to the customer.
     */
    public function __construct(
        public FeePayer $feePayer,
        public int $subtotalCents,
        public int $feeChargedCents,
        public int $paidCents,
        public int $refundedSubtotalCents = 0,
        public int $refundedFeeCents = 0,
        public int $refundedCustomerCents = 0,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if (is_int($value) && $value < 0) {
                throw new InvalidArgumentException("{$name} must not be negative.");
            }
        }

        if ($feeChargedCents > 0 && $subtotalCents === 0) {
            throw new InvalidArgumentException('A booking fee requires a positive subtotal.');
        }

        if ($refundedSubtotalCents > $subtotalCents) {
            throw new InvalidArgumentException('Refunded subtotal cannot exceed the subtotal.');
        }

        if ($refundedFeeCents > $feeChargedCents) {
            throw new InvalidArgumentException('Refunded fee cannot exceed the fee charged.');
        }

        if ($refundedCustomerCents > $paidCents) {
            throw new InvalidArgumentException('Refunded amount cannot exceed the amount paid.');
        }
    }

    public function remainingFeeCents(): int
    {
        return $this->feeChargedCents - $this->refundedFeeCents;
    }

    public function remainingPaidCents(): int
    {
        return $this->paidCents - $this->refundedCustomerCents;
    }

    public function remainingSubtotalCents(): int
    {
        return $this->subtotalCents - $this->refundedSubtotalCents;
    }

    public function hasPriorRefunds(): bool
    {
        return $this->refundedFeeCents > 0 || $this->refundedCustomerCents > 0 || $this->refundedSubtotalCents > 0;
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'fee_payer' => $this->feePayer->value,
            'subtotal_cents' => $this->subtotalCents,
            'fee_charged_cents' => $this->feeChargedCents,
            'paid_cents' => $this->paidCents,
            'refunded_subtotal_cents' => $this->refundedSubtotalCents,
            'refunded_fee_cents' => $this->refundedFeeCents,
            'refunded_customer_cents' => $this->refundedCustomerCents,
        ];
    }
}
