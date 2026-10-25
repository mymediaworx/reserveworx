<?php

namespace App\Services\Payments;

use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeExceedsChargeException;
use App\Domain\Fees\FeeRate;
use App\Domain\Fees\PaymentKind;
use App\Domain\Fees\Refunds\RefundReason;
use App\Domain\Payments\CheckoutSessionSpec;
use App\Domain\Payments\StripeGateway;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Domain\Pricing\PriceDecrease;
use App\Domain\Pricing\PriceIncrease;
use App\Domain\Pricing\PriceRuleInput;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\VariantPrice;
use App\Enums\BookingStatus;
use App\Enums\FeePayer;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\StripeAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\Catalog\BookingAddOnLines;
use App\Services\Catalog\BookingOperatorFeeLines;
use App\Services\Notifications\BookingEvents;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CheckoutService
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly Allocator $allocator,
        private readonly BookingEvents $events,
        private readonly PricingEngine $pricing,
        private readonly RefundService $refunds,
        private readonly BookingAddOnLines $addOnLines,
        private readonly BookingOperatorFeeLines $operatorFees,
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

    /**
     * The remainder after a deposit. Spec 7.4: a later balance payment carries no booking fee.
     *
     * @return array{payment: Payment, url: string|null}
     */
    public function startBalanceCheckout(Booking $booking, string $successUrl, string $cancelUrl, ?string $idempotencyKey = null): array
    {
        $hash = $this->payloadHash('balance', $booking->id, $successUrl, $cancelUrl);
        $existing = $this->storedPayment($idempotencyKey, $hash);

        if ($existing instanceof Payment) {
            return ['payment' => $existing, 'url' => null];
        }

        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            throw new AllocationUnavailableException('Price this booking before taking payment.');
        }

        if ($booking->status !== BookingStatus::Confirmed || $booking->payment_status !== PaymentStatus::DepositPaid) {
            throw new AllocationUnavailableException('This booking has no balance due.');
        }

        $remaining = $this->customerTotalCents($snapshot->payload) - $this->paidTowardTotal($booking);

        if ($remaining <= 0) {
            throw new AllocationUnavailableException('This booking has no balance due.');
        }

        $open = Payment::query()
            ->where('booking_id', $booking->id)
            ->where('kind', PaymentKind::BalanceStripePayment->value)
            ->where('status', 'pending')
            ->exists();

        if ($open) {
            throw new AllocationUnavailableException('A balance checkout is already open.');
        }

        $account = StripeAccount::query()->where('tenant_id', $booking->tenant_id)->first();

        if (! $account instanceof StripeAccount || ! $account->canCharge()) {
            throw new AllocationUnavailableException('Online payments are not enabled for this workspace.');
        }

        $serviceName = $booking->service->name ?? 'Booking';
        $identifier = 'reserveworx_'.Str::lower(Str::random(8));
        $params = CheckoutSessionSpec::directCharge(
            $booking->tenant_id,
            $booking->id,
            $serviceName.' balance',
            $snapshot->currency,
            $remaining,
            null,
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
            'kind' => PaymentKind::BalanceStripePayment->value,
            'status' => 'pending',
            'amount_cents' => $remaining,
            'fee_cents' => 0,
            'stripe_checkout_session_id' => $session['id'],
        ], $idempotencyKey, $hash);

        if ($idempotencyKey !== null && $idempotencyKey !== '' && ! $payment->wasRecentlyCreated) {
            return ['payment' => $payment, 'url' => null];
        }

        return ['payment' => $payment, 'url' => $session['url']];
    }

    /**
     * A later Stripe payment for a price increase. Spec 7.4: the fee is the percentage on the increase only.
     *
     * @return array{payment: Payment, url: string|null}
     */
    public function startIncreaseCheckout(Booking $booking, string $successUrl, string $cancelUrl, ?string $idempotencyKey = null): array
    {
        $hash = $this->payloadHash('increase', $booking->id, $successUrl, $cancelUrl);
        $existing = $this->storedPayment($idempotencyKey, $hash);

        if ($existing instanceof Payment) {
            return ['payment' => $existing, 'url' => null];
        }

        $booking->loadMissing(['service', 'location']);
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            throw new AllocationUnavailableException('Price this booking before taking payment.');
        }

        if ($booking->status !== BookingStatus::Confirmed) {
            throw new AllocationUnavailableException('Only a confirmed booking can be charged for a price increase.');
        }

        if (! in_array($booking->payment_status, [PaymentStatus::DepositPaid, PaymentStatus::Paid], true)) {
            throw new AllocationUnavailableException('Pay this booking before charging a price increase.');
        }

        $payload = $snapshot->payload;
        [$addOnsCents, $addOnLines] = $this->currentAddOns($booking);
        $increase = $this->priceIncrease($booking, $payload, $addOnsCents);
        $open = Payment::query()
            ->where('booking_id', $booking->id)
            ->where('kind', PaymentKind::PriceIncreaseStripePayment->value)
            ->where('status', 'pending')
            ->exists();

        if ($open) {
            throw new AllocationUnavailableException('A price increase checkout is already open.');
        }

        $account = StripeAccount::query()->where('tenant_id', $booking->tenant_id)->first();

        if (! $account instanceof StripeAccount || ! $account->canCharge()) {
            throw new AllocationUnavailableException('Online payments are not enabled for this workspace.');
        }

        $charge = $increase->payment->chargeCents;
        $fee = $increase->payment->applicationFeeCents;
        $serviceName = $booking->service->name ?? 'Booking';
        $identifier = 'reserveworx_'.Str::lower(Str::random(8));
        $params = CheckoutSessionSpec::directCharge(
            $booking->tenant_id,
            $booking->id,
            $serviceName.' increase',
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
            'kind' => PaymentKind::PriceIncreaseStripePayment->value,
            'status' => 'pending',
            'amount_cents' => $charge,
            'fee_cents' => $fee,
            'stripe_checkout_session_id' => $session['id'],
        ], $idempotencyKey, $hash);

        if ($idempotencyKey !== null && $idempotencyKey !== '' && ! $payment->wasRecentlyCreated) {
            return ['payment' => $payment, 'url' => null];
        }

        $payload['pending_increase'] = [
            'payment_id' => $payment->id,
            'services_delta_cents' => $increase->servicesDeltaCents,
            'discount_delta_cents' => $increase->discountDeltaCents,
            'basis_cents' => $increase->basisCents,
            'tax_cents' => $increase->taxCents,
            'fee_cents' => $fee,
            'customer_fee_line_cents' => $increase->quote->customerFeeLineCents(),
            'charge_cents' => $charge,
            'add_ons_delta_cents' => $increase->addOnsDeltaCents,
            'add_ons_cents' => $addOnsCents,
            'add_ons' => $addOnLines,
            'priced_participants' => $booking->participants,
            'priced_duration_minutes' => $this->durationMinutes($booking),
            'priced_on' => $this->pricedOn($booking),
            'priced_local_start' => $this->pricedLocalStart($booking),
            'time_windows' => $increase->timeWindows,
            'operator_fees_delta_cents' => $increase->operatorFeesDeltaCents,
            'operator_fees' => $increase->operatorFeeLines,
        ];

        if (is_array($increase->taxLines)) {
            $payload['pending_increase']['tax_lines'] = $increase->taxLines;
        }
        $snapshot->payload = $payload;
        $snapshot->save();

        return ['payment' => $payment, 'url' => $session['url']];
    }

    /**
     * Refund the difference when a confirmed booking now prices lower than its snapshot.
     *
     * Spec 7.7: the booking fee already charged is returned in proportion to the
     * subtotal reduction. The customer is refunded that share plus the tax that fell.
     */
    public function refundPriceDecrease(Booking $booking, ?string $idempotencyKey, ?User $actor): Refund
    {
        return DB::transaction(function () use ($booking, $idempotencyKey, $actor): Refund {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->first();

            if (! $locked instanceof Booking) {
                throw new AllocationUnavailableException('This booking has no price decrease.');
            }

            $existing = $this->storedDecreaseRefund($locked, $idempotencyKey);

            if ($existing instanceof Refund) {
                return $existing;
            }

            $snapshot = PricingSnapshot::query()->where('booking_id', $locked->id)->lockForUpdate()->first();

            if (! $snapshot instanceof PricingSnapshot) {
                throw new AllocationUnavailableException('Price this booking before refunding a price decrease.');
            }

            if ($locked->status !== BookingStatus::Confirmed) {
                throw new AllocationUnavailableException('Only a confirmed booking can be refunded for a price decrease.');
            }

            if (! in_array($locked->payment_status, [PaymentStatus::DepositPaid, PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
                throw new AllocationUnavailableException('Pay this booking before refunding a price decrease.');
            }

            $payload = $snapshot->payload;

            if (is_array($payload['pending_increase'] ?? null)) {
                throw new AllocationUnavailableException('A price increase checkout is already open.');
            }

            [$addOnsCents, $addOnLines] = $this->currentAddOns($locked);
            $decrease = $this->priceDecrease($locked, $payload, $addOnsCents);

            try {
                $refund = $this->refunds->issue(
                    $locked,
                    RefundReason::PriceReduced,
                    $decrease->basisReductionCents,
                    $decrease->taxReductionCents,
                    $idempotencyKey,
                    $actor,
                );
            } catch (QueryException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                    throw $exception;
                }

                $stored = $this->storedDecreaseRefund($locked, $idempotencyKey);

                if (! $stored instanceof Refund) {
                    throw $exception;
                }

                return $stored;
            }

            if (! $refund->wasRecentlyCreated) {
                return $refund;
            }

            $this->applyPriceDecrease($snapshot, $payload, $locked, $decrease, $refund, $addOnLines);

            return $refund;
        });
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function customerTotalCents(array $payload): int
    {
        $total = $payload['customer_total_cents'] ?? 0;

        return is_numeric($total) ? (int) $total : 0;
    }

    private function paidTowardTotal(Booking $booking): int
    {
        return (int) Payment::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'paid')
            ->sum('amount_cents');
    }

    /**
     * Whether the booking's current schedule prices above or below its snapshot.
     * Reads the increase and decrease rules and does not charge or refund.
     *
     * @return 'increase'|'decrease'|null
     */
    public function pendingPriceChange(Booking $booking): ?string
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            return null;
        }

        [$addOnsCents] = $this->currentAddOns($booking);

        try {
            $this->priceIncrease($booking, $snapshot->payload, $addOnsCents);

            return 'increase';
        } catch (AllocationUnavailableException $exception) {
            if ($exception->getMessage() !== 'This booking has no price increase.') {
                return null;
            }
        }

        try {
            $this->priceDecrease($booking, $snapshot->payload, $addOnsCents);

            return 'decrease';
        } catch (AllocationUnavailableException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function priceIncrease(Booking $booking, array $payload, int $addOnsCents): PriceIncrease
    {
        $duration = $this->durationMinutes($booking);
        $onDate = $this->pricedOn($booking);
        $storedOn = $payload['priced_on'] ?? null;
        $storedDuration = $payload['priced_duration_minutes'] ?? null;
        $storedParticipants = $payload['priced_participants'] ?? null;
        $storedLocalStart = $payload['priced_local_start'] ?? null;
        $scheduleKnown = is_string($storedOn) && $storedOn !== ''
            && is_string($storedLocalStart)
            && is_numeric($storedDuration)
            && is_numeric($storedParticipants);

        if ($scheduleKnown
            && $storedOn === $onDate
            && $storedLocalStart === $this->pricedLocalStart($booking)
            && (int) $storedDuration === $duration
            && (int) $storedParticipants === $booking->participants
            && $this->cents($payload, 'add_ons_cents') === $addOnsCents) {
            throw new AllocationUnavailableException('This booking has no price increase.');
        }

        [$channel, $rate, $payer, $rules, $on] = $this->quoteInputs($booking, $payload);

        [$quotedOperatorFees, $operatorDefinitions] = $this->quotedOperatorFees($payload);

        try {
            return $this->pricing->increaseFromQuote(
                $channel,
                $rate,
                $payer,
                $rules,
                $booking->participants,
                $duration,
                $on,
                $this->cents($payload, 'tax_basis_points'),
                $this->optionalInt($payload, 'promo_percent_off'),
                $this->optionalInt($payload, 'promo_amount_off_cents'),
                $this->cents($payload, 'services_cents'),
                $this->cents($payload, 'discount_cents'),
                $this->cents($payload, 'tax_cents'),
                $this->cents($payload, 'add_ons_cents'),
                $addOnsCents,
                $quotedOperatorFees,
                $operatorDefinitions,
                $this->locationTaxRules($payload),
            );
        } catch (FeeExceedsChargeException|InvalidArgumentException $exception) {
            throw new AllocationUnavailableException($exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function priceDecrease(Booking $booking, array $payload, int $addOnsCents): PriceDecrease
    {
        $duration = $this->durationMinutes($booking);
        $onDate = $this->pricedOn($booking);
        $storedOn = $payload['priced_on'] ?? null;
        $storedDuration = $payload['priced_duration_minutes'] ?? null;
        $storedParticipants = $payload['priced_participants'] ?? null;
        $storedLocalStart = $payload['priced_local_start'] ?? null;
        $scheduleKnown = is_string($storedOn) && $storedOn !== ''
            && is_string($storedLocalStart)
            && is_numeric($storedDuration)
            && is_numeric($storedParticipants);

        if ($scheduleKnown
            && $storedOn === $onDate
            && $storedLocalStart === $this->pricedLocalStart($booking)
            && (int) $storedDuration === $duration
            && (int) $storedParticipants === $booking->participants
            && $this->cents($payload, 'add_ons_cents') === $addOnsCents) {
            throw new AllocationUnavailableException('This booking has no price decrease.');
        }

        [, , , $rules, $on] = $this->quoteInputs($booking, $payload);

        [$quotedOperatorFees, $operatorDefinitions] = $this->quotedOperatorFees($payload);

        try {
            return $this->pricing->decreaseFromQuote(
                $rules,
                $booking->participants,
                $duration,
                $on,
                $this->cents($payload, 'tax_basis_points'),
                $this->optionalInt($payload, 'promo_percent_off'),
                $this->optionalInt($payload, 'promo_amount_off_cents'),
                $this->cents($payload, 'services_cents'),
                $this->cents($payload, 'discount_cents'),
                $this->cents($payload, 'tax_cents'),
                $this->cents($payload, 'add_ons_cents'),
                $addOnsCents,
                $quotedOperatorFees,
                $operatorDefinitions,
                $this->locationTaxRules($payload),
            );
        } catch (InvalidArgumentException $exception) {
            throw new AllocationUnavailableException($exception->getMessage());
        }
    }

    private function storedDecreaseRefund(Booking $booking, ?string $key): ?Refund
    {
        if ($key === null || $key === '') {
            return null;
        }

        $existing = Refund::query()->where('idempotency_key', $key)->first();

        if (! $existing instanceof Refund) {
            return null;
        }

        if ($existing->booking_id !== $booking->id || $existing->reason !== RefundReason::PriceReduced->value) {
            throw new IdempotencyConflictException;
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{id: string, add_on_id: string, name: string, kind: string, pricing: string, unit_amount_cents: int, quantity: int, total_cents: int}>  $addOnLines
     */
    private function applyPriceDecrease(PricingSnapshot $snapshot, array $payload, Booking $booking, PriceDecrease $decrease, Refund $refund, array $addOnLines): void
    {
        if (! is_numeric($payload['charged_basis_cents'] ?? null)) {
            $payload['charged_basis_cents'] = $this->cents($payload, 'basis_cents');
        }

        $payload['services_cents'] = $decrease->servicesCents;
        $payload['add_ons_cents'] = $decrease->addOnsCents;
        $payload['add_ons'] = $addOnLines;
        $payload['discount_cents'] = $decrease->discountCents;
        $payload['basis_cents'] = max(0, $this->cents($payload, 'basis_cents') - $decrease->basisReductionCents);
        $payload['tax_cents'] = max(0, $this->cents($payload, 'tax_cents') - $decrease->taxReductionCents);

        if (is_array($decrease->taxLines)) {
            $payload['tax_lines'] = $decrease->taxLines;
        }
        $payload['fee_cents'] = max(0, $this->cents($payload, 'fee_cents') - $refund->application_fee_refund_cents);
        $payload['customer_total_cents'] = max(0, $this->cents($payload, 'customer_total_cents') - $refund->customer_refund_cents);

        $line = $payload['customer_fee_line_cents'] ?? null;

        if (is_numeric($line)) {
            $payload['customer_fee_line_cents'] = max(0, (int) $line - $refund->customer_fee_refund_cents);
        }

        $payload['priced_participants'] = $booking->participants;
        $payload['priced_duration_minutes'] = $this->durationMinutes($booking);
        $payload['priced_on'] = $this->pricedOn($booking);
        $payload['priced_local_start'] = $this->pricedLocalStart($booking);
        $payload['time_windows'] = $decrease->timeWindows;
        $payload['mandatory_operator_fees_cents'] = $decrease->operatorFeesCents;
        $payload['operator_fees'] = $this->operatorFees->replace($booking, $decrease->operatorFeeLines);
        $snapshot->payload = $payload;
        $snapshot->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: FeeChannel, 1: FeeRate, 2: FeePayer, 3: list<PriceRuleInput>, 4: CarbonImmutable}
     */
    private function quoteInputs(Booking $booking, array $payload): array
    {
        $channelValue = $payload['channel'] ?? FeeChannel::Direct->value;
        $channel = is_string($channelValue) ? (FeeChannel::tryFrom($channelValue) ?? FeeChannel::Direct) : FeeChannel::Direct;
        $payerValue = $payload['fee_payer'] ?? FeePayer::CustomerPaid->value;
        $payer = is_string($payerValue) ? (FeePayer::tryFrom($payerValue) ?? FeePayer::CustomerPaid) : FeePayer::CustomerPaid;
        $setting = TenantSetting::query()->where('tenant_id', $booking->tenant_id)->first();
        $rate = $setting instanceof TenantSetting
            ? FeeRate::fromTenantSetting($setting, $channel)
            : FeeRate::defaultFor($channel);
        $rules = VariantPrice::overrideBase(PriceRule::inputsFor($booking->service_id), VariantPrice::forBooking($booking));
        $zone = $booking->location !== null ? $booking->location->timezone : 'UTC';

        return [$channel, $rate, $payer, $rules, CarbonImmutable::instance($booking->starts_at)->timezone($zone)];
    }

    /**
     * @return array{0: int, 1: list<array{id: string, add_on_id: string, name: string, kind: string, pricing: string, unit_amount_cents: int, quantity: int, total_cents: int}>}
     */
    private function currentAddOns(Booking $booking): array
    {
        $lines = $this->addOnLines->snapshot($booking);
        $total = 0;

        foreach ($lines as $line) {
            $total += $line['total_cents'];
        }

        return [$total, $lines];
    }

    private function durationMinutes(Booking $booking): int
    {
        $seconds = CarbonImmutable::instance($booking->ends_at)->utc()->getTimestamp()
            - CarbonImmutable::instance($booking->starts_at)->utc()->getTimestamp();
        $duration = intdiv($seconds, 60);

        if ($duration < 1) {
            throw new AllocationUnavailableException('Choose a valid interval.');
        }

        return $duration;
    }

    private function pricedOn(Booking $booking): string
    {
        $zone = $booking->location !== null ? $booking->location->timezone : 'UTC';

        return CarbonImmutable::instance($booking->starts_at)->timezone($zone)->toDateString();
    }

    private function pricedLocalStart(Booking $booking): string
    {
        $zone = $booking->location !== null ? $booking->location->timezone : 'UTC';

        return CarbonImmutable::instance($booking->starts_at)->timezone($zone)->format('H:i');
    }

    /**
     * Lines locked on the snapshot. A snapshot from before operator fees has neither
     * the total nor the lines, and stays unchanged.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: list<array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null}>}
     */
    private function quotedOperatorFees(array $payload): array
    {
        $lines = $payload['operator_fees'] ?? null;

        if (! is_array($lines)) {
            return [0, []];
        }

        return [
            $this->cents($payload, 'mandatory_operator_fees_cents'),
            $this->operatorFees->definitionsFromLines($lines),
        ];
    }

    /**
     * Rates frozen on the snapshot. Missing rates keep the single tax basis points.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function locationTaxRules(array $payload): array
    {
        $rules = $payload['tax_rules'] ?? null;

        if (! is_array($rules)) {
            return [];
        }

        $clean = [];

        foreach ($rules as $rule) {
            if (is_array($rule)) {
                $clean[] = $rule;
            }
        }

        return $clean;
    }

    private function cents(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalInt(array $payload, string $key): ?int
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        return is_numeric($payload[$key]) ? (int) $payload[$key] : null;
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
