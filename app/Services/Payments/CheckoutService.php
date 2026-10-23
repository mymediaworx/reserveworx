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
use App\Services\Notifications\BookingEvents;
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
    public function startCheckout(Booking $booking, string $successUrl, string $cancelUrl): array
    {
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

        $payment = Payment::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'pending',
            'amount_cents' => $charge,
            'fee_cents' => $fee,
            'stripe_checkout_session_id' => $session['id'],
        ]);

        return ['payment' => $payment, 'url' => $session['url']];
    }

    public function recordOffline(Booking $booking): Payment
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            throw new AllocationUnavailableException('Price this booking before taking payment.');
        }

        $charge = (int) ($snapshot->payload['customer_total_cents'] ?? $snapshot->payload['charge_cents'] ?? 0);
        $payment = Payment::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => $charge,
            'fee_cents' => 0,
        ]);

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
}
