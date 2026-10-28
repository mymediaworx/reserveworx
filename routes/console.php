<?php

use App\Support\SchedulerHeartbeat;
use App\Support\SharedHostBudget;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(SchedulerHeartbeat::class)->beat())
    ->name('scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

$maxTime = SharedHostBudget::seconds();

Schedule::command(sprintf(
    'queue:work database --stop-when-empty --tries=3 --max-time=%d',
    $maxTime,
))->everyMinute()->withoutOverlapping();

Schedule::command('holds:purge-expired')->everyMinute()->withoutOverlapping();
Schedule::command('undo:commit')->everyMinute()->withoutOverlapping();
Schedule::command('integrations:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('rentals:flag-overdue')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:dispatch-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('feedback:dispatch')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reports:build-summaries')->hourly()->withoutOverlapping();

// Cheap file housekeeping so a small shared host never creeps toward its inode cap.
Schedule::command('host:prune-files')->dailyAt('03:17')->withoutOverlapping();
