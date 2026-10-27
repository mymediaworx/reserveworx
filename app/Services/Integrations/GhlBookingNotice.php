<?php

namespace App\Services\Integrations;

use App\Domain\Fees\Disclosures\Money;
use App\Enums\GhlMessageKind;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RentalCheckout;

/**
 * Plain-text booking messages for the GoHighLevel conversations API.
 * The text never includes the private integration token.
 */
final class GhlBookingNotice
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{channel: string, subject: string, text: string, email: string, phone: string}
     */
    public function compose(
        GhlMessageKind $kind,
        Booking $booking,
        Customer $customer,
        string $serviceName,
        string $timezone,
        ?Payment $payment,
        ?Refund $refund,
        ?RentalCheckout $checkout,
        ?int $offsetMinutes,
        array $snapshot,
    ): array {
        $email = is_string($customer->email) ? trim($customer->email) : '';
        $phone = is_string($customer->phone) ? trim($customer->phone) : '';
        $channel = $email !== '' ? 'email' : 'sms';
        $when = $booking->starts_at->copy()->timezone($timezone)->format('M j, Y g:i A T');
        $service = $serviceName !== '' ? $serviceName : 'your booking';
        $reference = 'Booking '.$booking->id;

        $text = match ($kind) {
            GhlMessageKind::Confirmed => $this->confirmed($channel, $service, $reference, $when, $booking->participants),
            GhlMessageKind::Receipt => $this->receipt($channel, $service, $reference, $payment, $snapshot),
            GhlMessageKind::Reminder => $this->reminder($channel, $service, $reference, $when, $offsetMinutes),
            GhlMessageKind::Cancelled, GhlMessageKind::GuestCancelled => $this->cancelled($channel, $service, $reference, $when),
            GhlMessageKind::Rescheduled => $this->rescheduled($channel, $service, $reference, $when),
            GhlMessageKind::PaymentFailed => $this->paymentFailed($channel, $service, $reference, $when),
            GhlMessageKind::RefundIssued => $this->refund($channel, $service, $reference, $refund),
            GhlMessageKind::Overdue => $this->overdue($channel, $service, $reference, $checkout, $timezone),
        };

        return [
            'channel' => $channel,
            'subject' => $kind->label().': '.$service,
            'text' => mb_substr($text, 0, 1500),
            'email' => $email,
            'phone' => $phone,
        ];
    }

    private function confirmed(string $channel, string $service, string $reference, string $when, int $party): string
    {
        if ($channel === 'sms') {
            return 'Confirmed: '.$service.' at '.$when.'. '.$reference.'.';
        }

        return implode("\n", [
            'Your '.$service.' booking is confirmed.',
            $reference,
            'Starts '.$when,
            'Party '.$party,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function receipt(string $channel, string $service, string $reference, ?Payment $payment, array $snapshot): string
    {
        $amount = $payment instanceof Payment ? Money::format($payment->amount_cents) : '';
        $method = $payment instanceof Payment ? (string) $payment->method : '';

        if ($channel === 'sms') {
            return 'Receipt '.$amount.' for '.$service.'. '.$reference.'.';
        }

        $lines = [
            'Receipt for '.$service,
            $reference,
            'Payment '.$amount.($method !== '' ? ' by '.$method : ''),
        ];
        $fee = $this->cents($snapshot, 'customer_fee_line_cents');

        if ($fee !== null) {
            $lines[] = 'Booking fee '.$fee;
        }

        $tax = $this->cents($snapshot, 'tax_cents');

        if ($tax !== null) {
            $lines[] = 'Tax '.$tax;
        }

        $total = $this->cents($snapshot, 'customer_total_cents');

        if ($total !== null) {
            $lines[] = 'Total '.$total;
        }

        return implode("\n", $lines);
    }

    private function reminder(string $channel, string $service, string $reference, string $when, ?int $offsetMinutes): string
    {
        $offset = $offsetMinutes ?? 0;

        if ($channel === 'sms') {
            return 'Reminder: '.$service.' starts '.$when.'. '.$reference.'.';
        }

        return implode("\n", [
            'Reminder: '.$service.' starts '.$when.'.',
            'This reminder is '.$offset.' minutes before the start.',
            $reference,
        ]);
    }

    private function cancelled(string $channel, string $service, string $reference, string $when): string
    {
        if ($channel === 'sms') {
            return 'Cancelled: '.$service.' at '.$when.'. '.$reference.'.';
        }

        return implode("\n", [
            'Your '.$service.' booking was cancelled.',
            $reference,
            'Starts '.$when,
        ]);
    }

    private function rescheduled(string $channel, string $service, string $reference, string $when): string
    {
        if ($channel === 'sms') {
            return 'New time: '.$service.' at '.$when.'. '.$reference.'.';
        }

        return implode("\n", [
            'Your '.$service.' booking is now on '.$when.'.',
            $reference,
            'Your check-in code has not changed.',
        ]);
    }

    private function paymentFailed(string $channel, string $service, string $reference, string $when): string
    {
        if ($channel === 'sms') {
            return 'Payment failed for '.$service.' at '.$when.'. '.$reference.'.';
        }

        return implode("\n", [
            'Your payment for '.$service.' on '.$when.' did not go through, so the booking is not confirmed yet.',
            'Please try again from the booking page.',
            $reference,
        ]);
    }

    private function refund(string $channel, string $service, string $reference, ?Refund $refund): string
    {
        $toCustomer = $refund instanceof Refund ? $refund->customer_refund_cents : 0;
        $fee = $refund instanceof Refund ? $refund->customer_fee_refund_cents : 0;
        $subtotal = $refund instanceof Refund ? $refund->refund_subtotal_cents : 0;
        $tax = $refund instanceof Refund ? $refund->refund_tax_cents : 0;
        $reason = $refund instanceof Refund ? str_replace('_', ' ', $refund->reason) : '';

        if ($channel === 'sms') {
            $text = 'Refund '.Money::format($toCustomer).' for '.$service.'. '.$reference.'.';

            if ($fee > 0) {
                $text .= ' Booking fee refunded '.Money::format($fee).'.';
            }

            return $text;
        }

        return implode("\n", [
            'Refund for '.$service,
            $reference,
            'Refunded to you: '.Money::format($toCustomer),
            'Booking fee refunded: '.Money::format($fee),
            'Service amount refunded: '.Money::format($subtotal),
            'Tax refunded: '.Money::format($tax),
            'Reason: '.$reason,
        ]);
    }

    private function overdue(string $channel, string $service, string $reference, ?RentalCheckout $checkout, string $timezone): string
    {
        $due = $checkout instanceof RentalCheckout
            ? $checkout->due_at->copy()->timezone($timezone)->format('M j, Y g:i A T')
            : '';

        if ($channel === 'sms') {
            return 'Overdue: '.$service.' was due '.$due.'. '.$reference.'.';
        }

        return implode("\n", [
            'Your '.$service.' rental is overdue.',
            $reference,
            'Due '.$due,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function cents(array $snapshot, string $key): ?string
    {
        if (! array_key_exists($key, $snapshot) || ! is_numeric($snapshot[$key])) {
            return null;
        }

        return Money::format((int) $snapshot[$key]);
    }
}
