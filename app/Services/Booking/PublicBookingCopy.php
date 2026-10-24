<?php

namespace App\Services\Booking;

use App\Domain\Fees\Disclosures\Money;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Services\Payments\CheckoutService;

/**
 * Customer-facing booking words. Public pages do not print stored enum values.
 */
final class PublicBookingCopy
{
    public function __construct(private readonly CheckoutService $checkout) {}

    /**
     * @return array{status: string, payment: string, pickupFeeNote: string}
     */
    public function for(Booking $booking): array
    {
        $due = $booking->status === BookingStatus::Confirmed && $this->owesAtPickup($booking)
            ? $this->dueAtPickup($booking)
            : null;

        return [
            'status' => $this->status($booking),
            'payment' => $this->payment($booking, $due),
            'pickupFeeNote' => $this->pickupFeeNote($due),
        ];
    }

    private function status(Booking $booking): string
    {
        return match ($booking->status) {
            BookingStatus::PendingPayment => 'Waiting for payment',
            BookingStatus::Held => 'On hold',
            BookingStatus::Confirmed => 'Confirmed',
            BookingStatus::Cancelled => 'Cancelled',
            BookingStatus::NoShow => 'No show',
            BookingStatus::PaymentException => 'Payment needs attention',
        };
    }

    /**
     * @param  array{cents: int, currency: string, feeLineCents: int}|null  $due
     */
    private function payment(Booking $booking, ?array $due): string
    {
        if ($due !== null) {
            return 'Balance due at pickup: '.Money::format($due['cents'], $due['currency']);
        }

        return match ($booking->payment_status) {
            PaymentStatus::Unpaid => 'Not paid yet',
            PaymentStatus::DepositPaid => 'Deposit paid',
            PaymentStatus::Paid, PaymentStatus::OfflinePaid => 'Paid',
            PaymentStatus::PartiallyRefunded => 'Partially refunded',
            PaymentStatus::Refunded => 'Refunded',
            PaymentStatus::Disputed => 'Disputed',
            PaymentStatus::DueAtPickup => 'Balance due at pickup',
        };
    }

    private function owesAtPickup(Booking $booking): bool
    {
        if ($booking->payment_status === PaymentStatus::DueAtPickup) {
            return true;
        }

        if ($booking->payment_status !== PaymentStatus::OfflinePaid) {
            return false;
        }

        $paid = Payment::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'paid')
            ->get();

        if ($paid->isEmpty()) {
            return true;
        }

        return $paid->every(function (Payment $payment): bool {
            $key = $payment->idempotency_key;

            return is_string($key) && str_starts_with($key, 'widget-pickup-');
        });
    }

    /**
     * Offline payment carries no booking fee. The amount due is the customer total
     * minus that line, which is what pay at pickup already records.
     *
     * @param  array{cents: int, currency: string, feeLineCents: int}|null  $due
     */
    private function pickupFeeNote(?array $due): string
    {
        if ($due === null || $due['feeLineCents'] <= 0) {
            return '';
        }

        return 'Pay at pickup does not include the online booking fee.';
    }

    /**
     * @return array{cents: int, currency: string, feeLineCents: int}|null
     */
    private function dueAtPickup(Booking $booking): ?array
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            return null;
        }

        $currency = $snapshot->currency !== '' ? $snapshot->currency : 'USD';
        $line = $snapshot->payload['customer_fee_line_cents'] ?? 0;

        return [
            'cents' => $this->checkout->amountDueAtPickup($snapshot),
            'currency' => $currency,
            'feeLineCents' => is_numeric($line) ? (int) $line : 0,
        ];
    }
}
