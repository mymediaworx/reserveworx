<?php

namespace App\Domain\Fees\Refunds;

/**
 * The outcome of one refund under spec Section 7.7.
 */
final readonly class FeeRefund
{
    public function __construct(
        public RefundReason $reason,
        public FeeRefundRule $rule,
        public RefundMethod $method,
        public int $customerRefundCents,
        public int $customerFeeRefundCents,
        public int $applicationFeeRefundCents,
        public StripeFeeRefundMethod $stripeMethod,
        public bool $customerHandledByDispute,
        public FeeRefundState $before,
        public int $refundSubtotalCents,
        public int $refundTaxCents,
    ) {}

    /**
     * Record for the fee ledger and audit log. The caller adds the actor (system or user).
     *
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        return [
            'rules_version' => FeeRefundCalculator::RULES_VERSION,
            'reason_code' => $this->reason->value,
            'rule' => $this->rule->value,
            'rule_description' => $this->rule->description(),
            'method' => $this->method->value,
            'customer_refund_cents' => $this->customerRefundCents,
            'customer_fee_refund_cents' => $this->customerFeeRefundCents,
            'application_fee_refund_cents' => $this->applicationFeeRefundCents,
            'stripe_method' => $this->stripeMethod->value,
            'customer_handled_by_dispute' => $this->customerHandledByDispute,
            'refund_subtotal_cents' => $this->refundSubtotalCents,
            'refund_tax_cents' => $this->refundTaxCents,
            'before' => $this->before->toArray(),
        ];
    }
}
