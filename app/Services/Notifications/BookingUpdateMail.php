<?php

namespace App\Services\Notifications;

use App\Enums\TransactionalEmail;
use App\Models\Booking;
use App\Models\NotificationHook;
use App\Models\PricingSnapshot;
use App\Support\Display;
use DateTimeImmutable;
use Throwable;

/**
 * Words for the customer emails that notification hooks used to skip (#136).
 * Confirmation and reminder emails have their own senders and are not here.
 */
final class BookingUpdateMail
{
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
        'payment.failed' => TransactionalEmail::PaymentFailed,
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
        $zone = $booking->location?->timezone ?: 'UTC';
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

        return [
            'subject' => $businessName.' refunded '.Display::money($cents, $currency),
            'lines' => [
                $businessName.' refunded '.Display::money($cents, $currency).' for '.$service.' on '.$when.'.',
                'Card refunds usually reach your account in 5 to 10 business days.',
                $reference,
            ],
        ];
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
