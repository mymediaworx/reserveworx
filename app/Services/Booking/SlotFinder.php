<?php

namespace App\Services\Booking;

use App\Enums\DepartureStatus;
use App\Enums\ResourceType;
use App\Enums\ScheduleOwnerType;
use App\Models\AvailabilitySchedule;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Service;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Inventory\OpeningHours;
use App\Support\DisplayZone;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Collection;

/**
 * Lists bookable start times for the booking screens. Every answer is a hint:
 * the allocation transaction re-checks inventory when the hold is created.
 */
final class SlotFinder
{
    /** Start times are offered on this step, in minutes. */
    public const STEP_MINUTES = 30;

    /**
     * A posted rental start. A plain clock is H:i. A repeated fall-back hour
     * adds the UTC offset, such as 01:30-04:00, so the two instants stay distinct.
     */
    public const START_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d(?:[+-](?:0\d|1[0-4]):[0-5]\d)?$/';

    /** A location with no opening hours is open all day; offer this window. */
    private const DEFAULT_OPEN = '08:00';

    private const DEFAULT_CLOSE = '20:00';

    public function __construct(
        private readonly OpeningHours $hours,
        private readonly Allocator $allocator,
    ) {}

    /**
     * Pickup times on one local date.
     *
     * A length that finishes before close only lists starts that end inside
     * that window. A longer length, such as a multi-day rental, lists every
     * start while the shop is open for pickup. Opening hours then accept a
     * start only when the return instant is open and no blackout covers a
     * local date in between. The return is that many elapsed minutes later,
     * including across a daylight-saving change. A variant id, when the guest
     * chose an option, is passed to the hint so that option's boat or pool
     * is what the slot marks open or booked.
     *
     * A fall-back morning keeps both occurrences of a repeated hour. The label
     * names that abbreviation, and the value carries the offset. A spring-forward
     * morning does not offer the hour that does not exist. Any other start stays
     * a clock time, such as 10:00 AM, with an H:i value.
     *
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, label: string, value: string, available: bool, closed: bool}>
     */
    public function rentalSlots(Service $service, string $date, int $durationMinutes, int $participants, ?CarbonImmutable $notBefore = null, bool $counterSale = false, ?string $variantId = null): array
    {
        $location = $service->location;

        if (! $location instanceof Location || $durationMinutes < 1 || $participants < 1) {
            return [];
        }

        $service->loadMissing('requirements');
        $zone = DisplayZone::forLocation($location);
        $day = $this->parseDate($date, $zone);

        if ($day === null) {
            return [];
        }

        $day = $day->startOfDay();
        $earliest = ($notBefore ?? CarbonImmutable::now())->utc();
        [$open, $close] = $this->window($location, $day);
        $overnight = $open->addMinutes($durationMinutes)->greaterThan($close);
        /** @var list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, label: string, civil: string, offset: string, abbr: string, available: bool, closed: bool}> $slots */
        $slots = [];

        for ($start = $open; $this->offersStart($start, $close, $durationMinutes, $overnight); $start = $start->addMinutes(self::STEP_MINUTES)) {
            $startUtc = $start->utc();

            if ($startUtc->lessThan($earliest)) {
                continue;
            }

            $endUtc = $startUtc->addMinutes($durationMinutes);
            $closed = $this->intervalClosed($service, $startUtc, $endUtc);
            $available = ! $closed
                && $this->hours->assess($location, $startUtc, $endUtc)['open']
                && $this->allocator->hint(new AllocationIntent(
                    $service->tenant_id,
                    $service->id,
                    $startUtc,
                    $endUtc,
                    $participants,
                    null,
                    null,
                    null,
                    counterSale: $counterSale,
                    variantId: $variantId,
                ))['available'];

            $slots[] = [
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
                'label' => $start->format('g:i A'),
                'civil' => $start->format('H:i'),
                'offset' => $start->format('P'),
                'abbr' => $start->format('T'),
                'available' => $available,
                'closed' => $closed,
            ];
        }

        return $this->namedSlots($slots);
    }

    /**
     * The location-local instant for a posted rental start.
     *
     * A plain H:i uses the only instant with that clock, or the earlier one
     * when the hour happens twice. An offset, such as 01:30-05:00, selects
     * that occurrence. A clock that does not exist, including 2:30 AM on a
     * spring-forward morning, is refused instead of shifted forward.
     */
    public function resolveStart(string $date, string $start, string $zone): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        $offset = null;
        $clock = $start;

        if (preg_match('/^((?:[01]\d|2[0-3]):[0-5]\d)([+-](?:0\d|1[0-4]):[0-5]\d)$/', $start, $marked) === 1) {
            $clock = $marked[1];
            $offset = $marked[2];
        } elseif (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) !== 1) {
            return null;
        }

        $matches = $this->matchingInstants($date, $clock, $zone);

        if ($offset !== null) {
            foreach ($matches as $instant) {
                if ($instant->format('P') === $offset) {
                    return $instant;
                }
            }

            return null;
        }

        return $matches[0] ?? null;
    }

    /**
     * Scheduled departures for a tour on one location-local date, with seats left.
     *
     * The window is that location's half-open day: local midnight through the
     * next local midnight. A spring-forward day is 23 hours and a fall-back
     * day is 25. The end is the next local midnight in UTC, not 24 hours
     * after the UTC start.
     *
     * @return Collection<int, array{departure: Departure, seats_left: int|null, closed: bool}>
     */
    public function departures(Service $service, string $date, ?CarbonImmutable $notBefore = null): Collection
    {
        $service->loadMissing(['location', 'requirements']);
        $zone = DisplayZone::forLocation($service->location);
        $day = $this->parseDate($date, $zone);

        if ($day === null) {
            return collect();
        }

        $localStart = $day->startOfDay();
        $from = $localStart->utc();
        $until = $localStart->addDay()->utc();
        $earliest = ($notBefore ?? CarbonImmutable::now())->utc();

        return Departure::query()
            ->where('service_id', $service->id)
            ->where('status', DepartureStatus::Scheduled->value)
            ->where('starts_at', '>=', $from->max($earliest))
            ->where('starts_at', '<', $until)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Departure $departure): array => [
                'departure' => $departure,
                'seats_left' => $departure->seats === null ? null : max(0, $departure->seats - $departure->headcount()),
                'closed' => $this->intervalClosed(
                    $service,
                    CarbonImmutable::instance($departure->starts_at)->utc(),
                    CarbonImmutable::instance($departure->ends_at)->utc(),
                ),
            ]);
    }

    /**
     * Scheduled departures a guest can still join, soonest first.
     *
     * @return Collection<int, Departure>
     */
    public function upcoming(Service $service, int $limit = 90): Collection
    {
        return Departure::query()
            ->where('service_id', $service->id)
            ->where('status', DepartureStatus::Scheduled->value)
            ->where('starts_at', '>', CarbonImmutable::now())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The scheduled departure a public tour booking joins.
     * An explicit id wins. Otherwise exactly one departure must start at that instant.
     */
    public function matchingDeparture(Service $service, ?string $departureId, ?CarbonImmutable $startsAt): ?Departure
    {
        if (is_string($departureId) && $departureId !== '') {
            $departure = Departure::query()
                ->where('service_id', $service->id)
                ->where('status', DepartureStatus::Scheduled->value)
                ->find($departureId);

            return $departure instanceof Departure ? $departure : null;
        }

        if (! $startsAt instanceof CarbonImmutable) {
            return null;
        }

        $matches = Departure::query()
            ->where('service_id', $service->id)
            ->where('status', DepartureStatus::Scheduled->value)
            ->where('starts_at', $startsAt->utc())
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $departure = $matches->first();

        return $departure instanceof Departure ? $departure : null;
    }

    private function intervalClosed(Service $service, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $location = $service->location;

        if (! $location instanceof Location) {
            return false;
        }

        $place = $this->hours->assess($location, $start, $end);

        if ($place['blackout'] || $place['closed']) {
            return true;
        }

        foreach ($service->requirements as $requirement) {
            if ($requirement->resourceType() === ResourceType::Fleet) {
                continue;
            }

            $resource = $this->hours->assess($location, $start, $end, $requirement->resourceType()->value, $requirement->resourceId());

            if ($resource['closed']) {
                return true;
            }
        }

        return false;
    }

    /**
     * A same-day length must finish by close. A longer length may start any
     * time the shop is open for pickup; the return is checked separately.
     */
    private function offersStart(CarbonImmutable $start, CarbonImmutable $close, int $durationMinutes, bool $overnight): bool
    {
        if ($overnight) {
            return $start->lessThan($close);
        }

        return $start->addMinutes($durationMinutes)->lessThanOrEqualTo($close);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(Location $location, CarbonImmutable $day): array
    {
        $rows = AvailabilitySchedule::query()
            ->where('owner_type', ScheduleOwnerType::Location->value)
            ->where('owner_id', $location->id)
            ->where('weekday', $day->dayOfWeek)
            ->get();

        if ($rows->isEmpty()) {
            return [$this->at($day, self::DEFAULT_OPEN), $this->at($day, self::DEFAULT_CLOSE)];
        }

        $opens = $rows->min(fn (AvailabilitySchedule $row) => substr((string) $row->opens_local, 0, 5));
        $closes = $rows->max(fn (AvailabilitySchedule $row) => substr((string) $row->closes_local, 0, 5));

        return [$this->at($day, (string) $opens), $this->at($day, (string) $closes)];
    }

    private function parseDate(string $date, string $zone): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, $zone);
    }

    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $day->setTime($hour, $minute);
    }

    /**
     * @param  list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, label: string, civil: string, offset: string, abbr: string, available: bool, closed: bool}>  $slots
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, label: string, value: string, available: bool, closed: bool}>
     */
    private function namedSlots(array $slots): array
    {
        $counts = [];

        foreach ($slots as $slot) {
            $counts[$slot['civil']] = ($counts[$slot['civil']] ?? 0) + 1;
        }

        $named = [];

        foreach ($slots as $slot) {
            $repeated = ($counts[$slot['civil']] ?? 0) > 1;
            $named[] = [
                'starts_at' => $slot['starts_at'],
                'ends_at' => $slot['ends_at'],
                'label' => $repeated ? $slot['label'].' '.$slot['abbr'] : $slot['label'],
                'value' => $repeated ? $slot['civil'].$slot['offset'] : $slot['civil'],
                'available' => $slot['available'],
                'closed' => $slot['closed'],
            ];
        }

        return $named;
    }

    /**
     * Instants on that local date whose clock is $clock, earliest first.
     * A missing spring-forward hour returns none.
     *
     * @return list<CarbonImmutable>
     */
    private function matchingInstants(string $date, string $clock, string $timezone): array
    {
        try {
            $zone = new DateTimeZone($timezone);
            $midnight = new DateTimeImmutable($date.' 00:00:00', $zone);
        } catch (Exception) {
            return [];
        }

        $horizon = $midnight->modify('+36 hours');
        $transitions = $zone->getTransitions($midnight->getTimestamp() - 3600, $horizon->getTimestamp());
        $matches = [];
        $count = count($transitions);
        $wantedClock = $date.' '.$clock;

        for ($index = 0; $index < $count; $index++) {
            $offset = (int) $transitions[$index]['offset'];
            $from = (int) $transitions[$index]['ts'];
            $until = $index + 1 < $count ? (int) $transitions[$index + 1]['ts'] : $horizon->getTimestamp();
            $wanted = $this->civilUtc($date, $clock.':00') - $offset;

            if ($wanted < $from || $wanted >= $until) {
                continue;
            }

            $local = CarbonImmutable::createFromTimestampUTC($wanted)->timezone($timezone);

            if ($local->format('Y-m-d H:i') !== $wantedClock) {
                continue;
            }

            $matches[$local->getTimestamp()] = $local;
        }

        ksort($matches);

        return array_values($matches);
    }

    private function civilUtc(string $date, string $clock): int
    {
        return (new DateTimeImmutable($date.' '.$clock, new DateTimeZone('UTC')))->getTimestamp();
    }
}
