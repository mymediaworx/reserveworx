<?php

namespace App\Services\Payments;

use App\Domain\Fees\Refunds\FeeRefund;
use App\Domain\Fees\Refunds\FeeRefundCalculator;
use App\Domain\Fees\Refunds\FeeRefundState;
use App\Domain\Fees\Refunds\RefundMethod;
use App\Domain\Fees\Refunds\RefundReason;
use App\Domain\Fees\Refunds\StripeFeeRefundMethod;
use App\Domain\Payments\StripeGateway;
use App\Enums\FeePayer;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\StripeAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\NotificationHooks;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RefundService
{
    public function __construct(
        private readonly FeeRefundCalculator $calculator,
        private readonly StripeGateway $stripe,
        private readonly AuditLogger $audit,
        private readonly NotificationHooks $hooks,
    ) {}

    public function issue(
        Booking $booking,
        RefundReason $reason,
        int $refundSubtotalCents,
        int $refundTaxCents,
        ?string $idempotencyKey,
        ?User $actor,
    ): Refund {
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing instanceof Refund) {
                return $existing;
            }
        }

        $state = $this->state($booking);
        $plan = $this->calculator->calculate($state, $reason, $refundSubtotalCents, $refundTaxCents);

        if ($plan->customerRefundCents === 0 && $plan->applicationFeeRefundCents === 0 && $plan->method !== RefundMethod::None) {
            throw new InvalidArgumentException('Nothing left to refund.');
        }

        $stripeIds = $this->execute($booking, $plan, $idempotencyKey);
        $storedSubtotal = $plan->method === RefundMethod::Full
            ? $state->remainingSubtotalCents()
            : $plan->refundSubtotalCents;

        $refund = Refund::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'payment_id' => $stripeIds['payment_id'],
            'reason' => $reason->value,
            'rules_version' => FeeRefundCalculator::RULES_VERSION,
            'customer_refund_cents' => $plan->customerRefundCents,
            'customer_fee_refund_cents' => $plan->customerFeeRefundCents,
            'application_fee_refund_cents' => $plan->applicationFeeRefundCents,
            'refund_subtotal_cents' => $storedSubtotal,
            'refund_tax_cents' => $plan->refundTaxCents,
            'stripe_refund_id' => $stripeIds['refund_id'],
            'stripe_fee_refund_id' => $stripeIds['fee_refund_id'],
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
        ]);

        $fee = PlatformFee::query()->where('booking_id', $booking->id)->orderBy('created_at')->first();

        if ($fee instanceof PlatformFee && $plan->applicationFeeRefundCents > 0) {
            $fee->refunded_cents = min($fee->amount_cents, $fee->refunded_cents + $plan->applicationFeeRefundCents);
            $fee->save();
        }

        $booking->payment_status = $this->paymentStatus($booking, $plan);
        $booking->save();

        $this->audit->log('payment.refund', $refund, $plan->toAuditArray(), actor: $actor);
        $this->hooks->record(
            $booking->tenant_id,
            $booking->id,
            'booking.refunded',
            'booking.refunded:'.$refund->id,
            [
                'reason' => $reason->value,
                'customer_refund_cents' => $plan->customerRefundCents,
                'customer_fee_refund_cents' => $plan->customerFeeRefundCents,
                'application_fee_refund_cents' => $plan->applicationFeeRefundCents,
            ],
        );

        return $refund;
    }

    private function state(Booking $booking): FeeRefundState
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            throw new RefundException('Price this booking before refunding it.');
        }

        $payload = $snapshot->payload;
        $payer = FeePayer::tryFrom(is_string($payload['fee_payer'] ?? null) ? $payload['fee_payer'] : '');
        $paid = (int) Payment::query()
            ->where('booking_id', $booking->id)
            ->where('method', 'stripe')
            ->where('status', '!=', 'pending')
            ->sum('amount_cents');
        $refundedSubtotal = (int) Refund::query()->where('booking_id', $booking->id)->sum('refund_subtotal_cents');
        $feeCharged = (int) ($payload['application_fee_cents'] ?? $payload['fee_cents'] ?? 0);
        $refundedFee = (int) Refund::query()->where('booking_id', $booking->id)->sum('application_fee_refund_cents');
        $refundedFee += $this->manualFeeOnFirstCharge($booking);

        if ($refundedFee > $feeCharged) {
            $refundedFee = $feeCharged;
        }

        $refundedCustomer = (int) Refund::query()->where('booking_id', $booking->id)->sum('customer_refund_cents');

        return new FeeRefundState(
            feePayer: $payer ?? FeePayer::CustomerPaid,
            subtotalCents: (int) ($payload['basis_cents'] ?? 0),
            feeChargedCents: $feeCharged,
            paidCents: $paid,
            refundedSubtotalCents: $refundedSubtotal,
            refundedFeeCents: $refundedFee,
            refundedCustomerCents: $refundedCustomer,
        );
    }

    /**
     * @return array{payment_id: ?string, refund_id: ?string, fee_refund_id: ?string}
     */
    private function execute(Booking $booking, FeeRefund $plan, ?string $idempotencyKey): array
    {
        $none = [
            'payment_id' => null,
            'refund_id' => null,
            'fee_refund_id' => null,
        ];

        if ($plan->stripeMethod === StripeFeeRefundMethod::None && $plan->customerRefundCents === 0) {
            return $none;
        }

        $payment = $this->stripePayment($booking);
        $account = StripeAccount::query()->first();
        $key = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : (string) Str::ulid();

        if (! $account instanceof StripeAccount || ! $account->canCharge() || ! is_string($payment->stripe_payment_intent_id) || $payment->stripe_payment_intent_id === '') {
            throw new RefundException('Online payments are not enabled for this workspace.');
        }

        $feeId = null;

        if ($plan->stripeMethod === StripeFeeRefundMethod::ApplicationFeeRefund && $plan->applicationFeeRefundCents > 0) {
            $fee = PlatformFee::query()->where('booking_id', $booking->id)->orderBy('created_at')->first();
            $found = $fee instanceof PlatformFee ? $fee->stripe_fee_id : null;
            $feeId = is_string($found) && $found !== '' ? $found : null;

            if ($feeId === null) {
                throw new RefundException('The Stripe fee has not been recorded yet.');
            }
        }

        $refundId = null;

        if ($plan->stripeMethod === StripeFeeRefundMethod::RefundApplicationFeeFlag || $plan->customerRefundCents > 0) {
            $params = ['payment_intent' => $payment->stripe_payment_intent_id];

            if ($plan->customerRefundCents > 0) {
                $params['amount'] = $plan->customerRefundCents;
            }

            if ($plan->stripeMethod === StripeFeeRefundMethod::RefundApplicationFeeFlag) {
                $params['refund_application_fee'] = true;
            }

            $refundId = $this->stripe->createRefund($account->stripe_account_id, $params, $key.'-charge')['id'];
        }

        $feeRefundId = null;

        if (is_string($feeId)) {
            $feeRefundId = $this->stripe->createApplicationFeeRefund($feeId, $plan->applicationFeeRefundCents, $key.'-fee')['id'];
        }

        return [
            'payment_id' => $payment->id,
            'refund_id' => $refundId,
            'fee_refund_id' => $feeRefundId,
        ];
    }

    private function manualFeeOnFirstCharge(Booking $booking): int
    {
        $feeId = PlatformFee::query()
            ->where('booking_id', $booking->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('id');

        if (! is_string($feeId) || $feeId === '') {
            return 0;
        }

        return (int) PlatformFeeRefund::query()->where('platform_fee_id', $feeId)->sum('amount_cents');
    }

    private function stripePayment(Booking $booking): Payment
    {
        $fee = PlatformFee::query()->where('booking_id', $booking->id)->orderBy('created_at')->first();
        $payment = $fee instanceof PlatformFee && is_string($fee->payment_id)
            ? Payment::query()->find($fee->payment_id)
            : null;

        if (! $payment instanceof Payment) {
            $payment = Payment::query()
                ->where('booking_id', $booking->id)
                ->where('method', 'stripe')
                ->where('status', '!=', 'pending')
                ->orderBy('created_at')
                ->first();
        }

        if (! $payment instanceof Payment) {
            throw new RefundException('This booking has no Stripe payment to refund.');
        }

        return $payment;
    }

    private function paymentStatus(Booking $booking, FeeRefund $plan): PaymentStatus
    {
        if ($plan->reason === RefundReason::DisputeLost) {
            return PaymentStatus::Disputed;
        }

        if ($plan->customerRefundCents <= 0) {
            return $booking->payment_status;
        }

        $remaining = $plan->before->remainingPaidCents() - $plan->customerRefundCents;

        return $remaining <= 0 ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
    }
}
