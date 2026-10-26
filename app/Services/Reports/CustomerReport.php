<?php

namespace App\Services\Reports;

use App\Domain\Fees\FeeChannel;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Location;
use App\Models\PricingSnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * New and repeat customers, and the booking channel already stored on the
 * pricing snapshot. Confirmed and no-show bookings count. Held and cancelled
 * bookings do not, and an earlier held or cancelled booking does not make a
 * later one a repeat.
 *
 * A customer is new on the location and local start date of their earliest
 * counted booking. Earliest means the smaller `starts_at`, then the smaller
 * id. A later counted booking is a repeat. A booking with no customer is
 * anonymous. Identity is the customer row, not the email. The channel is
 * `direct` or `third_party` when the snapshot says so, and `unrecorded`
 * otherwise. This read does not write a summary row.
 */
final class CustomerReport
{
    public function __construct(private readonly CsvTable $csv) {}

    /**
     * @return array{
     *     customers: list<array{location_id: string, date: string, customers: int, new_customers: int, repeat_customers: int, bookings: int, new_bookings: int, repeat_bookings: int, anonymous_bookings: int}>,
     *     attribution: list<array{location_id: string, date: string, channel: string, bookings: int}>
     * }
     */
    public function build(string $from, string $to): array
    {
        $included = $this->includedBookings($from, $to, null);

        if ($included->isEmpty()) {
            return ['customers' => [], 'attribution' => []];
        }

        $firstIds = $this->firstBookingIds($included);
        $channels = $this->channels($included);

        /** @var array<string, array{location_id: string, date: string, customer_ids: array<string, true>, new_customer_ids: array<string, true>, bookings: int, new_bookings: int, repeat_bookings: int, anonymous_bookings: int}> $customerGroups */
        $customerGroups = [];
        /** @var array<string, array{location_id: string, date: string, channel: string, bookings: int}> $attribution */
        $attribution = [];

        foreach ($included as $booking) {
            $date = $this->localDate($booking->starts_at, $this->zone($booking->location));

            if ($date === null) {
                continue;
            }

            $locationId = (string) $booking->location_id;
            $bookingId = (string) $booking->id;
            $customerKey = $date.'|'.$locationId;

            if (! isset($customerGroups[$customerKey])) {
                $customerGroups[$customerKey] = [
                    'location_id' => $locationId,
                    'date' => $date,
                    'customer_ids' => [],
                    'new_customer_ids' => [],
                    'bookings' => 0,
                    'new_bookings' => 0,
                    'repeat_bookings' => 0,
                    'anonymous_bookings' => 0,
                ];
            }

            $customerGroups[$customerKey]['bookings']++;

            $customerId = $booking->customer_id;

            if (! is_string($customerId) || $customerId === '') {
                $customerGroups[$customerKey]['anonymous_bookings']++;
            } else {
                $customerGroups[$customerKey]['customer_ids'][$customerId] = true;
                $firstId = $firstIds[$customerId] ?? null;

                if ($firstId === null || $firstId === $bookingId) {
                    $customerGroups[$customerKey]['new_bookings']++;
                    $customerGroups[$customerKey]['new_customer_ids'][$customerId] = true;
                } else {
                    $customerGroups[$customerKey]['repeat_bookings']++;
                }
            }

            $channel = $channels[$bookingId] ?? 'unrecorded';
            $channelKey = $customerKey.'|'.$channel;

            if (! isset($attribution[$channelKey])) {
                $attribution[$channelKey] = [
                    'location_id' => $locationId,
                    'date' => $date,
                    'channel' => $channel,
                    'bookings' => 0,
                ];
            }

            $attribution[$channelKey]['bookings']++;
        }

        $customers = [];

        foreach ($customerGroups as $group) {
            $customers[] = [
                'location_id' => $group['location_id'],
                'date' => $group['date'],
                'customers' => count($group['customer_ids']),
                'new_customers' => count($group['new_customer_ids']),
                'repeat_customers' => count(array_diff_key($group['customer_ids'], $group['new_customer_ids'])),
                'bookings' => $group['bookings'],
                'new_bookings' => $group['new_bookings'],
                'repeat_bookings' => $group['repeat_bookings'],
                'anonymous_bookings' => $group['anonymous_bookings'],
            ];
        }

        usort($customers, function (array $left, array $right): int {
            return [$left['date'], $left['location_id']] <=> [$right['date'], $right['location_id']];
        });

        $attributionRows = array_values($attribution);
        usort($attributionRows, function (array $left, array $right): int {
            return [$left['date'], $left['location_id'], $left['channel']]
                <=> [$right['date'], $right['location_id'], $right['channel']];
        });

        return [
            'customers' => $customers,
            'attribution' => $attributionRows,
        ];
    }

    /**
     * Unique customers for one window. A customer is new when their earliest
     * confirmed or no-show booking is inside this window. Anyone else who
     * booked in the window is a repeat. A second visit in the same window
     * does not also count that new customer as a repeat. Daily rows are not
     * added together, because the same person would be counted twice.
     *
     * @return array{
     *     new_customers: int,
     *     repeat_customers: int,
     *     attribution: list<array{channel: string, bookings: int}>
     * }
     */
    public function windowTotals(string $from, string $to, ?string $locationId): array
    {
        $included = $this->includedBookings($from, $to, $locationId);

        if ($included->isEmpty()) {
            return [
                'new_customers' => 0,
                'repeat_customers' => 0,
                'attribution' => [],
            ];
        }

        $firstIds = $this->firstBookingIds($included);
        $channels = $this->channels($included);
        /** @var array<string, true> $includedIds */
        $includedIds = [];

        foreach ($included as $booking) {
            $includedIds[(string) $booking->id] = true;
        }

        /** @var array<string, true> $new */
        $new = [];
        /** @var array<string, true> $repeat */
        $repeat = [];
        /** @var array<string, int> $attribution */
        $attribution = [];

        foreach ($included as $booking) {
            $bookingId = (string) $booking->id;
            $customerId = $booking->customer_id;

            if (is_string($customerId) && $customerId !== '') {
                $firstId = $firstIds[$customerId] ?? null;

                if ($firstId !== null && isset($includedIds[$firstId])) {
                    $new[$customerId] = true;
                } else {
                    $repeat[$customerId] = true;
                }
            }

            $channel = $channels[$bookingId] ?? 'unrecorded';
            $attribution[$channel] = ($attribution[$channel] ?? 0) + 1;
        }

        $repeat = array_diff_key($repeat, $new);
        /** @var list<array{channel: string, bookings: int}> $rows */
        $rows = [];

        foreach ($attribution as $channel => $bookings) {
            $rows[] = [
                'channel' => $channel,
                'bookings' => $bookings,
            ];
        }

        usort($rows, fn (array $left, array $right): int => $left['channel'] <=> $right['channel']);

        return [
            'new_customers' => count($new),
            'repeat_customers' => count($repeat),
            'attribution' => $rows,
        ];
    }

    public function toCsv(string $from, string $to): string
    {
        $report = $this->build($from, $to);
        $rows = [];

        foreach ($report['customers'] as $row) {
            $rows[] = [
                'customer',
                $row['location_id'],
                $row['date'],
                $row['customers'],
                $row['new_customers'],
                $row['repeat_customers'],
                $row['bookings'],
                $row['new_bookings'],
                $row['repeat_bookings'],
                $row['anonymous_bookings'],
                null,
            ];
        }

        foreach ($report['attribution'] as $row) {
            $rows[] = [
                'attribution',
                $row['location_id'],
                $row['date'],
                null,
                null,
                null,
                $row['bookings'],
                null,
                null,
                null,
                $row['channel'],
            ];
        }

        return $this->csv->render([
            'record',
            'location_id',
            'date',
            'customers',
            'new_customers',
            'repeat_customers',
            'bookings',
            'new_bookings',
            'repeat_bookings',
            'anonymous_bookings',
            'channel',
        ], $rows);
    }

    /**
     * @return Collection<int, Booking>
     */
    private function includedBookings(string $from, string $to, ?string $locationId): Collection
    {
        $fromStart = CarbonImmutable::parse($from.' 00:00:00', 'UTC')->subDay();
        $toEnd = CarbonImmutable::parse($to.' 00:00:00', 'UTC')->addDays(2);

        $bookings = Booking::query()
            ->with('location')
            ->where('starts_at', '>=', $fromStart)
            ->where('starts_at', '<', $toEnd)
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::NoShow->value])
            ->get();

        $included = $bookings->filter(function (Booking $booking) use ($from, $to): bool {
            $date = $this->localDate($booking->starts_at, $this->zone($booking->location));

            return $date !== null && $date >= $from && $date <= $to;
        })->values();

        if ($locationId === null || $locationId === '') {
            return $included;
        }

        return $included->filter(
            fn (Booking $booking): bool => (string) $booking->location_id === $locationId,
        )->values();
    }

    /**
     * @param  Collection<int, Booking>  $included
     * @return array<string, string>
     */
    private function firstBookingIds(Collection $included): array
    {
        $customerIds = $included
            ->pluck('customer_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        if ($customerIds === []) {
            return [];
        }

        $latest = null;

        foreach ($included as $booking) {
            $instant = $this->instant($booking->starts_at);

            if ($instant === null) {
                continue;
            }

            if ($latest === null || $instant->greaterThan($latest)) {
                $latest = $instant;
            }
        }

        if ($latest === null) {
            return [];
        }

        $history = Booking::query()
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::NoShow->value])
            ->where('starts_at', '<=', $latest)
            ->get(['id', 'customer_id', 'starts_at']);

        /** @var array<string, Booking> $first */
        $first = [];

        foreach ($history as $booking) {
            $customerId = $booking->customer_id;

            if (! is_string($customerId) || $customerId === '') {
                continue;
            }

            $current = $first[$customerId] ?? null;

            if ($current === null || $this->before($booking, $current)) {
                $first[$customerId] = $booking;
            }
        }

        $ids = [];

        foreach ($first as $customerId => $booking) {
            $ids[$customerId] = (string) $booking->id;
        }

        return $ids;
    }

    private function before(Booking $candidate, Booking $current): bool
    {
        $left = $this->instant($candidate->starts_at);
        $right = $this->instant($current->starts_at);

        if ($left === null || $right === null) {
            return false;
        }

        if ($left->getTimestamp() !== $right->getTimestamp()) {
            return $left->getTimestamp() < $right->getTimestamp();
        }

        return (string) $candidate->id < (string) $current->id;
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return array<string, string>
     */
    private function channels(Collection $bookings): array
    {
        $ids = $bookings->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $known = [];

        foreach (PricingSnapshot::query()->whereIn('booking_id', $ids)->orderBy('id')->get() as $snapshot) {
            $bookingId = (string) $snapshot->booking_id;
            $channel = $this->channel($snapshot->payload);

            if (! isset($known[$bookingId]) || ($known[$bookingId] === 'unrecorded' && $channel !== 'unrecorded')) {
                $known[$bookingId] = $channel;
            }
        }

        return $known;
    }

    private function channel(mixed $payload): string
    {
        if (! is_array($payload)) {
            return 'unrecorded';
        }

        $channel = $payload['channel'] ?? null;

        if ($channel === FeeChannel::Direct->value || $channel === FeeChannel::ThirdParty->value) {
            return $channel;
        }

        return 'unrecorded';
    }

    private function zone(mixed $location): string
    {
        $timezone = $location instanceof Location ? $location->timezone : 'UTC';

        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }

    private function localDate(mixed $instant, string $zone): ?string
    {
        $parsed = $this->instant($instant);

        if ($parsed === null) {
            return null;
        }

        return $parsed->timezone($zone)->format('Y-m-d');
    }

    private function instant(mixed $instant): ?CarbonImmutable
    {
        if (! $instant instanceof DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::createFromInterface($instant)->utc();
    }
}
