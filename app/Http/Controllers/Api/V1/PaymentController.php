<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\ConnectAccountSpec;
use App\Domain\Payments\StripeGateway;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PlatformFee;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use App\Services\Payments\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly StripeGateway $stripe,
        private readonly AuditLogger $audit,
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

        return response()->json(['data' => [
            'payment_id' => $started['payment']->id,
            'checkout_url' => $started['url'],
            'amount_cents' => $started['payment']->amount_cents,
            'fee_cents' => $started['payment']->fee_cents,
        ]]);
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
        $fees = PlatformFee::query()->get();

        return response()->json(['data' => [
            'charged_cents' => (int) $fees->sum('amount_cents'),
            'refunded_cents' => (int) $fees->sum('refunded_cents'),
            'net_cents' => (int) $fees->sum('amount_cents') - (int) $fees->sum('refunded_cents'),
            'count' => $fees->count(),
        ]]);
    }

    private function idempotencyKey(Request $request, mixed $body): ?string
    {
        $header = $request->headers->get('Idempotency-Key');
        $key = is_string($header) && $header !== '' ? $header : $body;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
