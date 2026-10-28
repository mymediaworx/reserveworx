<?php

namespace App\Services\Inventory;

use App\Enums\ScheduleOwnerType;
use App\Models\AvailabilitySchedule;
use App\Models\Blackout;
use App\Models\Location;
use App\Models\LocationClosure;
use App\Support\DisplayZone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class OpeningHours
{
    public function __construct(private readonly ClosureCalendar $closures) {}

    /**
     * Pickup and return must fall in an open window. A location with no rows is open all day.
     * A resource with no rows adds no extra limit. A blackout covers each local date the
     * half-open interval touches. A closure covers the customer interval itself.
     * A category or single-resource closure does not close the rest of the location.
     * A location zone that is not a real name uses the business zone.
     *
     * @return array{open: bool, blackout: bool, closed: bool}
     */
    public function assess(Location $location, CarbonImmutable $startUtc, CarbonImmutable $endUtc, ?string $resourceType = null, ?string $resourceId = null): array
    {
        $start = $startUtc->utc();
        $end = $endUtc->utc();
        $zone = DisplayZone::forLocation($location);
        $pickup = $start->timezone($zone);
        $return = $end->subSecond()->timezone($zone);

        $blackout = $this->coversBlackout($location, $pickup, $return);
        $closed = $this->coversClosure($location, $start, $end, $resourceType, $resourceId);
        $open = $this->instantOpen(ScheduleOwnerType::Location, $location->id, $pickup, true)
            && $this->instantOpen(ScheduleOwnerType::Location, $location->id, $return, true);

        if ($resourceType !== null && $resourceId !== null) {
            $owner = ScheduleOwnerType::from($resourceType);
            $open = $open
                && $this->instantOpen($owner, $resourceId, $pickup, true)
                && $this->instantOpen($owner, $resourceId, $return, true);
        }

        return ['open' => $open && ! $blackout && ! $closed, 'blackout' => $blackout, 'closed' => $closed];
    }

    private function coversBlackout(Location $location, CarbonImmutable $pickup, CarbonImmutable $return): bool
    {
        $dates = [];
        $cursor = $pickup->startOfDay();
        $last = $return->startOfDay();

        while ($cursor->lessThanOrEqualTo($last)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        /** @var Collection<int, Blackout> $rows */
        $rows = Blackout::query()
            ->where(function ($query) use ($location): void {
                $query->whereNull('location_id')->orWhere('location_id', $location->id);
            })
            ->get();

        foreach ($rows as $row) {
            $from = $row->starts_on->toDateString();
            $until = $row->ends_on->toDateString();

            foreach ($dates as $date) {
                if ($date >= $from && $date <= $until) {
                    return true;
                }
            }
        }

        return false;
    }

    private function coversClosure(Location $location, CarbonImmutable $startUtc, CarbonImmutable $endUtc, ?string $resourceType, ?string $resourceId): bool
    {
        $categoryId = $this->closures->categoryId($resourceType, $resourceId);

        /** @var Collection<int, LocationClosure> $rows */
        $rows = LocationClosure::query()->where('location_id', $location->id)->get();

        foreach ($rows as $row) {
            if (! $this->closures->applies($row, $resourceType, $resourceId, $categoryId)) {
                continue;
            }

            if ($this->closures->covers($row, DisplayZone::forLocation($location), $startUtc, $endUtc)) {
                return true;
            }
        }

        return false;
    }

    private function instantOpen(ScheduleOwnerType $ownerType, string $ownerId, CarbonImmutable $instant, bool $emptyMeansOpen): bool
    {
        $rows = AvailabilitySchedule::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->get();

        if ($rows->isEmpty()) {
            return $emptyMeansOpen;
        }

        $time = $instant->format('H:i:s');
        $weekday = $instant->dayOfWeek;
        $previous = $instant->subDay()->dayOfWeek;

        foreach ($rows as $row) {
            $opens = $this->clock($row->opens_local);
            $closes = $this->clock($row->closes_local);

            if ($closes === $opens) {
                continue;
            }

            if ($closes > $opens && $row->weekday === $weekday && $time >= $opens && $time < $closes) {
                return true;
            }

            if ($closes < $opens && $row->weekday === $weekday && $time >= $opens) {
                return true;
            }

            if ($closes < $opens && $row->weekday === $previous && $time < $closes) {
                return true;
            }
        }

        return false;
    }

    private function clock(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        $text = (string) $value;

        return strlen($text) === 5 ? $text.':00' : $text;
    }
}
