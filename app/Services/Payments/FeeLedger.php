<?php

namespace App\Services\Payments;

use App\Domain\Fees\Refunds\RefundReason;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tenant fee ledger, one booking at a time (spec Section 7.7).
 * Totals come from stored platform-fee rows. It does not call Stripe.
 */
final class FeeLedger
{
    /**
     * @return array{
     *     charged_cents: int,
     *     refunded_cents: int,
     *     net_cents: int,
     *     count: int,
     *     bookings: list<array{
     *         booking_id: string,
     *         charged_cents: int,
     *         refunded_cents: int,
     *         fees: list<array{
     *             id: string,
     *             payment_id: string|null,
     *             channel: string,
     *             charged_cents: int,
     *             refunded_cents: int,
     *             refunds: list<array{
     *                 id: string,
     *                 amount_cents: int,
     *                 reason: string,
     *                 rule: string,
     *                 actor_user_id: string|null
     *             }>
     *         }>
     *     }>
     * }
     */
    public function build(?string $bookingId = null): array
    {
        $query = PlatformFee::query()->orderBy('created_at')->orderBy('id');

        if ($bookingId !== null) {
            $query->where('booking_id', $bookingId);
        }

        /** @var Collection<int, PlatformFee> $fees */
        $fees = $query->get();
        $bookingIds = $bookingId !== null
            ? [$bookingId]
            : $fees->pluck('booking_id')->unique()->values()->all();

        /** @var Collection<int, Refund> $sectionRefunds */
        $sectionRefunds = Refund::query()
            ->whereIn('booking_id', $bookingIds)
            ->where('application_fee_refund_cents', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        /** @var Collection<int, PlatformFeeRefund> $manualRefunds */
        $manualRefunds = PlatformFeeRefund::query()
            ->whereIn('platform_fee_id', $fees->modelKeys())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        /** @var array<string, list<PlatformFeeRefund>> $manualByFee */
        $manualByFee = [];

        foreach ($manualRefunds as $manual) {
            $manualByFee[$manual->platform_fee_id][] = $manual;
        }

        /** @var array<string, list<Refund>> $sectionByBooking */
        $sectionByBooking = [];

        foreach ($sectionRefunds as $refund) {
            $sectionByBooking[$refund->booking_id][] = $refund;
        }

        /** @var array<string, array{booking_id: string, charged_cents: int, refunded_cents: int, fees: list<array{id: string, payment_id: string|null, channel: string, charged_cents: int, refunded_cents: int, refunds: list<array{id: string, amount_cents: int, reason: string, rule: string, actor_user_id: string|null}>}>}> $bookings */
        $bookings = [];

        foreach ($fees as $fee) {
            $id = $fee->booking_id;

            if (! isset($bookings[$id])) {
                $bookings[$id] = [
                    'booking_id' => $id,
                    'charged_cents' => 0,
                    'refunded_cents' => 0,
                    'fees' => [],
                ];
            }

            $bookings[$id]['charged_cents'] += $fee->amount_cents;
            $bookings[$id]['refunded_cents'] += $fee->refunded_cents;
            $isFirst = $bookings[$id]['fees'] === [];
            $lines = $isFirst ? $this->sectionLines($sectionByBooking[$id] ?? []) : [];

            foreach ($manualByFee[$fee->id] ?? [] as $manual) {
                $lines[] = $this->manualLine($manual);
            }

            $bookings[$id]['fees'][] = [
                'id' => $fee->id,
                'payment_id' => is_string($fee->payment_id) ? $fee->payment_id : null,
                'channel' => $fee->channel,
                'charged_cents' => $fee->amount_cents,
                'refunded_cents' => $fee->refunded_cents,
                'refunds' => $this->ordered($lines),
            ];
        }

        if ($bookingId !== null && $bookings === []) {
            $bookings[$bookingId] = [
                'booking_id' => $bookingId,
                'charged_cents' => 0,
                'refunded_cents' => 0,
                'fees' => [],
            ];
        }

        $charged = (int) $fees->sum('amount_cents');
        $refunded = (int) $fees->sum('refunded_cents');

        return [
            'charged_cents' => $charged,
            'refunded_cents' => $refunded,
            'net_cents' => $charged - $refunded,
            'count' => $fees->count(),
            'bookings' => array_values($bookings),
        ];
    }

    /**
     * @param  list<Refund>  $refunds
     * @return list<array{id: string, amount_cents: int, reason: string, rule: string, actor_user_id: string|null, sort_at: string, sort_id: string}>
     */
    private function sectionLines(array $refunds): array
    {
        $lines = [];

        foreach ($refunds as $refund) {
            $reason = RefundReason::tryFrom($refund->reason);
            $lines[] = [
                'id' => $refund->id,
                'amount_cents' => $refund->application_fee_refund_cents,
                'reason' => $refund->reason,
                'rule' => $reason instanceof RefundReason ? $reason->rule()->value : $refund->rules_version,
                'actor_user_id' => is_string($refund->actor_user_id) ? $refund->actor_user_id : null,
                'sort_at' => $this->sortAt($refund),
                'sort_id' => $refund->id,
            ];
        }

        return $lines;
    }

    /**
     * @return array{id: string, amount_cents: int, reason: string, rule: string, actor_user_id: string|null, sort_at: string, sort_id: string}
     */
    private function manualLine(PlatformFeeRefund $refund): array
    {
        return [
            'id' => $refund->id,
            'amount_cents' => $refund->amount_cents,
            'reason' => $refund->reason,
            'rule' => $refund->rule,
            'actor_user_id' => is_string($refund->actor_user_id) ? $refund->actor_user_id : null,
            'sort_at' => $this->sortAt($refund),
            'sort_id' => $refund->id,
        ];
    }

    /**
     * @param  list<array{id: string, amount_cents: int, reason: string, rule: string, actor_user_id: string|null, sort_at: string, sort_id: string}>  $lines
     * @return list<array{id: string, amount_cents: int, reason: string, rule: string, actor_user_id: string|null}>
     */
    private function ordered(array $lines): array
    {
        usort($lines, function (array $left, array $right): int {
            return [$left['sort_at'], $left['sort_id']] <=> [$right['sort_at'], $right['sort_id']];
        });

        return array_map(function (array $line): array {
            unset($line['sort_at'], $line['sort_id']);

            return $line;
        }, $lines);
    }

    private function sortAt(Refund|PlatformFeeRefund $refund): string
    {
        $at = $refund->created_at;

        return $at === null ? '' : $at->format('Y-m-d H:i:s.u');
    }
}
