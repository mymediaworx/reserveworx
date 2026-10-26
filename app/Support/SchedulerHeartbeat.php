<?php

namespace App\Support;

use App\Console\Commands\Smoke;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The schedule writes this every minute. A missing or old value means the
 * host cron that runs `schedule:run` is not running, so undo commits,
 * the queue, reminders, and overdue flags are not happening either.
 */
final class SchedulerHeartbeat
{
    public const STALE_AFTER_MINUTES = 10;

    public function beat(): void
    {
        Cache::forever(Smoke::SCHEDULE_LAST_RUN_KEY, CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'));
    }

    public function lastRun(): ?CarbonImmutable
    {
        try {
            $value = Cache::get(Smoke::SCHEDULE_LAST_RUN_KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return 'ok'|'stale'|'missing'
     */
    public function status(): string
    {
        $last = $this->lastRun();

        if (! $last instanceof CarbonImmutable) {
            return 'missing';
        }

        return $last->lessThan(CarbonImmutable::now('UTC')->subMinutes(self::STALE_AFTER_MINUTES)) ? 'stale' : 'ok';
    }
}
