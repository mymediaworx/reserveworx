<?php

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\RentalCheckout;
use App\Models\Service;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

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
            'fees' => [
                'charged_cents' => $charged,
                'refunded_cents' => $refunded,
                'net_cents' => $charged - $refunded,
                'period' => 'all_time',
            ],
        ];
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

    private function validZone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }
}
