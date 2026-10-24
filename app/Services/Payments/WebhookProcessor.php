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
        $addedBasis = $this->cents($pending, 'basis_cents');
        $payload['basis_cents'] = $this->cents($payload, 'basis_cents') + $addedBasis;

        if (is_numeric($payload['charged_basis_cents'] ?? null)) {
            $payload['charged_basis_cents'] = (int) $payload['charged_basis_cents'] + $addedBasis;
        }
        $payload['tax_cents'] = $this->cents($payload, 'tax_cents') + $this->cents($pending, 'tax_cents');
        $payload['fee_cents'] = $this->cents($payload, 'fee_cents') + $this->cents($pending, 'fee_cents');
        $payload['customer_total_cents'] = $this->cents($payload, 'customer_total_cents') + $this->cents($pending, 'charge_cents');

        $line = $payload['customer_fee_line_cents'] ?? null;
        $addedLine = $pending['customer_fee_line_cents'] ?? null;

        if (is_numeric($line) && is_numeric($addedLine)) {
            $payload['customer_fee_line_cents'] = (int) $line + (int) $addedLine;
        }

        foreach (['priced_participants', 'priced_duration_minutes', 'priced_on'] as $key) {
            if (array_key_exists($key, $pending)) {
                $payload[$key] = $pending[$key];
            }
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
     * @param  array<string, mixed>  $dispute
     */
    private function rememberDispute(array $dispute): void
    {
        $reason = match ($dispute['status'] ?? null) {
            'lost' => RefundReason::DisputeLost,
            'won', 'warning_closed' => RefundReason::DisputeWon,
            default => null,
        };
        $intent = $dispute['payment_intent'] ?? null;
        $disputeId = $dispute['id'] ?? null;

        if ($reason === null || ! is_string($intent) || $intent === '' || ! is_string($disputeId) || $disputeId === '') {
            return;
        }

        $payment = Payment::withoutGlobalScope(TenantScope::class)
            ->where('stripe_payment_intent_id', $intent)
            ->first();

        if (! $payment instanceof Payment) {
            return;
        }

        $booking = Booking::withoutGlobalScope(TenantScope::class)->find($payment->booking_id);
        $tenant = $booking instanceof Booking ? Tenant::query()->find($booking->tenant_id) : null;

        if (! $booking instanceof Booking || ! $tenant instanceof Tenant) {
            return;
        }

        $this->tenants->set($tenant);

        try {
            $this->refunds->issue($booking, $reason, 0, 0, 'dispute:'.$disputeId, null);
        } catch (RefundException|InvalidArgumentException) {
            return;
        } finally {
            $this->tenants->forget();
        }
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
