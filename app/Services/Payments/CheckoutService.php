<?php

namespace App\Services\Payments;

use App\Domain\Payments\CheckoutSessionSpec;
use App\Domain\Payments\StripeGateway;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Models\StripeAccount;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\Notifications\BookingEvents;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class CheckoutService
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly Allocator $allocator,
        private readonly BookingEvents $events,
    ) {}

    /**
     * @return array{payment: Payment, url: string|null}
     */
    public function startCheckout(Booking $booking, string $successUrl, string $cancelUrl, ?string $idempotencyKey = null): array
    {
        $hash = $this->payloadHash('checkout', $booking->id, $successUrl, $cancelUrl);
        $existing = $this->storedPayment($idempotencyKey, $hash);

        if ($existing instanceof Payment) {
            return ['payment' => $existing, 'url' => null];
        }

        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            throw new AllocationUnavailableException('Price this booking before taking payment.');
        }

        $account = StripeAccount::query()->where('tenant_id', $booking->tenant_id)->first();

        if (! $account instanceof StripeAccount || ! $account->canCharge()) {
            throw new AllocationUnavailableException('Online payments are not enabled for this workspace.');
        }

        $payload = $snapshot->payload;
        $charge = (int) ($payload['charge_cents'] ?? 0);
        $fee = (int) ($payload['application_fee_cents'] ?? 0);
        $serviceName = $booking->service->name ?? 'Booking';
        $identifier = 'reserveworx_'.Str::lower(Str::random(8));
        $params = CheckoutSessionSpec::directCharge(
            $booking->tenant_id,
            $booking->id,
            $serviceName,
            $snapshot->currency,
            $charge,
            $fee > 0 ? $fee : null,
            $successUrl,
            $cancelUrl,
            $identifier,
        );

        try {
            $session = $this->stripe->createDirectCheckoutSession($account->stripe_account_id, $params);
        } catch (StripeNotConfiguredException $exception) {
            throw new AllocationUnavailableException($exception->getMessage());
        }

        $payment = $this->createPayment([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'pending',
            'amount_cents' => $charge,
            'fee_cents' => $fee,
            'stripe_checkout_session_id' => $session['id'],
        ], $idempotencyKey, $hash);

        if ($idempotencyKey !== null && $idempotencyKey !== '' && ! $payment->wasRecentlyCreated) {
            return ['payment' => $payment, 'url' => null];
        }

        return ['payment' => $payment, 'url' => $session['url']];
    }

    public function recordOffline(Booking $booking, ?string $idempotencyKey = null): Payment
    {
        $hash = $this->payloadHash('offline', $booking->id);
        $existing = $this->storedPayment($idempotencyKey, $hash);

        if ($existing instanceof Payment) {
            return $existing;
        }

        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            throw new AllocationUnavailableException('Price this booking before taking payment.');
        }

        $charge = $this->offlineAmountCents($snapshot->payload);
        $payment = $this->createPayment([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => $charge,
            'fee_cents' => 0,
        ], $idempotencyKey, $hash);

        if ($idempotencyKey !== null && $idempotencyKey !== '' && ! $payment->wasRecentlyCreated) {
            return $payment;
        }

        if ($booking->status->value === 'held') {
            $booking = $this->allocator->confirm($booking);
        }

        if ($booking->status->value !== 'payment_exception') {
            $booking->payment_status = PaymentStatus::OfflinePaid;
            $booking->save();
        }

        $this->events->paymentCompleted($payment);

        return $payment;
    }

    /**
     * Cash and check do not include the customer booking-fee line. Operator-absorbed
     * quotes store that line as null, so the customer total is already the amount due.
     *
     * @param  array<string, mixed>  $payload
     */
    private function offlineAmountCents(array $payload): int
    {
        $total = $payload['customer_total_cents'] ?? $payload['charge_cents'] ?? 0;
        $line = $payload['customer_fee_line_cents'] ?? 0;
        $amount = (is_numeric($total) ? (int) $total : 0) - (is_numeric($line) ? (int) $line : 0);

        return max(0, $amount);
    }

    public function amountDueAtPickup(PricingSnapshot $snapshot): int
    {
        return $this->offlineAmountCents($snapshot->payload);
    }

    private function payloadHash(string $action, string $bookingId, ?string $successUrl = null, ?string $cancelUrl = null): string
    {
        $fields = [
            'action' => $action,
            'booking_id' => $bookingId,
        ];

        if ($cancelUrl !== null) {
            $fields['cancel_url'] = $cancelUrl;
        }

        if ($successUrl !== null) {
            $fields['success_url'] = $successUrl;
        }

        $encoded = json_encode($fields, JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
    }

    private function storedPayment(?string $key, string $hash): ?Payment
    {
        if ($key === null || $key === '') {
            return null;
        }

        $existing = Payment::query()->where('idempotency_key', $key)->first();

        if (! $existing instanceof Payment) {
            return null;
        }

        $stored = $existing->payload_hash;

        if (! is_string($stored) || ! hash_equals($stored, $hash)) {
            throw new IdempotencyConflictException;
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPayment(array $attributes, ?string $key, string $hash): Payment
    {
        if ($key !== null && $key !== '') {
            $attributes['idempotency_key'] = $key;
            $attributes['payload_hash'] = $hash;
        }

        try {
            return Payment::query()->create($attributes);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062 || $key === null || $key === '') {
                throw $exception;
            }

            $existing = $this->storedPayment($key, $hash);

            if (! $existing instanceof Payment) {
                throw $exception;
            }

            return $existing;
        }
    }
}
