<?php

namespace App\Services\Booking;

use App\Enums\DepartureStatus;
use App\Enums\ScheduleOwnerType;
use App\Models\AvailabilitySchedule;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Service;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Inventory\OpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Lists bookable start times for the booking screens. Every answer is a hint:
 * the allocation transaction re-checks inventory when the hold is created.
 */
final class SlotFinder
{
    /** Start times are offered on this step, in minutes. */
    public const STEP_MINUTES = 30;

    /** A location with no opening hours is open all day; offer this window. */
    private const DEFAULT_OPEN = '08:00';

    private const DEFAULT_CLOSE = '20:00';

    public function __construct(
        private readonly OpeningHours $hours,
        private readonly Allocator $allocator,
    ) {}

    /**
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, label: string, available: bool}>
     */
    public function rentalSlots(Service $service, string $date, int $durationMinutes, int $participants, ?CarbonImmutable $notBefore = null): array
    {
        $location = $service->location;

        if (! $location instanceof Location || $durationMinutes < 1 || $participants < 1) {
            return [];
        }

        $zone = $location->timezone;
        $day = $this->parseDate($date, $zone);

        if ($day === null) {
            return [];
        }

        $day = $day->startOfDay();
        $earliest = ($notBefore ?? CarbonImmutable::now())->utc();
        [$open, $close] = $this->window($location, $day);
        $slots = [];

        for ($start = $open; $start->addMinutes($durationMinutes)->lessThanOrEqualTo($close); $start = $start->addMinutes(self::STEP_MINUTES)) {
            $startUtc = $start->utc();

            if ($startUtc->lessThan($earliest)) {
                continue;
            }

            $endUtc = $startUtc->addMinutes($durationMinutes);
            $available = $this->hours->assess($location, $startUtc, $endUtc)['open']
                && $this->allocator->hint(new AllocationIntent(
                    $service->tenant_id,
                    $service->id,
                    $startUtc,
                    $endUtc,
                    $participants,
                    null,
                    null,
                    null,
                ))['available'];

            $slots[] = [
                'starts_at' => $startUtc,
                'ends_at' => $endUtc,
                'label' => $start->format('g:i A'),
                'available' => $available,
            ];
        }

        return $slots;
    }

    /**
     * Scheduled departures for a tour on one local date, with seats left.
     *
     * @return Collection<int, array{departure: Departure, seats_left: int|null}>
     */
    public function departures(Service $service, string $date, ?CarbonImmutable $notBefore = null): Collection
    {
        $zone = $service->location !== null ? $service->location->timezone : 'UTC';
        $day = $this->parseDate($date, $zone);

        if ($day === null) {
            return collect();
        }

        $from = $day->startOfDay()->utc();
        $earliest = ($notBefore ?? CarbonImmutable::now())->utc();

        return Departure::query()
            ->where('service_id', $service->id)
            ->where('status', DepartureStatus::Scheduled->value)
            ->where('starts_at', '>=', $from->max($earliest))
            ->where('starts_at', '<', $from->addDay())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Departure $departure): array => [
                'departure' => $departure,
                'seats_left' => $departure->seats === null ? null : max(0, $departure->seats - $departure->headcount()),
            ]);
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
}
