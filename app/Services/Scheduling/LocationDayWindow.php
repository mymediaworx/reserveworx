<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

/**
 * The local calendar day used by the staff home schedule.
 * Each location keeps its own clock, including a 23-hour or 25-hour daylight-saving day.
 */
final class LocationDayWindow
{
    /**
     * Start and end of the local calendar day that contains now.
     * The end is the last moment of that local date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function today(string $zone, CarbonImmutable $nowUtc): array
    {
        $local = $nowUtc->timezone($zone);

        return [$local->startOfDay()->utc(), $local->endOfDay()->utc()];
    }

    /**
     * The instant after today, and the last moment of the seventh local day from now.
     * A visit that is still today in this zone is not in this window.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function upcoming(string $zone, CarbonImmutable $nowUtc): array
    {
        $local = $nowUtc->timezone($zone);

        return [$local->endOfDay()->utc(), $local->addDays(7)->endOfDay()->utc()];
    }
}
