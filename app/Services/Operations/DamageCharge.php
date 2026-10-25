<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\PricingSnapshot;
use Illuminate\Validation\ValidationException;

/**
 * Adds a damage amount to the amount the customer owes.
 *
 * The amount is stored on the incident and added to the snapshot customer
 * total. It is not part of the fee basis. It does not write a platform fee,
 * a payment, or a refund. A later Stripe price increase still uses the
 * percentage on that increase, and a refund still uses the basis already charged.
 */
final class DamageCharge
{
    public function apply(Booking $booking, string $incidentId, int $amountCents): void
    {
        if ($amountCents < 1) {
            return;
        }

        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->lockForUpdate()->first();

        if (! $snapshot instanceof PricingSnapshot) {
            throw ValidationException::withMessages([
                'damageAmount' => 'Price this booking before adding a damage amount.',
            ]);
        }

        $payload = $snapshot->payload;
        $lines = $payload['damage_lines'] ?? [];

        if (! is_array($lines)) {
            $lines = [];
        }

        $lines[] = [
            'incident_id' => $incidentId,
            'amount_cents' => $amountCents,
        ];
        $payload['damage_lines'] = $lines;
        $payload['damage_cents'] = $this->cents($payload, 'damage_cents') + $amountCents;
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
