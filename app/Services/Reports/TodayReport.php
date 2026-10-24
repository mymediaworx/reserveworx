<?php

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\Service;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * A live read of one calendar day. Utilization, conversion, and revenue per
 * resource-hour stay off this path until a scheduled summary table exists.
 */
final class TodayReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(?string $date): array
    {
        $reportDate = $this->reportDate($date);
        $clockZone = $this->clockTimezone();
        $windowStart = CarbonImmutable::parse($reportDate.' 00:00:00', 'UTC')->subDays(2);
        $windowEnd = CarbonImmutable::parse($reportDate.' 00:00:00', 'UTC')->addDays(2);

        $bookings = Booking::query()
            ->with(['service', 'location'])
            ->where('starts_at', '>=', $windowStart)
            ->where('starts_at', '<', $windowEnd)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Booking $booking): bool => $this->onDate($booking->starts_at, $this->zoneOf($booking->location), $reportDate))
            ->values();

        $departures = Departure::query()
            ->with(['service', 'location'])
            ->where('starts_at', '>=', $windowStart)
            ->where('starts_at', '<', $windowEnd)
            ->where('status', '!=', DepartureStatus::Cancelled->value)
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Departure $departure): bool => $this->onDate($departure->starts_at, $this->zoneOf($departure->location), $reportDate))
            ->values();

        $shownBookings = $bookings->take(100);
        $shownDepartures = $departures->take(100);
        $charged = (int) PlatformFee::query()->sum('amount_cents');
        $refunded = (int) PlatformFee::query()->sum('refunded_cents');
        $payments = $this->payments($windowStart, $windowEnd, $clockZone, $reportDate);

        return [
            'date' => $reportDate,
            'timezone' => $clockZone,
            'scope' => 'live',
            'bookings_omitted' => max(0, $bookings->count() - $shownBookings->count()),
            'departures_omitted' => max(0, $departures->count() - $shownDepartures->count()),
            'bookings' => $shownBookings->map(fn (Booking $booking): array => [
                'id' => $booking->id,
                'service' => $booking->service instanceof Service ? $booking->service->name : 'Booking',
                'starts_at' => $this->iso($booking->starts_at),
                'status' => $booking->status->value,
                'participants' => $booking->participants,
                'attention_reason' => $booking->attention_reason,
            ])->all(),
            'departures' => $shownDepartures->map(fn (Departure $departure): array => [
                'id' => $departure->id,
                'service' => $departure->service instanceof Service ? $departure->service->name : 'Departure',
                'starts_at' => $this->iso($departure->starts_at),
                'seats' => $departure->seats,
                'headcount' => $departure->headcount(),
            ])->all(),
            'open_checkouts' => RentalCheckout::query()->whereNull('returned_at')->count(),
            'overdue_checkouts' => RentalCheckout::query()->whereNull('returned_at')->where('due_at', '<', CarbonImmutable::now('UTC'))->count(),
            'payments' => $payments,
            'totals' => $this->totals($bookings, $windowStart, $windowEnd, $clockZone, $reportDate),
            'outcomes' => $this->outcomes($windowStart, $windowEnd, $reportDate),
            'fees' => [
                'charged_cents' => $charged,
                'refunded_cents' => $refunded,
                'net_cents' => $charged - $refunded,
                'period' => 'all_time',
            ],
        ];
    }

    public function toCsv(?string $date): string
    {
        /** @var array<string, mixed> $report */
        $report = $this->build($date);
        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            throw new RuntimeException('Could not build the report.');
        }

        fputcsv($handle, ['record', 'id', 'name', 'starts_at', 'detail', 'count', 'amount_cents']);

        $bookings = is_array($report['bookings'] ?? null) ? $report['bookings'] : [];

        foreach ($bookings as $booking) {
            if (! is_array($booking)) {
                continue;
            }

            $detail = is_string($booking['status'] ?? null) ? $booking['status'] : '';
            $attention = $booking['attention_reason'] ?? null;

            if (is_string($attention) && $attention !== '') {
                $detail .= ' '.$attention;
            }

            fputcsv($handle, [
                'booking',
                $booking['id'] ?? '',
                $booking['service'] ?? '',
                $booking['starts_at'] ?? '',
                $detail,
                $booking['participants'] ?? '',
                '',
            ]);
        }

        $departures = is_array($report['departures'] ?? null) ? $report['departures'] : [];

        foreach ($departures as $departure) {
            if (! is_array($departure)) {
                continue;
            }

            fputcsv($handle, [
                'departure',
                $departure['id'] ?? '',
                $departure['service'] ?? '',
                $departure['starts_at'] ?? '',
                'seats '.($departure['seats'] ?? ''),
                $departure['headcount'] ?? '',
                '',
            ]);
        }

        $payments = is_array($report['payments'] ?? null) ? $report['payments'] : [];
        $fees = is_array($report['fees'] ?? null) ? $report['fees'] : [];
        fputcsv($handle, ['checkout', 'open', '', '', 'checked out', $report['open_checkouts'] ?? 0, '']);
        fputcsv($handle, ['checkout', 'overdue', '', '', 'overdue', $report['overdue_checkouts'] ?? 0, '']);
        fputcsv($handle, ['payment', 'stripe', '', '', 'card', '', $payments['stripe_cents'] ?? 0]);
        fputcsv($handle, ['payment', 'offline', '', '', 'offline', '', $payments['offline_cents'] ?? 0]);
        $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];
        fputcsv($handle, ['total', 'gross', '', '', 'services', '', $totals['gross_cents'] ?? 0]);
        fputcsv($handle, ['total', 'tax', '', '', 'tax', '', $totals['tax_cents'] ?? 0]);
        fputcsv($handle, ['total', 'booking_fee', '', '', 'booking fee', '', $totals['booking_fee_cents'] ?? 0]);
        fputcsv($handle, ['total', 'refunds', '', '', 'customer refund', '', $totals['refunds_cents'] ?? 0]);
        fputcsv($handle, ['fee', 'net', '', '', 'all_time', '', $fees['net_cents'] ?? 0]);
        $outcomes = is_array($report['outcomes'] ?? null) ? $report['outcomes'] : [];
        fputcsv($handle, ['outcome', 'confirmed', '', '', 'confirmed', $outcomes['confirmed'] ?? 0, '']);
        fputcsv($handle, ['outcome', 'cancelled', '', '', 'cancelled', $outcomes['cancelled'] ?? 0, '']);
        fputcsv($handle, ['outcome', 'no_show', '', '', 'no show', $outcomes['no_show'] ?? 0, '']);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    private function reportDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return CarbonImmutable::now($this->clockTimezone())->toDateString();
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }

        return $date;
    }

    private function clockTimezone(): string
    {
        $location = Location::query()->orderBy('name')->first();

        if (! $location instanceof Location) {
            return 'UTC';
        }

        return $this->validZone($location->timezone) ? $location->timezone : 'UTC';
    }

    private function zoneOf(mixed $location): string
    {
        if (! $location instanceof Location || ! $this->validZone($location->timezone)) {
            return 'UTC';
        }

        return $location->timezone;
    }

    private function onDate(mixed $instant, string $timezone, string $reportDate): bool
    {
        if (! $instant instanceof DateTimeInterface) {
            return false;
        }

        return CarbonImmutable::parse($instant)->timezone($timezone)->toDateString() === $reportDate;
    }

    private function iso(mixed $instant): ?string
    {
        if (! $instant instanceof DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::parse($instant)->utc()->toIso8601String();
    }

    /**
     * @return array{stripe_cents: int, offline_cents: int}
     */
    private function payments(CarbonImmutable $windowStart, CarbonImmutable $windowEnd, string $clockZone, string $reportDate): array
    {
        $stripe = 0;
        $offline = 0;

        $rows = Payment::query()
            ->where('status', 'paid')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<', $windowEnd)
            ->get();

        foreach ($rows as $payment) {
            if (! $this->onDate($payment->created_at, $clockZone, $reportDate)) {
                continue;
            }

            if ($payment->method === 'offline') {
                $offline += $payment->amount_cents;
            }

            if ($payment->method === 'stripe') {
                $stripe += $payment->amount_cents;
            }
        }

        return [
            'stripe_cents' => $stripe,
            'offline_cents' => $offline,
        ];
    }

    /**
     * Gross, tax, and booking fee follow bookings that start on the report date.
     * Refunds follow the day the refund row was recorded, in the report timezone.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return array{gross_cents: int, tax_cents: int, booking_fee_cents: int, refunds_cents: int}
     */
    private function totals(Collection $bookings, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, string $clockZone, string $reportDate): array
    {
        $gross = 0;
        $tax = 0;
        $fee = 0;
        $ids = $bookings->pluck('id')->all();

        if ($ids !== []) {
            foreach (PricingSnapshot::query()->whereIn('booking_id', $ids)->get() as $snapshot) {
                $payload = $snapshot->payload;
                $services = $payload['services_cents'] ?? $payload['basis_cents'] ?? 0;
                $taxAmount = $payload['tax_cents'] ?? 0;
                $feeAmount = $payload['fee_cents'] ?? 0;
                $gross += is_numeric($services) ? (int) $services : 0;
                $tax += is_numeric($taxAmount) ? (int) $taxAmount : 0;
                $fee += is_numeric($feeAmount) ? (int) $feeAmount : 0;
            }
        }

        $refunds = 0;

        foreach (Refund::query()->where('created_at', '>=', $windowStart)->where('created_at', '<', $windowEnd)->get() as $refund) {
            if (! $this->onDate($refund->created_at, $clockZone, $reportDate)) {
                continue;
            }

            $refunds += $refund->customer_refund_cents;
        }

        return [
            'gross_cents' => $gross,
            'tax_cents' => $tax,
            'booking_fee_cents' => $fee,
            'refunds_cents' => $refunds,
        ];
    }

    /**
     * Counts bookings that start on the report date in the booking location's zone.
     * Holds, unpaid checkouts, and payment exceptions are left out of the rates.
     * Basis points use integer division. A day with none of these statuses is zero.
     *
     * @return array{confirmed: int, cancelled: int, no_show: int, cancellation_bps: int, no_show_bps: int}
     */
    private function outcomes(CarbonImmutable $windowStart, CarbonImmutable $windowEnd, string $reportDate): array
    {
        $rows = Booking::query()
            ->with('location')
            ->where('starts_at', '>=', $windowStart)
            ->where('starts_at', '<', $windowEnd)
            ->whereIn('status', [
                BookingStatus::Confirmed->value,
                BookingStatus::Cancelled->value,
                BookingStatus::NoShow->value,
            ])
            ->get()
            ->filter(fn (Booking $booking): bool => $this->onDate($booking->starts_at, $this->zoneOf($booking->location), $reportDate));

        $confirmed = $rows->filter(fn (Booking $booking): bool => $booking->status === BookingStatus::Confirmed)->count();
        $cancelled = $rows->filter(fn (Booking $booking): bool => $booking->status === BookingStatus::Cancelled)->count();
        $noShow = $rows->filter(fn (Booking $booking): bool => $booking->status === BookingStatus::NoShow)->count();
        $denominator = $confirmed + $cancelled + $noShow;

        return [
            'confirmed' => $confirmed,
            'cancelled' => $cancelled,
            'no_show' => $noShow,
            'cancellation_bps' => $denominator === 0 ? 0 : intdiv($cancelled * 10000, $denominator),
            'no_show_bps' => $denominator === 0 ? 0 : intdiv($noShow * 10000, $denominator),
        ];
    }

    private function validZone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }
}
