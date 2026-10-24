<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Payments\StripeNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Models\PlatformFee;
use App\Models\User;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\Payments\ManualFeeRefundService;
use App\Services\Payments\RefundException;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ManualFeeRefundController extends Controller
{
    public function __construct(private readonly ManualFeeRefundService $refunds) {}

    public function store(Request $request, string $fee): JsonResponse
    {
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $platformFee = PlatformFee::withoutGlobalScope(TenantScope::class)->whereKey($fee)->first();

        if (! $platformFee instanceof PlatformFee) {
            return response()->json(['message' => 'That booking fee was not found.'], 404);
        }

        $reason = $validated['reason'];
        $user = $request->user();

        try {
            $refund = $this->refunds->issue(
                $platformFee,
                (int) $validated['amount_cents'],
                is_string($reason) ? $reason : '',
                $this->idempotencyKey($request, $validated['idempotency_key'] ?? null),
                $user instanceof User ? $user : null,
                $request,
            );
        } catch (StripeNotConfiguredException|RefundException|IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => [
            'id' => $refund->id,
            'platform_fee_id' => $refund->platform_fee_id,
            'amount_cents' => $refund->amount_cents,
            'reason' => $refund->reason,
            'rule' => $refund->rule,
            'stripe_fee_refund_id' => $refund->stripe_fee_refund_id,
        ]], 201);
    }

    private function idempotencyKey(Request $request, mixed $body): ?string
    {
        $header = $request->headers->get('Idempotency-Key');
        $key = is_string($header) && $header !== '' ? $header : $body;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
