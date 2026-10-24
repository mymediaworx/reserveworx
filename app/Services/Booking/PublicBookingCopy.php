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
     * @return array{status: string, payment: string}
     */
    public function for(Booking $booking): array
    {
        return [
            'status' => $this->status($booking),
            'payment' => $this->payment($booking),
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

    private function payment(Booking $booking): string
    {
        if ($booking->status === BookingStatus::Confirmed && $this->owesAtPickup($booking)) {
            $due = $this->dueAtPickup($booking);

            if ($due !== null) {
                return 'Balance due at pickup: '.Money::format($due['cents'], $due['currency']);
            }
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
     * @return array{cents: int, currency: string}|null
     */
    private function dueAtPickup(Booking $booking): ?array
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot) {
            return null;
        }

        $currency = $snapshot->currency !== '' ? $snapshot->currency : 'USD';

        return [
            'cents' => $this->checkout->amountDueAtPickup($snapshot),
            'currency' => $currency,
        ];
    }
}
