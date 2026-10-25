<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\PricingSnapshot;
use App\Models\RentalCheckout;

/**
 * Adds an applied late fee to the amount the customer owes.
 *
 * The amount is stored on the checkout and added to the snapshot customer
 * total. It is not part of the fee basis. It does not write a platform fee,
 * a payment, or a refund. A later Stripe price increase still uses the
 * percentage on that increase, and a refund still uses the basis already charged.
 */
final class LateFeeCharge
{
    public function apply(Booking $booking, RentalCheckout $checkout, int $amountCents, int $minutesLate): void
    {
        $checkout->late_fee_cents = $amountCents;
        $checkout->save();

        if ($amountCents < 1) {
            return;
        }

        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->lockForUpdate()->first();

        if (! $snapshot instanceof PricingSnapshot) {
            return;
        }

        $payload = $snapshot->payload;
        $lines = $payload['late_fee_lines'] ?? [];

        if (! is_array($lines)) {
            $lines = [];
        }

        $lines[] = [
            'checkout_id' => $checkout->id,
            'minutes_late' => $minutesLate,
            'amount_cents' => $amountCents,
        ];
        $payload['late_fee_lines'] = $lines;
        $payload['late_fees_cents'] = $this->cents($payload, 'late_fees_cents') + $amountCents;
        $payload['customer_total_cents'] = $this->cents($payload, 'customer_total_cents') + $amountCents;
        $snapshot->payload = $payload;
        $snapshot->save();
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
