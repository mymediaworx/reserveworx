<?php

namespace App\Services\Notifications;

use App\Domain\Fees\Disclosures\BookingFeeRefundTerms;
use App\Enums\TransactionalEmail;
use App\Models\Booking;
use App\Models\DisclosureVersion;
use App\Models\NotificationHook;
use App\Models\PricingSnapshot;
use App\Services\Customers\CustomerReceipts;
use App\Support\Display;
use App\Support\DisplayZone;
use DateTimeImmutable;
use Throwable;

/**
 * Words for the customer emails that notification hooks used to skip (#136).
 * Confirmation and reminder emails have their own senders and are not here.
 * A payment receipt uses the amounts already stored on the booking. It does
 * not recalculate a price, a tax, a booking fee, or a refund. A refund notice
 * lists the booking-fee amount already stored on that hook and does not
 * calculate a new fee. A waitlist opening names the visit and does not read
 * those amounts.
 */
final class BookingUpdateMail
{
    public function __construct(private readonly CustomerReceipts $receipts) {}

    /**
     * Hook kind => the workspace email switch that controls it.
     *
     * @var array<string, TransactionalEmail>
     */
    public const KINDS = [
        'booking.cancelled' => TransactionalEmail::BookingCancelled,
        'booking.guest_cancelled' => TransactionalEmail::BookingGuestCancelled,
        'booking.guest_rescheduled' => TransactionalEmail::BookingGuestRescheduled,
        'booking.refunded' => TransactionalEmail::RefundIssued,
        'rental.overdue' => TransactionalEmail::RentalOverdue,
        'payment.completed' => TransactionalEmail::PaymentCompleted,
        'payment.failed' => TransactionalEmail::PaymentFailed,
        'waitlist.promoted' => TransactionalEmail::WaitlistPromoted,
    ];

    public static function handles(string $kind): bool
    {
        return array_key_exists($kind, self::KINDS);
    }

    /**
     * @return array{subject: string, lines: list<string>}|null
     */
    public function compose(NotificationHook $hook, Booking $booking, string $businessName): ?array
    {
        $service = trim((string) $booking->service?->name) ?: 'your booking';
        $zone = DisplayZone::forLocation($booking->location);
        $when = Display::dateTime($booking->starts_at, $zone);
        $reference = 'Reference: '.strtoupper(substr($booking->id, -8)).'.';
        $payload = $hook->payload;

        return match ($hook->kind) {
            'booking.cancelled' => [
                'subject' => 'Your booking with '.$businessName.' is cancelled',
                'lines' => [
                    $businessName.' cancelled your booking for '.$service.' on '.$when.'.',
                    'If you paid online, any refund you are owed is sent to the card you used, and you get a separate email when it is issued.',
                    $reference,
                ],
            ],
            'booking.guest_cancelled' => [
                'subject' => 'You cancelled your booking with '.$businessName,
                'lines' => [
                    'Your booking for '.$service.' on '.$when.' is cancelled, as you asked.',
                    $reference,
                ],
            ],
            'booking.guest_rescheduled' => [
                'subject' => 'Your booking with '.$businessName.' has a new time',
                'lines' => [
                    'Your booking for '.$service.' is now on '.$when.'.',
                    'Your check-in code has not changed.',
                    $reference,
                ],
            ],
            'booking.refunded' => $this->refund($booking, $payload, $service, $when, $businessName, $reference),
            'payment.completed' => $this->receipt($booking, $payload, $service, $when, $businessName, $reference),
            'rental.overdue' => [
                'subject' => 'Your rental from '.$businessName.' is past its return time',
                'lines' => [
                    'Your rental of '.$service.' was due back '.$this->dueAt($payload, $zone).'.',
                    'Please return it as soon as you can, or contact '.$businessName.' if you need more time.',
                    $reference,
                ],
            ],
            'payment.failed' => [
                'subject' => 'Your payment to '.$businessName.' did not go through',
                'lines' => [
                    'Your payment for '.$service.' on '.$when.' did not go through, so the booking is not confirmed yet.',
                    'Please try again from the booking page, or contact '.$businessName.'.',
                    $reference,
                ],
            ],
            'waitlist.promoted' => [
                'subject' => 'A spot opened with '.$businessName,
                'lines' => [
                    'A spot opened for '.$service.' on '.$when.'.',
                    'The booking is held for you. Finish it from the booking page, or contact '.$businessName.'.',
                    $reference,
                ],
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{subject: string, lines: list<string>}|null
     */
    private function refund(Booking $booking, array $payload, string $service, string $when, string $businessName, string $reference): ?array
    {
        $cents = is_numeric($payload['customer_refund_cents'] ?? null) ? (int) $payload['customer_refund_cents'] : 0;

        if ($cents <= 0) {
            return null;
        }

        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();
        $currency = $snapshot instanceof PricingSnapshot && $snapshot->currency !== '' ? $snapshot->currency : 'USD';
        $lines = [
            $businessName.' refunded '.Display::money($cents, $currency).' for '.$service.' on '.$when.'.',
        ];
        $feeLine = $this->storedFeeRefundLine($payload, $currency);

        if ($feeLine !== null) {
            $lines[] = $feeLine;
        }

        $lines[] = 'Card refunds usually reach your account in 5 to 10 business days.';
        $lines[] = $reference;

        return [
            'subject' => $businessName.' refunded '.Display::money($cents, $currency),
            'lines' => $lines,
        ];
    }

    /**
     * The booking-fee portion already stored on this hook. A missing, negative,
     * or non-numeric value is left out. The charged fee on the snapshot is not read.
     *
     * @param  array<string, mixed>  $payload
     */
    private function storedFeeRefundLine(array $payload, string $currency): ?string
    {
        if (! array_key_exists('customer_fee_refund_cents', $payload) || ! is_numeric($payload['customer_fee_refund_cents'])) {
            return null;
        }

        $fee = (int) $payload['customer_fee_refund_cents'];

        if ($fee < 0) {
            return null;
        }

        return BookingFeeRefundTerms::LABEL.' refunded: '.Display::money($fee, $currency).'.';
    }

    /**
     * The stored receipt, in the same order as the receipt the customer can open.
     * Amounts come from that receipt. This method does not price the booking again.
     *
     * @param  array<string, mixed>  $payload
     * @return array{subject: string, lines: list<string>}
     */
    private function receipt(Booking $booking, array $payload, string $service, string $when, string $businessName, string $reference): array
    {
        $stored = $this->receipts->forBooking($booking);
        $currency = $stored['currency'];
        $method = ($payload['method'] ?? null) === 'offline' ? 'Paid offline' : 'Card';
        $thisPayment = is_numeric($payload['amount_cents'] ?? null) ? (int) $payload['amount_cents'] : $stored['paid_cents'];
        $lines = [
            'Receipt from '.$businessName.' for '.$service.' on '.$when.'.',
            $method.': '.Display::money($thisPayment, $currency).'.',
            'Subtotal: '.Display::money($stored['subtotal_cents'], $currency).'.',
        ];

        foreach ($stored['operator_fees'] as $fee) {
            $lines[] = $fee['name'].': '.Display::money($fee['total_cents'], $currency).'.';
        }

        if ($stored['late_fee_cents'] > 0) {
            $lines[] = 'Late fee: '.Display::money($stored['late_fee_cents'], $currency).'.';
        }

        if ($stored['damage_cents'] > 0) {
            $lines[] = 'Damage: '.Display::money($stored['damage_cents'], $currency).'.';
        }

        if ($stored['booking_fee_cents'] !== null) {
            $lines[] = BookingFeeRefundTerms::LABEL.': '.Display::money($stored['booking_fee_cents'], $currency).'.';
        }

        foreach ($stored['tax_lines'] as $tax) {
            $lines[] = $tax['name'].': '.Display::money($tax['cents'], $currency).'.';
        }

        if ($stored['tax_lines'] === [] && $stored['tax_cents'] > 0) {
            $lines[] = 'Tax: '.Display::money($stored['tax_cents'], $currency).'.';
        }

        $lines[] = 'Total: '.Display::money($stored['total_cents'], $currency).'.';
        $lines[] = 'Paid: '.Display::money($stored['paid_cents'], $currency).'.';

        if ($stored['refunded_cents'] !== null) {
            $lines[] = 'Refunded: '.Display::money($stored['refunded_cents'], $currency).'.';

            if ($stored['booking_fee_cents'] !== null) {
                $lines[] = BookingFeeRefundTerms::LABEL.' refunded: '.Display::money($stored['refunded_booking_fee_cents'] ?? 0, $currency).'.';
            }
        }

        $lines = array_merge($lines, $this->storedTerms($booking));
        $lines[] = $reference;

        return [
            'subject' => 'Your receipt from '.$businessName,
            'lines' => $lines,
        ];
    }

    /**
     * The disclosure version already stored on the booking, not a new set of rules.
     *
     * @return list<string>
     */
    private function storedTerms(Booking $booking): array
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();

        if (! $snapshot instanceof PricingSnapshot || $snapshot->disclosure_version_id === null) {
            return [];
        }

        $terms = DisclosureVersion::query()->find($snapshot->disclosure_version_id);

        if (! $terms instanceof DisclosureVersion) {
            return [];
        }

        $lines = $terms->lines();
        $lines[] = 'Terms version '.$terms->version.'.';

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dueAt(array $payload, string $zone): string
    {
        $due = $payload['due_at'] ?? null;

        if (! is_string($due) || $due === '') {
            return 'earlier';
        }

        try {
            return 'at '.Display::dateTime(new DateTimeImmutable($due), $zone);
        } catch (Throwable) {
            return 'earlier';
        }
    }
}
