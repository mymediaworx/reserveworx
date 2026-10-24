<?php

namespace App\Services\CheckIn;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Models\Refund;

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
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            return ['cents' => null, 'currency' => 'USD'];
        }

        $payload = $snapshot->payload;
        $total = $this->cents($payload, 'customer_total_cents');

        if ($booking->payment_status === PaymentStatus::OfflinePaid) {
            $total = max(0, $total - $this->cents($payload, 'customer_fee_line_cents'));
        }

        $paid = (int) Payment::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'paid')
            ->sum('amount_cents');
        $refunded = (int) Refund::query()
            ->where('booking_id', $booking->id)
            ->sum('customer_refund_cents');
        $currency = $snapshot->currency !== '' ? $snapshot->currency : 'USD';

        return [
            'cents' => max(0, $total - $paid + $refunded),
            'currency' => $currency,
        ];
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
