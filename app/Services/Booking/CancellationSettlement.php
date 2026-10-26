<?php

namespace App\Services\Booking;

use App\Domain\Fees\Refunds\RefundReason;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Domain\Pricing\CancellationRefund;
use App\Models\Booking;
use App\Models\PricingSnapshot;
use App\Models\User;
use App\Services\Payments\RefundException;
use App\Services\Payments\RefundService;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Refunds a customer cancellation or a no-show from the saved policy, then
 * lets the caller change the booking. A Stripe failure leaves the booking as it was.
 */
final class CancellationSettlement
{
    public function __construct(
        private readonly CancellationPolicies $policies,
        private readonly RefundService $refunds,
    ) {}

    /**
     * @return array{percent: int, refunded: bool, problem: ?string}
     */
    public function refundCustomerCancel(Booking $booking, CarbonImmutable $at): array
    {
        $minutes = CancellationRefund::minutesUntil(
            CarbonImmutable::instance($booking->starts_at)->utc(),
            $at->utc(),
        );
        $percent = CancellationRefund::percent($this->policies->customerTiersFor($booking->tenant_id), $minutes);

        return $this->settle($booking, $percent, false, null);
    }

    /**
     * A missing no-show row refunds nothing. An explicit 0 percent also refunds nothing.
     *
     * @return array{percent: int, refunded: bool, problem: ?string}
     */
    public function refundNoShow(Booking $booking, ?User $actor): array
    {
        $percent = $this->policies->noShowPercentFor($booking->tenant_id);

        if ($percent === null) {
            return ['percent' => 0, 'refunded' => false, 'problem' => null];
        }

        return $this->settle($booking, $percent, true, $actor);
    }

    /**
     * @return array{percent: int, refunded: bool, problem: ?string}
     */
    private function settle(Booking $booking, int $percent, bool $noShow, ?User $actor): array
    {
        if ($percent === 0 || ! $this->policies->hasCardPayment($booking)) {
            return ['percent' => $percent, 'refunded' => false, 'problem' => null];
        }

        $subtotal = 0;
        $tax = 0;

        if ($percent === 100) {
            $reason = $noShow ? RefundReason::NoShowFullRefund : RefundReason::CustomerCancelledFullRefund;
        } else {
            $reason = $noShow ? RefundReason::NoShowPartialRefund : RefundReason::CustomerCancelledPartialRefund;
            $subtotal = CancellationRefund::share($this->basisCents($booking), $percent);
            $tax = CancellationRefund::share($this->taxCents($booking), $percent);

            if ($subtotal < 1) {
                return ['percent' => $percent, 'refunded' => false, 'problem' => null];
            }
        }

        $key = ($noShow ? 'policy-no-show:' : 'policy-cancel:').$booking->id;

        try {
            $this->refunds->issue($booking, $reason, $subtotal, $tax, $key, $actor);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() === 'Nothing left to refund.') {
                return ['percent' => $percent, 'refunded' => false, 'problem' => null];
            }

            return ['percent' => $percent, 'refunded' => false, 'problem' => $exception->getMessage()];
        } catch (RefundException|StripeNotConfiguredException $exception) {
            return ['percent' => $percent, 'refunded' => false, 'problem' => $exception->getMessage()];
        }

        return ['percent' => $percent, 'refunded' => true, 'problem' => null];
    }

    private function basisCents(Booking $booking): int
    {
        $payload = $this->payload($booking);
        $charged = $payload['charged_basis_cents'] ?? $payload['basis_cents'] ?? 0;

        return is_numeric($charged) ? max(0, (int) $charged) : 0;
    }

    private function taxCents(Booking $booking): int
    {
        $tax = $this->payload($booking)['tax_cents'] ?? 0;

        return is_numeric($tax) ? max(0, (int) $tax) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Booking $booking): array
    {
        $snapshot = PricingSnapshot::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $booking->tenant_id)
            ->where('booking_id', $booking->id)
            ->first();

        if (! $snapshot instanceof PricingSnapshot) {
            return [];
        }

        return $snapshot->payload;
    }
}
