<?php

use Illuminate\Support\Facades\Schedule;

$maxTime = max(10, (int) config('reserveworx.queue_work_max_time'));

Schedule::command(sprintf(
    'queue:work database --stop-when-empty --tries=3 --max-time=%d',
    $maxTime,
))->everyMinute()->withoutOverlapping();

Schedule::command('holds:purge-expired')->everyMinute()->withoutOverlapping();
Schedule::command('integrations:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('rentals:flag-overdue')->everyMinute()->withoutOverlapping();
Schedule::command('reports:build-summaries')->hourly()->withoutOverlapping();
