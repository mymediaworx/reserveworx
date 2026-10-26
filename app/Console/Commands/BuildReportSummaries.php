<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Reports\DailySummary;
use App\Support\TenantSweep;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

class BuildReportSummaries extends Command
{
    protected $signature = 'reports:build-summaries {--date= : One location-local date (YYYY-MM-DD). Omit to rebuild yesterday and today.}';

    protected $description = 'Store utilization, conversion, and revenue per resource-hour for each location.';

    public function handle(TenantSweep $sweep, DailySummary $summaries): int
    {
        $date = $this->option('date');

        if (is_string($date) && $date !== '' && ! $this->validDate($date)) {
            $this->error('Date must be YYYY-MM-DD.');

            return self::FAILURE;
        }

        $fixed = is_string($date) && $date !== '' ? $date : null;
        $written = 0;

        $result = $sweep->each('report-summaries', function () use ($summaries, $fixed, &$written): void {
            Location::query()->orderBy('id')->each(function (Location $location) use ($summaries, $fixed, &$written): void {
                foreach ($this->datesFor($location, $fixed) as $day) {
                    $summaries->write($location, $day);
                    $written++;
                }
            });
        });

        $this->line('summaries_written='.$written);
        $this->line('sweep_visited='.$result->visited);
        $this->line('sweep_deferred='.($result->deferred ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function datesFor(Location $location, ?string $fixed): array
    {
        if ($fixed !== null) {
            return [$fixed];
        }

        $zone = in_array($location->timezone, DateTimeZone::listIdentifiers(), true) ? $location->timezone : 'UTC';
        $today = CarbonImmutable::now($zone);

        return [$today->subDay()->toDateString(), $today->toDateString()];
    }

    private function validDate(string $date): bool
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $date;
    }
}
