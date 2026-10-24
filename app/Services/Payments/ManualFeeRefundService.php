<?php

namespace App\Services\Payments;

use App\Domain\Fees\Refunds\RefundReason;
use App\Domain\Payments\StripeGateway;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\User;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use App\Tenancy\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Returns part of one application fee to the connected account.
 * The customer is not refunded. The reason is free text, never a Section 7.7 code.
 */
final class ManualFeeRefundService
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly AuditLogger $audit,
    ) {}

    public function issue(
        PlatformFee $fee,
        int $amountCents,
        string $reason,
        ?string $idempotencyKey,
        ?User $actor,
        ?Request $request = null,
    ): PlatformFeeRefund {
        $reason = trim($reason);
        $key = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null;
        $this->assertReason($reason);

        if ($amountCents < 1) {
            throw new InvalidArgumentException('The fee refund amount must be at least one cent.');
        }

        return DB::transaction(function () use ($fee, $amountCents, $reason, $key, $actor, $request): PlatformFeeRefund {
            $locked = PlatformFee::withoutGlobalScope(TenantScope::class)
                ->whereKey($fee->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof PlatformFee) {
                throw new RefundException('That booking fee was not found.');
            }

            if ($key !== null) {
                $existing = $this->findByKey($locked->tenant_id, $key);

                if ($existing instanceof PlatformFeeRefund) {
                    $this->assertSameRequest($existing, $locked, $amountCents, $reason);

                    return $existing;
                }
            }

            $remaining = $locked->amount_cents - $locked->refunded_cents;

            if ($amountCents > $remaining) {
                throw new InvalidArgumentException('The fee refund cannot exceed the booking fee still held.');
            }

            $feeId = $locked->stripe_fee_id;

            if (! is_string($feeId) || $feeId === '') {
                throw new RefundException('The Stripe fee has not been recorded yet.');
            }

            $stripeKey = $key !== null ? $key.'-manual-fee' : (string) Str::ulid();
            $stripeId = $this->stripe->createApplicationFeeRefund($feeId, $amountCents, $stripeKey)['id'];

            try {
                $stored = PlatformFeeRefund::query()->create([
                    'tenant_id' => $locked->tenant_id,
                    'platform_fee_id' => $locked->id,
                    'amount_cents' => $amountCents,
                    'reason' => $reason,
                    'rule' => PlatformFeeRefund::RULE,
                    'stripe_fee_refund_id' => $stripeId,
                    'idempotency_key' => $key,
                    'actor_user_id' => $actor instanceof User ? $actor->id : null,
                ]);
            } catch (QueryException $exception) {
                if ($key === null || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                    throw $exception;
                }

                $existing = $this->findByKey($locked->tenant_id, $key);

                if (! $existing instanceof PlatformFeeRefund) {
                    throw $exception;
                }

                $this->assertSameRequest($existing, $locked, $amountCents, $reason);

                return $existing;
            }

            $locked->refunded_cents = min($locked->amount_cents, $locked->refunded_cents + $amountCents);
            $locked->save();

            $this->audit->log(
                'platform.fee_refunded',
                $stored,
                [
                    'platform_fee_id' => $locked->id,
                    'booking_id' => $locked->booking_id,
                    'amount_cents' => $amountCents,
                    'reason' => $reason,
                    'rule' => PlatformFeeRefund::RULE,
                ],
                tenantId: $locked->tenant_id,
                actor: $actor,
                request: $request,
                useContextTenant: false,
            );

            return $stored;
        });
    }

    private function assertReason(string $reason): void
    {
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required.');
        }

        foreach (RefundReason::cases() as $case) {
            if ($reason === $case->value) {
                throw new InvalidArgumentException('A manual fee refund uses its own reason, not a Section 7.7 reason code.');
            }
        }
    }

    private function findByKey(string $tenantId, string $key): ?PlatformFeeRefund
    {
        $existing = PlatformFeeRefund::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $key)
            ->first();

        return $existing instanceof PlatformFeeRefund ? $existing : null;
    }

    private function assertSameRequest(PlatformFeeRefund $existing, PlatformFee $fee, int $amountCents, string $reason): void
    {
        if ($existing->platform_fee_id !== $fee->id || $existing->amount_cents !== $amountCents || $existing->reason !== $reason) {
            throw new IdempotencyConflictException;
        }
    }
}
