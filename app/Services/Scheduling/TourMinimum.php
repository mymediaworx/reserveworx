<?php

namespace App\Services\Scheduling;

use App\Enums\DepartureStatus;
use App\Enums\ServiceKind;
use App\Models\Departure;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * A scheduled tour that has some guests, but not enough to meet its minimum,
 * is flagged while it is still in the future. The flag is a decision for the
 * operator. Nothing here cancels the departure.
 */
final class TourMinimum
{
    /**
     * How far ahead the staff home page lists tours that are still short.
     */
    public const HOME_HORIZON_DAYS = 120;

    /**
     * @param  Collection<int, Departure>  $departures
     * @return array<string, array{participants: int, minimum: int|null, belowMinimum: bool}>
     */
    public function summarize(Collection $departures, CarbonImmutable $now): array
    {
        $models = new EloquentCollection($departures->all());
        $models->loadMissing('service');
        $counts = Departure::headcountsFor($models);
        $rows = [];

        foreach ($departures as $departure) {
            $participants = $counts[$departure->id] ?? 0;
            $minimum = $departure->service?->min_participants;
            $rows[$departure->id] = [
                'participants' => $participants,
                'minimum' => is_int($minimum) ? $minimum : null,
                'belowMinimum' => $this->flagged($departure, $participants, $now),
            ];
        }

        return $rows;
    }

    public function flagged(Departure $departure, int $participants, CarbonImmutable $now): bool
    {
        if ($departure->status !== DepartureStatus::Scheduled) {
            return false;
        }

        $departure->loadMissing('service');
        $service = $departure->service;

        if ($service === null || $service->kind !== ServiceKind::Tour) {
            return false;
        }

        if (CarbonImmutable::instance($departure->starts_at)->lessThanOrEqualTo($now)) {
            return false;
        }

        $minimum = $service->min_participants;

        return is_int($minimum) && $participants > 0 && $participants < $minimum;
    }
}
