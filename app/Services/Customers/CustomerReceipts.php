<?php

namespace App\Services\Customers;

use App\Models\Booking;
use App\Models\DisclosureVersion;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use Illuminate\Support\Carbon;

final class CustomerReceipts
{
    /**
     * @return array{
     *     booking_id: string,
     *     reference: string,
     *     service_name: string|null,
     *     starts_at: string|null,
     *     ends_at: string|null,
     *     when: string,
     *     currency: string,
     *     subtotal_cents: int,
     *     operator_fees: list<array{name: string, total_cents: int}>,
     *     booking_fee_cents: int|null,
     *     tax_cents: int,
     *     tax_lines: list<array{name: string, cents: int}>,
     *     late_fee_cents: int,
     *     damage_cents: int,
     *     total_cents: int,
     *     paid_cents: int,
     *     refunded_cents: int|null,
     *     refunded_booking_fee_cents: int|null,
     *     payments: list<array{id: string, method: string, kind: string, status: string, amount_cents: int}>,
     *     html: string|null,
     *     text: string|null
     * }
     */
    public function forBooking(Booking $booking): array
    {
        $booking->loadMissing(['service', 'location']);
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();
        $payload = $snapshot instanceof PricingSnapshot ? $snapshot->payload : [];
        $operatorFees = $this->operatorFees($payload);
        $operatorCents = 0;

        foreach ($operatorFees as $fee) {
            $operatorCents += $fee['total_cents'];
        }

        $subtotal = $this->subtotal($payload, $operatorCents);
        $bookingFee = $this->nullableCents($payload, 'customer_fee_line_cents');
        $tax = $this->cents($payload, 'tax_cents');
        $taxLines = $this->taxLines($payload);
        $lateFee = $this->cents($payload, 'late_fees_cents');
        $damage = $this->cents($payload, 'damage_cents');
        $total = $this->cents($payload, 'customer_total_cents');
        $currency = $snapshot instanceof PricingSnapshot && $snapshot->currency !== ''
            ? $snapshot->currency
            : 'USD';
        $payments = $this->payments($booking);
        $paid = 0;

        foreach ($payments as $payment) {
            if ($payment['status'] === 'paid') {
                $paid += $payment['amount_cents'];
            }
        }

        $refunded = (int) Refund::query()->where('booking_id', $booking->id)->sum('customer_refund_cents');
        $refundedFee = (int) Refund::query()->where('booking_id', $booking->id)->sum('customer_fee_refund_cents');
        $terms = $this->terms($snapshot);
        $rendered = $this->rendered($subtotal, $operatorFees, $bookingFee, $tax, $taxLines, $lateFee, $damage, $total, $paid, $refunded, $refundedFee, $currency, $terms);
        $ends = $booking->ends_at;

        return [
            'booking_id' => $booking->id,
            'reference' => $booking->id,
            'service_name' => $booking->service?->name,
            'starts_at' => $this->iso($booking->starts_at),
            'ends_at' => $this->iso($booking->ends_at),
            'when' => $ends instanceof Carbon && $ends->greaterThanOrEqualTo(now()) ? 'upcoming' : 'past',
            'currency' => $currency,
            'subtotal_cents' => $subtotal,
            'operator_fees' => $operatorFees,
            'booking_fee_cents' => $bookingFee,
            'tax_cents' => $tax,
            'tax_lines' => $taxLines,
            'late_fee_cents' => $lateFee,
            'damage_cents' => $damage,
            'total_cents' => $total,
            'paid_cents' => $paid,
            'refunded_cents' => $refunded > 0 ? $refunded : null,
            'refunded_booking_fee_cents' => $refunded > 0 ? $refundedFee : null,
            'payments' => $payments,
            'html' => $rendered['html'],
            'text' => $rendered['text'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{name: string, total_cents: int}>
     */
    private function operatorFees(array $payload): array
    {
        $lines = $payload['operator_fees'] ?? null;

        if (! is_array($lines)) {
            return [];
        }

        $fees = [];

        foreach ($lines as $line) {
            if (! is_array($line) || ! is_string($line['name'] ?? null) || ! is_numeric($line['total_cents'] ?? null)) {
                continue;
            }

            $fees[] = [
                'name' => $line['name'],
                'total_cents' => (int) $line['total_cents'],
            ];
        }

        return $fees;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{name: string, cents: int}>
     */
    private function taxLines(array $payload): array
    {
        $lines = $payload['tax_lines'] ?? null;

        if (! is_array($lines)) {
            return [];
        }

        $taxes = [];

        foreach ($lines as $line) {
            if (! is_array($line) || ! is_string($line['name'] ?? null) || ! is_numeric($line['cents'] ?? null)) {
                continue;
            }

            $taxes[] = [
                'name' => $line['name'],
                'cents' => (int) $line['cents'],
            ];
        }

        return $taxes;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function subtotal(array $payload, int $operatorCents): int
    {
        if (! array_key_exists('services_cents', $payload)) {
            return max(0, $this->cents($payload, 'basis_cents') - $operatorCents);
        }

        return max(0, $this->cents($payload, 'services_cents') - $this->cents($payload, 'discount_cents') + $this->cents($payload, 'add_ons_cents'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cents(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullableCents(array $payload, string $key): ?int
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        return is_numeric($payload[$key]) ? (int) $payload[$key] : null;
    }

    /**
     * @return list<array{id: string, method: string, kind: string, status: string, amount_cents: int}>
     */
    private function payments(Booking $booking): array
    {
        $rows = [];

        foreach (Payment::query()->where('booking_id', $booking->id)->orderBy('id')->get() as $payment) {
            $rows[] = [
                'id' => $payment->id,
                'method' => (string) $payment->method,
                'kind' => (string) $payment->kind,
                'status' => (string) $payment->status,
                'amount_cents' => (int) $payment->amount_cents,
            ];
        }

        return $rows;
    }

    private function terms(?PricingSnapshot $snapshot): ?DisclosureVersion
    {
        if (! $snapshot instanceof PricingSnapshot || $snapshot->disclosure_version_id === null) {
            return null;
        }

        $terms = DisclosureVersion::query()->find($snapshot->disclosure_version_id);

        return $terms instanceof DisclosureVersion ? $terms : null;
    }

    /**
     * @param  list<array{name: string, total_cents: int}>  $operatorFees
     * @param  list<array{name: string, cents: int}>  $taxLines
     * @return array{html: string|null, text: string|null}
     */
    private function rendered(
        int $subtotal,
        array $operatorFees,
        ?int $bookingFee,
        int $tax,
        array $taxLines,
        int $lateFee,
        int $damage,
        int $total,
        int $paid,
        int $refunded,
        int $refundedFee,
        string $currency,
        ?DisclosureVersion $terms,
    ): array {
        if ($bookingFee !== null && ! $terms instanceof DisclosureVersion) {
            return ['html' => null, 'text' => null];
        }

        $data = [
            'subtotalCents' => $subtotal,
            'operatorFees' => $operatorFees,
            'bookingFeeCents' => $bookingFee,
            'taxCents' => $tax,
            'taxLines' => $taxLines,
            'lateFeeCents' => $lateFee,
            'damageCents' => $damage,
            'totalCents' => $total,
            'paidCents' => $paid,
            'refundedCents' => $refunded > 0 ? $refunded : null,
            'refundedBookingFeeCents' => $refunded > 0 ? $refundedFee : null,
            'currency' => $currency,
            'refundTerms' => $terms,
        ];
        $text = trim(view('disclosures.receipt-text', $data)->render());

        return [
            'html' => view('disclosures.receipt', $data)->render(),
            'text' => $text !== '' ? $text : null,
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        return null;
    }
}
