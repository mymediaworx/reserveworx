<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fees\Refunds\RefundReason;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\Payments\RefundException;
use App\Services\Payments\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function store(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in(array_column(RefundReason::cases(), 'value'))],
            'refund_subtotal_cents' => ['sometimes', 'integer', 'min:0'],
            'refund_tax_cents' => ['sometimes', 'integer', 'min:0'],
            'idempotency_key' => ['sometimes', 'nullable', 'string'],
        ]);
        $user = $request->user();
        $key = $this->idempotencyKey($request, $validated['idempotency_key'] ?? null);
        $reason = RefundReason::from($validated['reason']);
        $subtotal = (int) ($validated['refund_subtotal_cents'] ?? 0);
        $tax = (int) ($validated['refund_tax_cents'] ?? 0);

        if ($reason->recordedByStripe()) {
            return response()->json([
                'message' => 'A dispute outcome comes from Stripe. It cannot be entered as a refund.',
            ], 422);
        }

        try {
            $this->refunds->rejectDifferentRequest($booking, $reason, $subtotal, $tax, $key);
            $refund = $this->refunds->issue(
                $booking,
                $reason,
                $subtotal,
                $tax,
                $key,
                $user instanceof User ? $user : null,
            );
        } catch (IdempotencyConflictException|StripeNotConfiguredException|RefundException $exception) {
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
            'stripe_refund_id' => $refund->stripe_refund_id,
            'stripe_fee_refund_id' => $refund->stripe_fee_refund_id,
            'payment_status' => $fresh instanceof Booking ? $fresh->payment_status->value : $booking->payment_status->value,
        ]], 201);
    }

    private function idempotencyKey(Request $request, mixed $body): ?string
    {
        $header = $request->headers->get('Idempotency-Key');
        $key = is_string($header) && $header !== '' ? $header : (is_string($body) && $body !== '' ? $body : null);

        if ($key === null) {
            return null;
        }

        if (strlen($key) > 80) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'An idempotency key must be 80 characters or fewer.',
            ]);
        }

        return $key;
    }
}
