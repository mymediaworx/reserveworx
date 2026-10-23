<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fees\Refunds\RefundReason;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Services\Payments\RefundException;
use App\Services\Payments\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $user = $request->user();
        $key = $validated['idempotency_key'] ?? null;

        try {
            $refund = $this->refunds->issue(
                $booking,
                RefundReason::from($validated['reason']),
                (int) ($validated['refund_subtotal_cents'] ?? 0),
                (int) ($validated['refund_tax_cents'] ?? 0),
                is_string($key) ? $key : null,
                $user instanceof User ? $user : null,
            );
        } catch (StripeNotConfiguredException|RefundException $exception) {
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
}
