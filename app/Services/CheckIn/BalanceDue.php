<?php

namespace App\Services\CheckIn;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use Illuminate\Support\Collection;

/**
 * What the customer still owes. Offline payment never included the booking-fee line,
 * so an offline-paid booking does not treat that line as due.
 */
final class BalanceDue
{
    /**
     * @return array{cents: int|null, currency: string}
     */
    public function forBooking(Booking $booking): array
    {
        return $this->forBookings([$booking])[$booking->id];
    }

    /**
     * @param  iterable<mixed>  $bookings
     * @return array<string, array{cents: int|null, currency: string}>
     */
    public function forBookings(iterable $bookings): array
    {
        /** @var array<string, Booking> $rows */
        $rows = [];

        foreach ($bookings as $booking) {
            if ($booking instanceof Booking) {
                $rows[$booking->id] = $booking;
            }
        }

        if ($rows === []) {
            return [];
        }

        $ids = array_keys($rows);
        $snapshots = PricingSnapshot::query()->whereIn('booking_id', $ids)->get()->keyBy('booking_id');
        $paid = Payment::query()
            ->whereIn('booking_id', $ids)
            ->where('status', 'paid')
            ->get()
            ->groupBy('booking_id');
        $refunds = Refund::query()
            ->whereIn('booking_id', $ids)
            ->get()
            ->groupBy('booking_id');
        $result = [];

        foreach ($rows as $id => $booking) {
            $snapshot = $snapshots->get($id);

            if (! $snapshot instanceof PricingSnapshot) {
                $result[$id] = ['cents' => null, 'currency' => 'USD'];

                continue;
            }

            $payload = $snapshot->payload ?? [];
            $total = $this->cents($payload, 'customer_total_cents');

            if ($booking->payment_status === PaymentStatus::OfflinePaid) {
                $total = max(0, $total - $this->cents($payload, 'customer_fee_line_cents'));
            }

            $paidCents = $this->sumColumn($paid->get($id), 'amount_cents');
            $refunded = $this->sumColumn($refunds->get($id), 'customer_refund_cents');
            $currency = $snapshot->currency !== '' ? $snapshot->currency : 'USD';
            $result[$id] = [
                'cents' => max(0, $total - $paidCents + $refunded),
                'currency' => $currency,
            ];
        }

        return $result;
    }

    private function sumColumn(mixed $rows, string $column): int
    {
        if (! $rows instanceof Collection) {
            return 0;
        }

        return (int) $rows->sum($column);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cents(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
