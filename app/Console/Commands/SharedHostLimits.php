<?php

namespace App\Console\Commands;

use App\Support\SharedHostBudget;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;

class SharedHostLimits extends Command
{
    protected $signature = 'reserveworx:limits';

    protected $description = 'Report whether scheduled work stays inside the shared-host window. This command does not print secrets.';

    public function handle(Schedule $schedule): int
    {
        $window = SharedHostBudget::seconds();
        $configured = (int) config('reserveworx.queue_work_max_time');
        $configuredOk = $configured >= SharedHostBudget::FLOOR_SECONDS && $configured <= SharedHostBudget::CEILING_SECONDS;
        $jobsOk = $this->jobsFit($window);
        $overlapOk = $this->scheduleFits($schedule);
        $workerOk = $this->workerFits($schedule);
        $within = $configuredOk && $jobsOk && $overlapOk && $workerOk;

        $this->line('window_seconds='.$window);
        $this->line('configured_queue_work_max_time='.$configured);
        $this->line('queue_worker='.($workerOk ? 'bounded' : 'unbounded'));
        $this->line('jobs_within_window='.($jobsOk ? 'yes' : 'no'));
        $this->line('schedule_overlap='.($overlapOk ? 'yes' : 'no'));
        $this->line('within_limit='.($within ? 'yes' : 'no'));

        return $within ? self::SUCCESS : self::FAILURE;
    }

    private function jobsFit(int $window): bool
    {
        $fit = true;

        foreach ($this->queuedClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $timeout = $reflection->getDefaultProperties()['timeout'] ?? null;

            if (! is_int($timeout) || $timeout < 1 || $timeout > $window) {
                $fit = false;
                $this->line('job_timeout='.$class.' '.(is_int($timeout) ? (string) $timeout : 'missing'));
            }
        }

        return $fit;
    }

    /**
     * @return list<string>
     */
    private function queuedClasses(): array
    {
        /** @var list<string> $classes */
        $classes = [];

        foreach ([app_path('Jobs'), app_path('Listeners')] as $directory) {
            foreach (glob($directory.'/*.php') ?: [] as $file) {
                $suffix = substr($file, strlen(app_path()) + 1, -4);
                $class = 'App\\'.str_replace('/', '\\', $suffix);

                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return $classes;
    }

    private function scheduleFits(Schedule $schedule): bool
    {
        foreach ($schedule->events() as $event) {
            if (! $event->withoutOverlapping) {
                return false;
            }
        }

        return $schedule->events() !== [];
    }

    private function workerFits(Schedule $schedule): bool
    {
        foreach ($schedule->events() as $event) {
            $command = $event->command;

            if (! is_string($command) || ! str_contains($command, 'queue:work')) {
                continue;
            }

            if (! str_contains($command, '--stop-when-empty')) {
                return false;
            }

            if (! preg_match('/--max-time=(\d+)/', $command, $match)) {
                return false;
            }

            $max = (int) $match[1];

            return $max >= SharedHostBudget::FLOOR_SECONDS && $max <= SharedHostBudget::CEILING_SECONDS;
        }

        return false;
    }
}
