<?php

namespace App\Services\Payments;

use App\Domain\Fees\PaymentKind;
use App\Domain\Fees\Refunds\RefundReason;
use App\Domain\Payments\ConnectAccountSpec;
use App\Domain\Payments\StripeGateway;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\Allocation\Allocator;
use App\Services\Catalog\BookingOperatorFeeLines;
use App\Services\Notifications\BookingEvents;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

final class WebhookProcessor
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly Allocator $allocator,
        private readonly TenantManager $tenants,
        private readonly RefundService $refunds,
        private readonly BookingEvents $events,
        private readonly BookingOperatorFeeLines $operatorFees,
    ) {}

    public function handle(string $payload, string $signature): void
    {
        $event = $this->stripe->parseEvent($payload, $signature);
        $row = WebhookEvent::query()->where('stripe_event_id', $event['id'])->first();

        if ($row instanceof WebhookEvent && $row->processed_at !== null) {
            return;
        }

        if (! $row instanceof WebhookEvent) {
            try {
                $row = WebhookEvent::query()->create([
                    'stripe_event_id' => $event['id'],
                    'type' => $event['type'],
                    'stripe_account_id' => $event['account'],
                    'payload' => $event['object'],
                ]);
            } catch (QueryException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                    throw $exception;
                }

                $row = WebhookEvent::query()->where('stripe_event_id', $event['id'])->first();

                if (! $row instanceof WebhookEvent || $row->processed_at !== null) {
                    return;
                }
            }
        }

        match ($event['type']) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->fulfill($event['object']),
            'checkout.session.async_payment_failed' => $this->fail($event['object']),
            'application_fee.created' => $this->rememberFee($event['object']),
            'application_fee.refunded' => $this->rememberFeeRefund($event['object']),
            'charge.dispute.closed' => $this->rememberDispute($event['object']),
            default => $this->rememberCapability($event['object']),
        };

        $row->processed_at = now();
        $row->save();
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function fulfill(array $session): void
    {
        $paymentStatus = $session['payment_status'] ?? null;

        if ($paymentStatus !== 'paid' && $paymentStatus !== 'no_payment_required') {
            return;
        }

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $bookingId = $metadata['booking_id'] ?? null;

        if (! is_string($bookingId) || $bookingId === '') {
            return;
        }

        $booking = Booking::withoutGlobalScope(TenantScope::class)->find($bookingId);

        if (! $booking instanceof Booking) {
            return;
        }

        $tenant = Tenant::query()->find($booking->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $this->tenants->set($tenant);

        try {
            $sessionId = is_string($session['id'] ?? null) ? $session['id'] : null;
            $existing = $sessionId === null ? null : Payment::query()->where('stripe_checkout_session_id', $sessionId)->first();
            $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();
            $snapshotFee = $snapshot instanceof PricingSnapshot ? (int) ($snapshot->payload['application_fee_cents'] ?? 0) : 0;
            $fee = $existing instanceof Payment ? (int) $existing->fee_cents : $snapshotFee;
            $amount = is_numeric($session['amount_total'] ?? null)
                ? (int) $session['amount_total']
                : ($existing instanceof Payment ? $existing->amount_cents : 0);
            $intent = $session['payment_intent'] ?? null;
            $intentId = is_string($intent) ? $intent : null;

            $payment = $existing instanceof Payment ? $existing : Payment::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'method' => 'stripe',
                'kind' => 'first_stripe_payment',
                'status' => 'paid',
                'amount_cents' => $amount,
                'fee_cents' => $fee,
                'stripe_checkout_session_id' => $sessionId,
                'stripe_payment_intent_id' => $intentId,
            ]);
            $payment->status = 'paid';
            $payment->stripe_payment_intent_id = $intentId;
            $payment->save();
            $snapshot = $this->applyPendingIncrease($payment, $snapshot);

            $fresh = Booking::query()->findOrFail($booking->id);

            if ($fresh->status->value === 'held') {
                $fresh = $this->allocator->confirm($fresh);
            }

            if ($fresh->status->value !== 'payment_exception') {
                $total = $snapshot instanceof PricingSnapshot ? (int) ($snapshot->payload['customer_total_cents'] ?? $amount) : $amount;
                $paid = (int) Payment::query()->where('booking_id', $fresh->id)->where('status', 'paid')->sum('amount_cents');
                $fresh->payment_status = $total > 0 && $paid < $total ? PaymentStatus::DepositPaid : PaymentStatus::Paid;
                $fresh->save();
            }

            if ($fee > 0 && ! PlatformFee::query()->where('payment_id', $payment->id)->exists()) {
                $stored = $snapshot instanceof PricingSnapshot ? ($snapshot->payload['channel'] ?? null) : null;
                $channel = is_string($stored) && $stored !== '' ? $stored : 'direct';

                $recorded = PlatformFee::query()->create([
                    'tenant_id' => $fresh->tenant_id,
                    'booking_id' => $fresh->id,
                    'payment_id' => $payment->id,
                    'amount_cents' => $fee,
                    'refunded_cents' => 0,
                    'channel' => $channel,
                ]);
                $this->linkStripeFee($recorded);
            }

            $this->events->paymentCompleted($payment);
        } finally {
            $this->tenants->forget();
        }
    }

    /**
     * Fold a paid price increase into the snapshot once. A later delivery of the
     * same payment finds no pending row and leaves the totals alone.
     */
    private function applyPendingIncrease(Payment $payment, ?PricingSnapshot $snapshot): ?PricingSnapshot
    {
        if (! $snapshot instanceof PricingSnapshot || $payment->kind !== PaymentKind::PriceIncreaseStripePayment->value) {
            return $snapshot;
        }

        $payload = $snapshot->payload;
        $pending = $payload['pending_increase'] ?? null;

        if (! is_array($pending) || ($pending['payment_id'] ?? null) !== $payment->id) {
            return $snapshot;
        }

        /** @var array<string, mixed> $pending */
        $payload['services_cents'] = max(0, $this->cents($payload, 'services_cents') + $this->cents($pending, 'services_delta_cents'));
        $payload['discount_cents'] = max(0, $this->cents($payload, 'discount_cents') + $this->cents($pending, 'discount_delta_cents'));

        if (array_key_exists('add_ons_delta_cents', $pending)) {
            $payload['add_ons_cents'] = $this->cents($payload, 'add_ons_cents') + $this->cents($pending, 'add_ons_delta_cents');
        }

        if (is_array($pending['add_ons'] ?? null)) {
            $payload['add_ons'] = $pending['add_ons'];
        }

        $addedBasis = $this->cents($pending, 'basis_cents');
        $payload['basis_cents'] = $this->cents($payload, 'basis_cents') + $addedBasis;

        if (is_numeric($payload['charged_basis_cents'] ?? null)) {
            $payload['charged_basis_cents'] = (int) $payload['charged_basis_cents'] + $addedBasis;
        }
        $payload['tax_cents'] = $this->cents($payload, 'tax_cents') + $this->cents($pending, 'tax_cents');

        if (is_array($pending['tax_lines'] ?? null)) {
            $payload['tax_lines'] = $pending['tax_lines'];
        }
        $payload['fee_cents'] = $this->cents($payload, 'fee_cents') + $this->cents($pending, 'fee_cents');
        $payload['customer_total_cents'] = $this->cents($payload, 'customer_total_cents') + $this->cents($pending, 'charge_cents');

        $line = $payload['customer_fee_line_cents'] ?? null;
        $addedLine = $pending['customer_fee_line_cents'] ?? null;

        if (is_numeric($line) && is_numeric($addedLine)) {
            $payload['customer_fee_line_cents'] = (int) $line + (int) $addedLine;
        }

        foreach (['priced_participants', 'priced_duration_minutes', 'priced_on', 'priced_local_start', 'time_windows'] as $key) {
            if (array_key_exists($key, $pending)) {
                $payload[$key] = $pending[$key];
            }
        }

        $operatorLines = $pending['operator_fees'] ?? null;

        if (is_array($operatorLines)) {
            $booked = Booking::query()->find($payment->booking_id);

            if ($booked instanceof Booking) {
                $payload['operator_fees'] = $this->operatorFees->replace($booked, $operatorLines);
            }

            $payload['mandatory_operator_fees_cents'] = max(
                0,
                $this->cents($payload, 'mandatory_operator_fees_cents') + $this->cents($pending, 'operator_fees_delta_cents'),
            );
        }

        unset($payload['pending_increase']);
        $snapshot->payload = $payload;
        $snapshot->save();

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cents(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function fail(array $session): void
    {
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $bookingId = $metadata['booking_id'] ?? null;

        if (! is_string($bookingId) || $bookingId === '') {
            return;
        }

        $booking = Booking::withoutGlobalScope(TenantScope::class)->find($bookingId);

        if (! $booking instanceof Booking) {
            return;
        }

        $tenant = Tenant::query()->find($booking->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $sessionId = is_string($session['id'] ?? null) && $session['id'] !== '' ? $session['id'] : null;
        $payment = $sessionId === null ? null : Payment::withoutGlobalScope(TenantScope::class)
            ->where('stripe_checkout_session_id', $sessionId)
            ->first();

        if ($payment instanceof Payment && $payment->status === 'paid') {
            return;
        }

        $this->tenants->set($tenant);

        try {
            $this->events->paymentFailed(
                $booking->tenant_id,
                $booking->id,
                'payment.failed:'.($sessionId ?? $booking->id),
                $payment instanceof Payment ? $payment->id : null,
            );
        } finally {
            $this->tenants->forget();
        }
    }

    /**
     * @param  array<string, mixed>  $fee
     */
    private function rememberFee(array $fee): void
    {
        $feeId = $fee['id'] ?? null;

        if (! is_string($feeId) || $feeId === '') {
            return;
        }

        if (PlatformFee::withoutGlobalScope(TenantScope::class)->where('stripe_fee_id', $feeId)->exists()) {
            return;
        }

        $amount = is_numeric($fee['amount'] ?? null) ? (int) $fee['amount'] : null;
        $accountId = $fee['account'] ?? null;

        if (! is_string($accountId) || $accountId === '' || $amount === null) {
            return;
        }

        $account = StripeAccount::withoutGlobalScope(TenantScope::class)
            ->where('stripe_account_id', $accountId)
            ->first();

        if (! $account instanceof StripeAccount) {
            return;
        }

        $row = PlatformFee::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $account->tenant_id)
            ->whereNull('stripe_fee_id')
            ->where('amount_cents', $amount)
            ->orderBy('id')
            ->first();

        if ($row instanceof PlatformFee) {
            PlatformFee::withoutGlobalScope(TenantScope::class)
                ->where('id', $row->id)
                ->update(['stripe_fee_id' => $feeId]);
        }
    }

    private function linkStripeFee(PlatformFee $fee): void
    {
        $events = WebhookEvent::query()
            ->where('type', 'application_fee.created')
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            $payload = $event->payload;
            $feeId = $payload['id'] ?? null;
            $amount = $payload['amount'] ?? null;
            $accountId = $payload['account'] ?? null;

            if (! is_string($feeId) || ! is_numeric($amount) || ! is_string($accountId)) {
                continue;
            }

            if ((int) $amount !== $fee->amount_cents) {
                continue;
            }

            $account = StripeAccount::query()->where('stripe_account_id', $accountId)->first();

            if (! $account instanceof StripeAccount || $account->tenant_id !== $fee->tenant_id) {
                continue;
            }

            $taken = PlatformFee::withoutGlobalScope(TenantScope::class)->where('stripe_fee_id', $feeId)->exists();

            if ($taken) {
                continue;
            }

            $fee->stripe_fee_id = $feeId;
            $fee->save();

            return;
        }
    }

    /**
     * @param  array<string, mixed>  $fee
     */
    private function rememberFeeRefund(array $fee): void
    {
        $feeId = $fee['id'] ?? null;

        if (! is_string($feeId)) {
            return;
        }

        $amount = is_numeric($fee['amount_refunded'] ?? null) ? (int) $fee['amount_refunded'] : 0;
        PlatformFee::withoutGlobalScope(TenantScope::class)
            ->where('stripe_fee_id', $feeId)
            ->update(['refunded_cents' => $amount]);
    }

    /**
     * A lost dispute returns the remaining booking fee to the tenant. The customer
     * is handled by the dispute. A won dispute, or an inquiry that closes, returns nothing.
     * A payment or Stripe fee id that is not stored yet is left unfinished so Stripe retries.
     *
     * @param  array<string, mixed>  $dispute
     */
    private function rememberDispute(array $dispute): void
    {
        $status = is_string($dispute['status'] ?? null) ? $dispute['status'] : null;
        $reason = match ($status) {
            'lost' => RefundReason::DisputeLost,
            'won', 'warning_closed' => RefundReason::DisputeWon,
            default => null,
        };
        $intent = $this->disputePaymentIntentId($dispute);
        $disputeId = $dispute['id'] ?? null;

        if ($reason === null || ! is_string($disputeId) || $disputeId === '') {
            return;
        }

        if ($intent === null) {
            throw new RefundException('The disputed payment is not recorded yet.');
        }

        $payment = Payment::withoutGlobalScope(TenantScope::class)
            ->where('stripe_payment_intent_id', $intent)
            ->first();
        $booking = $payment instanceof Payment
            ? Booking::withoutGlobalScope(TenantScope::class)->find($payment->booking_id)
            : null;
        $tenant = $booking instanceof Booking ? Tenant::query()->find($booking->tenant_id) : null;

        if (! $payment instanceof Payment || ! $booking instanceof Booking || ! $tenant instanceof Tenant) {
            throw new RefundException('The disputed payment is not recorded yet.');
        }

        $this->tenants->set($tenant);

        try {
            if ($reason === RefundReason::DisputeWon) {
                $this->refunds->recordDisputeWithoutFeeRefund($booking, $disputeId, $reason, $status);

                return;
            }

            try {
                $this->refunds->issue($booking, $reason, 0, 0, 'dispute:'.$disputeId, null);
            } catch (InvalidArgumentException $exception) {
                if ($exception->getMessage() !== 'Nothing left to refund.') {
                    throw $exception;
                }

                $this->refunds->recordDisputeWithoutFeeRefund($booking, $disputeId, $reason, $status);
            }
        } finally {
            $this->tenants->forget();
        }
    }

    /**
     * @param  array<string, mixed>  $dispute
     */
    private function disputePaymentIntentId(array $dispute): ?string
    {
        $intent = $dispute['payment_intent'] ?? null;

        if (is_string($intent) && $intent !== '') {
            return $intent;
        }

        if (! is_array($intent)) {
            return null;
        }

        $id = $intent['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function rememberCapability(array $object): void
    {
        $accountId = $object['id'] ?? null;

        $status = ConnectAccountSpec::readCardPaymentsStatus($object);

        if (! is_string($accountId) || ! str_starts_with($accountId, 'acct_') || $status === null) {
            return;
        }

        StripeAccount::withoutGlobalScope(TenantScope::class)
            ->where('stripe_account_id', $accountId)
            ->update(['card_payments_status' => $status]);
    }
}
