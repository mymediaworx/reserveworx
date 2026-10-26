<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staff bookings list: status, a local date range, location, and item.
 * The item is the service on the booking. Dates use each location's timezone.
 */
final class BookingListFilters
{
    /**
     * Not a booking status: flagged by maintenance, damage, or a status change,
     * or paid after the slot was lost.
     */
    public const NEEDS_ATTENTION = 'attention';

    /**
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public static function needsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->where(function (Builder $flagged): void {
                $flagged->whereNotNull('attention_reason')
                    ->whereIn('status', [BookingStatus::Held->value, BookingStatus::Confirmed->value]);
            })->orWhere('status', BookingStatus::PaymentException->value);
        });
    }

    public function __construct(
        public readonly string $status,
        public readonly string $from,
        public readonly string $to,
        public readonly string $locationId,
        public readonly string $itemId,
        public readonly string $when,
        public readonly string $search,
        public readonly bool $blocked,
    ) {}

    public static function fromRequest(
        string $status,
        string $from,
        string $to,
        string $locationId,
        string $itemId,
        string $when,
        string $search,
    ): self {
        $from = trim($from);
        $to = trim($to);
        $fromOk = $from === '' || self::validDate($from);
        $toOk = $to === '' || self::validDate($to);
        $ordered = $fromOk && $toOk && ($from === '' || $to === '' || $from <= $to);

        return new self(
            status: $status,
            from: $from,
            to: $to,
            locationId: $locationId,
            itemId: $itemId,
            when: $when,
            search: trim($search),
            blocked: ! $fromOk || ! $toOk || ! $ordered,
        );
    }

    public function problem(): ?string
    {
        if (! $this->blocked) {
            return null;
        }

        $fromOk = $this->from === '' || self::validDate($this->from);
        $toOk = $this->to === '' || self::validDate($this->to);

        if (! $fromOk || ! $toOk) {
            return 'Use dates as YYYY-MM-DD.';
        }

        return 'The from date must be on or before the to date.';
    }

    /**
     * Values safe to store on a saved view. A location or item from another
     * workspace is dropped because this query already runs inside the tenant.
     *
     * @return array{status: string, from: string, to: string, location: string, item: string, when: string, search: string}
     */
    public function stored(): array
    {
        $statuses = array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::cases());
        $when = in_array($this->when, ['upcoming', 'past', 'all'], true) ? $this->when : 'upcoming';
        $location = $this->locationId !== '' && Location::query()->whereKey($this->locationId)->exists()
            ? $this->locationId
            : '';
        $item = $this->itemId !== '' && Service::query()->whereKey($this->itemId)->exists()
            ? $this->itemId
            : '';

        return [
            'status' => in_array($this->status, [...$statuses, self::NEEDS_ATTENTION], true) ? $this->status : '',
            'from' => $this->blocked ? '' : $this->from,
            'to' => $this->blocked ? '' : $this->to,
            'location' => $location,
            'item' => $item,
            'when' => $when,
            'search' => mb_substr($this->search, 0, 120),
        ];
    }

    /**
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->blocked) {
            return $query->whereRaw('0 = 1');
        }

        $statuses = array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::cases());

        if (in_array($this->status, $statuses, true)) {
            $query->where('status', $this->status);
        } elseif ($this->status === self::NEEDS_ATTENTION) {
            self::needsAttention($query);
        }

        if ($this->locationId !== '') {
            if (! Location::query()->whereKey($this->locationId)->exists()) {
                return $query->whereRaw('0 = 1');
            }

            $query->where('location_id', $this->locationId);
        }

        if ($this->itemId !== '') {
            if (! Service::query()->whereKey($this->itemId)->exists()) {
                return $query->whereRaw('0 = 1');
            }

            $query->where('service_id', $this->itemId);
        }

        $this->applyDates($query);

        $now = CarbonImmutable::now('UTC');
        $when = in_array($this->when, ['upcoming', 'past', 'all'], true) ? $this->when : 'upcoming';

        if ($when === 'upcoming') {
            $query->where('ends_at', '>=', $now)->orderBy('starts_at');
        } elseif ($when === 'past') {
            $query->where('ends_at', '<', $now)->orderByDesc('starts_at');
        } else {
            $query->orderByDesc('starts_at');
        }

        $query->orderBy('id');
        $this->applySearch($query);

        return $query;
    }

    /**
     * @param  Builder<Booking>  $query
     */
    private function applyDates(Builder $query): void
    {
        if ($this->from === '' && $this->to === '') {
            return;
        }

        $locations = Location::query()->get(['id', 'timezone']);

        if ($this->locationId !== '') {
            $locations = $locations->where('id', $this->locationId)->values();
        }

        /** @var array<string, list<string>> $groups */
        $groups = [];

        foreach ($locations as $location) {
            $groups[$this->zone($location->timezone)][] = $location->id;
        }

        if ($groups === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where(function (Builder $outer) use ($groups): void {
            $first = true;

            foreach ($groups as $zone => $ids) {
                $start = $this->bound($this->from, $zone, false);
                $end = $this->bound($this->to, $zone, true);

                if (($this->from !== '' && ! $start instanceof CarbonImmutable) || ($this->to !== '' && ! $end instanceof CarbonImmutable)) {
                    $outer->whereRaw('0 = 1');

                    return;
                }

                $clause = function (Builder $inner) use ($ids, $start, $end): void {
                    $inner->whereIn('location_id', $ids);

                    if ($start instanceof CarbonImmutable) {
                        $inner->where('starts_at', '>=', $start);
                    }

                    if ($end instanceof CarbonImmutable) {
                        $inner->where('starts_at', '<', $end);
                    }
                };

                if ($first) {
                    $outer->where($clause);
                    $first = false;
                } else {
                    $outer->orWhere($clause);
                }
            }
        });
    }

    /**
     * @param  Builder<Booking>  $query
     */
    private function applySearch(Builder $query): void
    {
        $term = mb_substr($this->search, 0, 120);

        if ($term === '') {
            return;
        }

        $customerIds = Customer::query()
            ->where(fn (Builder $inner) => $inner->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'))
            ->pluck('id');

        $query->where(fn (Builder $inner) => $inner->whereIn('customer_id', $customerIds)->orWhere('id', $term));
    }

    private function bound(string $date, string $zone, bool $end): ?CarbonImmutable
    {
        if ($date === '') {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone);

        if ($parsed === null) {
            return null;
        }

        $start = $parsed->startOfDay();

        return ($end ? $start->addDay() : $start)->utc();
    }

    private function zone(mixed $timezone): string
    {
        if (! is_string($timezone) || $timezone === '') {
            return 'UTC';
        }

        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            return 'UTC';
        }

        return $timezone;
    }

    private static function validDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
