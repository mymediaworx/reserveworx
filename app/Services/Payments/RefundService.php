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
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\StripeAccount;
use App\Models\User;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use App\Services\Integrations\GhlMessages;
use App\Services\Notifications\NotificationHooks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RefundService
{
    public function __construct(
        private readonly FeeRefundCalculator $calculator,
        private readonly StripeGateway $stripe,
        private readonly AuditLogger $audit,
        private readonly NotificationHooks $hooks,
        private readonly GhlMessages $ghlMessages,
    ) {}

    public function issue(
        Booking $booking,
        RefundReason $reason,
        int $refundSubtotalCents,
        int $refundTaxCents,
        ?string $idempotencyKey,
        ?User $actor,
    ): Refund {
        $existing = $this->stored($idempotencyKey);

        if ($existing instanceof Refund) {
            $this->markLostDispute($booking, $reason);

            return $existing;
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

        $attributes = [
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
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $attributes['idempotency_key'] = $idempotencyKey;
            $attributes['payload_hash'] = $this->requestHash($reason, $refundSubtotalCents, $refundTaxCents);
        }

        try {
            $refund = Refund::query()->create($attributes);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            $existing = $this->stored($idempotencyKey);

            if (! $existing instanceof Refund) {
                throw $exception;
            }

            $this->markLostDispute($booking, $reason);

            return $existing;
        }

        $fee = PlatformFee::query()->where('booking_id', $booking->id)->orderBy('created_at')->first();

        if ($fee instanceof PlatformFee && $plan->applicationFeeRefundCents > 0) {
            $fee->refunded_cents = min($fee->amount_cents, $fee->refunded_cents + $plan->applicationFeeRefundCents);
            $fee->save();
        }

        $booking->payment_status = $this->paymentStatus($booking, $plan);
        $booking->save();

        $properties = $plan->toAuditArray();
        $properties['actor'] = $actor instanceof User ? 'user' : 'system';
        $disputeId = $this->disputeIdFromKey($idempotencyKey);

        if ($disputeId !== null) {
            $properties['dispute_id'] = $disputeId;
        }

        $this->audit->log('payment.refund', $refund, $properties, actor: $actor);

        if ($reason !== RefundReason::DisputeLost) {
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
            $this->ghlMessages->queueRefund($refund);
        }

        return $refund;
    }

    /**
     * Refuse a staff retry that reuses a key for another booking or another request.
     * A row stored before payload hashes existed still matches, so that retry returns
     * the first refund. Callers inside a settlement keep the first row themselves.
     */
    public function rejectDifferentRequest(
        Booking $booking,
        RefundReason $reason,
        int $refundSubtotalCents,
        int $refundTaxCents,
        ?string $idempotencyKey,
    ): void {
        $existing = $this->stored($idempotencyKey);

        if (! $existing instanceof Refund) {
            return;
        }

        if ($existing->booking_id !== $booking->id) {
            throw new IdempotencyConflictException;
        }

        $stored = $existing->payload_hash;
        $hash = $this->requestHash($reason, $refundSubtotalCents, $refundTaxCents);

        if (is_string($stored) && $stored !== '' && ! hash_equals($stored, $hash)) {
            throw new IdempotencyConflictException;
        }
    }

    private function stored(?string $idempotencyKey): ?Refund
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->first();

        return $existing instanceof Refund ? $existing : null;
    }

    private function requestHash(RefundReason $reason, int $refundSubtotalCents, int $refundTaxCents): string
    {
        $encoded = json_encode([
            'reason' => $reason->value,
            'refund_subtotal_cents' => $refundSubtotalCents,
            'refund_tax_cents' => $refundTaxCents,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
    }

    /**
     * A closed dispute that returns no booking fee. A loss still marks the booking disputed.
     * The same dispute id is recorded once.
     */
    public function recordDisputeWithoutFeeRefund(Booking $booking, string $disputeId, RefundReason $reason, ?string $status): void
    {
        $this->markLostDispute($booking, $reason);

        if ($this->disputeAlreadyRecorded($booking, $disputeId)) {
            return;
        }

        $this->audit->log('payment.dispute_closed', $booking, [
            'dispute_id' => $disputeId,
            'status' => $status,
            'reason_code' => $reason->value,
            'rule' => $reason->rule()->value,
            'rule_description' => $reason->rule()->description(),
            'rules_version' => FeeRefundCalculator::RULES_VERSION,
            'application_fee_refund_cents' => 0,
            'customer_refund_cents' => 0,
            'actor' => 'system',
        ]);
    }

    private function state(Booking $booking): FeeRefundState
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            throw new RefundException('Price this booking before refunding it.');
        }

        $payload = $snapshot->payload;
        $payer = FeePayer::tryFrom(is_string($payload['fee_payer'] ?? null) ? $payload['fee_payer'] : '');
        $chargedBasis = $payload['charged_basis_cents'] ?? $payload['basis_cents'] ?? 0;
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
            subtotalCents: is_numeric($chargedBasis) ? (int) $chargedBasis : 0,
            feeChargedCents: $feeCharged,
            paidCents: $paid,
            refundedSubtotalCents: $refundedSubtotal,
            refundedFeeCents: $refundedFee,
            refundedCustomerCents: $refundedCustomer,
        );
    }

    /**
     * A fee-only return, including a lost dispute, refunds the application fee from the
     * platform account. It does not require the connected account to accept new charges.
     *
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
        $key = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : (string) Str::ulid();
        $returnsCharge = $plan->stripeMethod === StripeFeeRefundMethod::RefundApplicationFeeFlag
            || $plan->customerRefundCents > 0;
        $feeId = $this->applicationFeeId($booking, $plan);
        $refundId = null;

        if ($returnsCharge) {
            $account = StripeAccount::query()->first();

            if (! $account instanceof StripeAccount || ! $account->canCharge() || ! is_string($payment->stripe_payment_intent_id) || $payment->stripe_payment_intent_id === '') {
                throw new RefundException('Online payments are not enabled for this workspace.');
            }

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

    private function applicationFeeId(Booking $booking, FeeRefund $plan): ?string
    {
        if ($plan->stripeMethod !== StripeFeeRefundMethod::ApplicationFeeRefund || $plan->applicationFeeRefundCents <= 0) {
            return null;
        }

        $fee = PlatformFee::query()->where('booking_id', $booking->id)->orderBy('created_at')->first();
        $found = $fee instanceof PlatformFee ? $fee->stripe_fee_id : null;

        if (! is_string($found) || $found === '') {
            throw new RefundException('The Stripe fee has not been recorded yet.');
        }

        return $found;
    }

    private function markLostDispute(Booking $booking, RefundReason $reason): void
    {
        if ($reason !== RefundReason::DisputeLost || $booking->payment_status === PaymentStatus::Disputed) {
            return;
        }

        $booking->payment_status = PaymentStatus::Disputed;
        $booking->save();
    }

    private function disputeIdFromKey(?string $idempotencyKey): ?string
    {
        if (! is_string($idempotencyKey) || ! str_starts_with($idempotencyKey, 'dispute:')) {
            return null;
        }

        $disputeId = substr($idempotencyKey, strlen('dispute:'));

        return $disputeId === '' ? null : $disputeId;
    }

    private function disputeAlreadyRecorded(Booking $booking, string $disputeId): bool
    {
        $logs = AuditLog::query()
            ->where('action', 'payment.dispute_closed')
            ->where('subject_type', Booking::class)
            ->where('subject_id', $booking->id)
            ->get();

        foreach ($logs as $log) {
            if (($log->properties['dispute_id'] ?? null) === $disputeId) {
                return true;
            }
        }

        return false;
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
