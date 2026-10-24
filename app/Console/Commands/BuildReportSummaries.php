<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Location;
use App\Models\Tenant;
use App\Services\Reports\DailySummary;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

class BuildReportSummaries extends Command
{
    protected $signature = 'reports:build-summaries {--date= : One location-local date (YYYY-MM-DD). Omit to rebuild yesterday and today.}';

    protected $description = 'Store utilization, conversion, and revenue per resource-hour for each location.';

    public function handle(TenantManager $tenants, DailySummary $summaries): int
    {
        $date = $this->option('date');

        if (is_string($date) && $date !== '' && ! $this->validDate($date)) {
            $this->error('Date must be YYYY-MM-DD.');

            return self::FAILURE;
        }

        $fixed = is_string($date) && $date !== '' ? $date : null;
        $written = 0;

        Tenant::query()
            ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Onboarding->value])
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($tenants, $summaries, $fixed, &$written): void {
                $previous = $tenants->get();
                $tenants->set($tenant);

                try {
                    Location::query()->orderBy('id')->each(function (Location $location) use ($summaries, $fixed, &$written): void {
                        foreach ($this->datesFor($location, $fixed) as $day) {
                            $summaries->write($location, $day);
                            $written++;
                        }
                    });
                } finally {
                    if ($previous instanceof Tenant) {
                        $tenants->set($previous);
                    } else {
                        $tenants->forget();
                    }
                }
            });

        $this->line('summaries_written='.$written);

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
