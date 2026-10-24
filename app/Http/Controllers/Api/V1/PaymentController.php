<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\ConnectAccountSpec;
use App\Domain\Payments\StripeGateway;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\FeeLedger;
use App\Services\Payments\RefundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly StripeGateway $stripe,
        private readonly AuditLogger $audit,
        private readonly FeeLedger $ledger,
    ) {}

    public function connect(Request $request): JsonResponse
    {
        $this->authorize('create', Booking::class);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'refresh_url' => ['required', 'url', 'max:255'],
            'return_url' => ['required', 'url', 'max:255'],
        ]);

        $existing = StripeAccount::query()->where('tenant_id', $tenant->id)->first();

        try {
            if (! $existing instanceof StripeAccount) {
                $created = $this->stripe->createMerchantAccount(ConnectAccountSpec::merchant(
                    $tenant->id,
                    $validated['display_name'],
                    $validated['email'],
                ));
                $existing = StripeAccount::query()->create([
                    'tenant_id' => $tenant->id,
                    'stripe_account_id' => $created['id'],
                    'card_payments_status' => $created['card_payments_status'],
                    'livemode' => $created['livemode'],
                ]);
            }

            $link = $this->stripe->createOnboardingLink(ConnectAccountSpec::onboardingLink(
                $existing->stripe_account_id,
                $validated['refresh_url'],
                $validated['return_url'],
            ));
        } catch (StripeNotConfiguredException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $this->audit->log('stripe.account_linked', $existing, [], request: $request);

        return response()->json(['data' => [
            'stripe_account_id' => $existing->stripe_account_id,
            'card_payments_status' => $existing->card_payments_status,
            'onboarding_url' => $link['url'],
        ]]);
    }

    public function checkout(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'success_url' => ['required', 'url', 'max:255'],
            'cancel_url' => ['required', 'url', 'max:255'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        try {
            $started = $this->checkout->startCheckout(
                $booking->load('service'),
                $validated['success_url'],
                $validated['cancel_url'],
                $this->idempotencyKey($request, $validated['idempotency_key'] ?? null),
            );
        } catch (AllocationUnavailableException|IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($started['url'] !== null) {
            $this->audit->log('payment.checkout_started', $started['payment'], [
                'booking_id' => $booking->id,
                'amount_cents' => $started['payment']->amount_cents,
                'fee_cents' => $started['payment']->fee_cents,
            ], request: $request);
        }

        return response()->json(['data' => [
            'payment_id' => $started['payment']->id,
            'checkout_url' => $started['url'],
            'amount_cents' => $started['payment']->amount_cents,
            'fee_cents' => $started['payment']->fee_cents,
        ]]);
    }

    public function balance(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'success_url' => ['required', 'url', 'max:255'],
            'cancel_url' => ['required', 'url', 'max:255'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        try {
            $started = $this->checkout->startBalanceCheckout(
                $booking->load('service'),
                $validated['success_url'],
                $validated['cancel_url'],
                $this->idempotencyKey($request, $validated['idempotency_key'] ?? null),
            );
        } catch (AllocationUnavailableException|IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($started['url'] !== null) {
            $this->audit->log('payment.balance_checkout_started', $started['payment'], [
                'booking_id' => $booking->id,
                'amount_cents' => $started['payment']->amount_cents,
                'fee_cents' => $started['payment']->fee_cents,
            ], request: $request);
        }

        return response()->json(['data' => [
            'payment_id' => $started['payment']->id,
            'checkout_url' => $started['url'],
            'amount_cents' => $started['payment']->amount_cents,
            'fee_cents' => $started['payment']->fee_cents,
        ]]);
    }

    public function increase(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'success_url' => ['required', 'url', 'max:255'],
            'cancel_url' => ['required', 'url', 'max:255'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        try {
            $started = $this->checkout->startIncreaseCheckout(
                $booking,
                $validated['success_url'],
                $validated['cancel_url'],
                $this->idempotencyKey($request, $validated['idempotency_key'] ?? null),
            );
        } catch (AllocationUnavailableException|IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($started['url'] !== null) {
            $this->audit->log('payment.increase_checkout_started', $started['payment'], [
                'booking_id' => $booking->id,
                'amount_cents' => $started['payment']->amount_cents,
                'fee_cents' => $started['payment']->fee_cents,
            ], request: $request);
        }

        return response()->json(['data' => [
            'payment_id' => $started['payment']->id,
            'checkout_url' => $started['url'],
            'amount_cents' => $started['payment']->amount_cents,
            'fee_cents' => $started['payment']->fee_cents,
        ]]);
    }

    public function decrease(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $user = $request->user();

        try {
            $refund = $this->checkout->refundPriceDecrease(
                $booking,
                $this->idempotencyKey($request, $validated['idempotency_key'] ?? null),
                $user instanceof User ? $user : null,
            );
        } catch (StripeNotConfiguredException|RefundException|AllocationUnavailableException|IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $fresh = $booking->fresh();

        return response()->json(['data' => [
            'id' => $refund->id,
            'customer_refund_cents' => $refund->customer_refund_cents,
            'customer_fee_refund_cents' => $refund->customer_fee_refund_cents,
            'application_fee_refund_cents' => $refund->application_fee_refund_cents,
            'refund_subtotal_cents' => $refund->refund_subtotal_cents,
            'refund_tax_cents' => $refund->refund_tax_cents,
            'stripe_refund_id' => $refund->stripe_refund_id,
            'stripe_fee_refund_id' => $refund->stripe_fee_refund_id,
            'payment_status' => $fresh instanceof Booking ? $fresh->payment_status->value : $booking->payment_status->value,
        ]], 201);
    }

    public function offline(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        try {
            $payment = $this->checkout->recordOffline(
                $booking,
                $this->idempotencyKey($request, $validated['idempotency_key'] ?? null),
            );
        } catch (AllocationUnavailableException|IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $this->audit->log('payment.offline', $payment, ['booking_id' => $booking->id], request: $request);
        $fresh = $booking->fresh();

        return response()->json(['data' => [
            'payment_id' => $payment->id,
            'fee_cents' => 0,
            'payment_status' => $fresh?->payment_status->value,
            'status' => $fresh?->status->value,
        ]]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);
        $validated = $request->validate([
            'booking_id' => ['sometimes', 'string', 'ulid'],
        ]);
        $bookingId = $validated['booking_id'] ?? null;

        if (is_string($bookingId)) {
            $this->authorize('view', Booking::query()->findOrFail($bookingId));
        }

        return response()->json([
            'data' => $this->ledger->build(is_string($bookingId) ? $bookingId : null),
        ]);
    }

    private function idempotencyKey(Request $request, mixed $body): ?string
    {
        $header = $request->headers->get('Idempotency-Key');
        $key = is_string($header) && $header !== '' ? $header : $body;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
