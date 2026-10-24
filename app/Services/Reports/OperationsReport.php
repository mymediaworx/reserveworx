<?php

namespace App\Services\Reports;

use App\Domain\Fees\Rounding;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Cancellation rate, no-show rate, captured card payments, offline payments,
 * customer refunds, taxes, and booking fees for a location-local date window.
 * The window is at most 366 days. Confirmed, cancelled, and no-show bookings
 * count on the location-local start date. Held bookings and every other status
 * stay out of the rates. Rates are basis points, rounded half-up, and stay
 * empty when that day has none of those three statuses.
 *
 * Tax is the pricing snapshot `tax_cents` of confirmed and no-show bookings
 * that start that day. Card payments, offline payments, customer refunds,
 * charged booking fees, and booking-fee refunds follow the day the row was
 * recorded in the booking location's timezone. A quoted snapshot fee is not
 * added. This read does not write a summary row.
 */
final class OperationsReport
{
    /**
     * @var array<string, array{
     *     location_id: string,
     *     date: string,
     *     confirmed: int,
     *     cancelled: int,
     *     no_show: int,
     *     card_cents: int,
     *     offline_cents: int,
     *     refunds_cents: int,
     *     tax_cents: int,
     *     fee_cents: int,
     *     fee_refunded_cents: int
     * }>
     */
    private array $groups = [];

    public function __construct(private readonly CsvTable $csv) {}

    /**
     * @return list<array{
     *     location_id: string,
     *     date: string,
     *     confirmed: int,
     *     cancelled: int,
     *     no_show: int,
     *     cancellation_bps: int|null,
     *     no_show_bps: int|null,
     *     card_cents: int,
     *     offline_cents: int,
     *     refunds_cents: int,
     *     tax_cents: int,
     *     fee_cents: int,
     *     fee_refunded_cents: int
     * }>
     */
    public function build(string $from, string $to): array
    {
        $this->groups = [];
        $start = CarbonImmutable::parse($from.' 00:00:00', 'UTC')->subDay();
        $end = CarbonImmutable::parse($to.' 00:00:00', 'UTC')->addDays(2);

        $this->bookings($start, $end, $from, $to);
        $this->payments($start, $end, $from, $to);
        $this->refunds($start, $end, $from, $to);
        $this->fees($start, $end, $from, $to);
        $this->feeRefunds($start, $end, $from, $to);

        return $this->rows();
    }

    public function toCsv(string $from, string $to): string
    {
        $rows = [];

        foreach ($this->build($from, $to) as $row) {
            $rows[] = [
                $row['location_id'],
                $row['date'],
                $row['confirmed'],
                $row['cancelled'],
                $row['no_show'],
                $row['cancellation_bps'],
                $row['no_show_bps'],
                $row['card_cents'],
                $row['offline_cents'],
                $row['refunds_cents'],
                $row['tax_cents'],
                $row['fee_cents'],
                $row['fee_refunded_cents'],
            ];
        }

        return $this->csv->render([
            'location_id',
            'date',
            'confirmed',
            'cancelled',
            'no_show',
            'cancellation_bps',
            'no_show_bps',
            'card_cents',
            'offline_cents',
            'refunds_cents',
            'tax_cents',
            'fee_cents',
            'fee_refunded_cents',
        ], $rows);
    }

    private function bookings(CarbonImmutable $start, CarbonImmutable $end, string $from, string $to): void
    {
        $bookings = Booking::query()
            ->with('location')
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->whereIn('status', [
                BookingStatus::Confirmed->value,
                BookingStatus::Cancelled->value,
                BookingStatus::NoShow->value,
            ])
            ->get();

        /** @var array<string, string> $taxable */
        $taxable = [];

        foreach ($bookings as $booking) {
            $key = $this->place($booking, $booking->starts_at, $from, $to);

            if ($key === null) {
                continue;
            }

            if ($booking->status === BookingStatus::Confirmed) {
                $this->groups[$key]['confirmed']++;
                $taxable[(string) $booking->id] = $key;
            } elseif ($booking->status === BookingStatus::Cancelled) {
                $this->groups[$key]['cancelled']++;
            } elseif ($booking->status === BookingStatus::NoShow) {
                $this->groups[$key]['no_show']++;
                $taxable[(string) $booking->id] = $key;
            }
        }

        if ($taxable === []) {
            return;
        }

        $snapshots = PricingSnapshot::query()->whereIn('booking_id', array_keys($taxable))->get();

        foreach ($snapshots as $snapshot) {
            $key = $taxable[(string) $snapshot->booking_id] ?? null;

            if ($key === null) {
                continue;
            }

            $this->groups[$key]['tax_cents'] += $this->taxCents($snapshot->payload);
        }
    }

    private function payments(CarbonImmutable $start, CarbonImmutable $end, string $from, string $to): void
    {
        $payments = Payment::query()
            ->with('booking.location')
            ->where('status', 'paid')
            ->whereIn('method', ['stripe', 'offline'])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->get();

        foreach ($payments as $payment) {
            $booking = $payment->booking instanceof Booking ? $payment->booking : null;
            $key = $this->place($booking, $payment->created_at, $from, $to);

            if ($key === null) {
                continue;
            }

            if ($payment->method === 'stripe') {
                $this->groups[$key]['card_cents'] += $payment->amount_cents;
            }

            if ($payment->method === 'offline') {
                $this->groups[$key]['offline_cents'] += $payment->amount_cents;
            }
        }
    }

    private function refunds(CarbonImmutable $start, CarbonImmutable $end, string $from, string $to): void
    {
        $refunds = Refund::query()
            ->with('booking.location')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->get();

        foreach ($refunds as $refund) {
            $booking = $refund->booking instanceof Booking ? $refund->booking : null;
            $key = $this->place($booking, $refund->created_at, $from, $to);

            if ($key === null) {
                continue;
            }

            $this->groups[$key]['refunds_cents'] += $refund->customer_refund_cents;
            $this->groups[$key]['fee_refunded_cents'] += $refund->application_fee_refund_cents;
        }
    }

    private function fees(CarbonImmutable $start, CarbonImmutable $end, string $from, string $to): void
    {
        $fees = PlatformFee::query()
            ->with('booking.location')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->get();

        foreach ($fees as $fee) {
            $booking = $fee->booking instanceof Booking ? $fee->booking : null;
            $key = $this->place($booking, $fee->created_at, $from, $to);

            if ($key === null) {
                continue;
            }

            $this->groups[$key]['fee_cents'] += $fee->amount_cents;
        }
    }

    private function feeRefunds(CarbonImmutable $start, CarbonImmutable $end, string $from, string $to): void
    {
        $refunds = PlatformFeeRefund::query()
            ->with('platformFee.booking.location')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->get();

        foreach ($refunds as $refund) {
            $fee = $refund->platformFee;
            $booking = $fee instanceof PlatformFee && $fee->booking instanceof Booking ? $fee->booking : null;
            $key = $this->place($booking, $refund->created_at, $from, $to);

            if ($key === null) {
                continue;
            }

            $this->groups[$key]['fee_refunded_cents'] += $refund->amount_cents;
        }
    }

    /**
     * @return list<array{
     *     location_id: string,
     *     date: string,
     *     confirmed: int,
     *     cancelled: int,
     *     no_show: int,
     *     cancellation_bps: int|null,
     *     no_show_bps: int|null,
     *     card_cents: int,
     *     offline_cents: int,
     *     refunds_cents: int,
     *     tax_cents: int,
     *     fee_cents: int,
     *     fee_refunded_cents: int
     * }>
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->groups as $group) {
            $denominator = $group['confirmed'] + $group['cancelled'] + $group['no_show'];
            $rows[] = [
                'location_id' => $group['location_id'],
                'date' => $group['date'],
                'confirmed' => $group['confirmed'],
                'cancelled' => $group['cancelled'],
                'no_show' => $group['no_show'],
                'cancellation_bps' => $this->rate($group['cancelled'], $denominator),
                'no_show_bps' => $this->rate($group['no_show'], $denominator),
                'card_cents' => $group['card_cents'],
                'offline_cents' => $group['offline_cents'],
                'refunds_cents' => $group['refunds_cents'],
                'tax_cents' => $group['tax_cents'],
                'fee_cents' => $group['fee_cents'],
                'fee_refunded_cents' => $group['fee_refunded_cents'],
            ];
        }

        usort($rows, function (array $left, array $right): int {
            return [$left['date'], $left['location_id']] <=> [$right['date'], $right['location_id']];
        });

        return $rows;
    }

    private function place(?Booking $booking, mixed $instant, string $from, string $to): ?string
    {
        if (! $booking instanceof Booking) {
            return null;
        }

        $locationId = (string) $booking->location_id;

        if ($locationId === '') {
            return null;
        }

        $date = $this->localDate($instant, $this->zone($booking->location));

        if ($date === null || $date < $from || $date > $to) {
            return null;
        }

        $key = $date.'|'.$locationId;

        if (! isset($this->groups[$key])) {
            $this->groups[$key] = [
                'location_id' => $locationId,
                'date' => $date,
                'confirmed' => 0,
                'cancelled' => 0,
                'no_show' => 0,
                'card_cents' => 0,
                'offline_cents' => 0,
                'refunds_cents' => 0,
                'tax_cents' => 0,
                'fee_cents' => 0,
                'fee_refunded_cents' => 0,
            ];
        }

        return $key;
    }

    private function rate(int $count, int $denominator): ?int
    {
        if ($denominator <= 0) {
            return null;
        }

        return Rounding::halfUp($count * 10_000, $denominator);
    }

    private function taxCents(mixed $payload): int
    {
        if (! is_array($payload)) {
            return 0;
        }

        $tax = $payload['tax_cents'] ?? 0;

        return is_numeric($tax) ? (int) $tax : 0;
    }

    private function zone(mixed $location): string
    {
        $timezone = $location instanceof Location ? $location->timezone : 'UTC';

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    private function localDate(mixed $instant, string $zone): ?string
    {
        if (! $instant instanceof DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::createFromInterface($instant)->timezone($zone)->format('Y-m-d');
    }
}
