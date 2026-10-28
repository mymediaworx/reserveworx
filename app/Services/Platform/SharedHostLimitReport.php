<?php

namespace App\Services\Platform;

use App\Support\SharedHostBudget;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;

/**
 * Whether scheduled work stays inside the shared-host window.
 * The report does not print secrets and does not write a row.
 */
final class SharedHostLimitReport
{
    /**
     * @return array{
     *     within: bool,
     *     window: int,
     *     configured: int,
     *     configuredOk: bool,
     *     worker: string,
     *     jobsOk: bool,
     *     overlapOk: bool,
     *     jobTimeouts: list<array{class: string, timeout: string}>,
     *     checks: list<array{key: string, label: string, state: string, detail: string}>
     * }
     */
    public function build(Schedule $schedule): array
    {
        $window = SharedHostBudget::seconds();
        $configured = (int) config('reserveworx.queue_work_max_time');
        $configuredOk = $configured >= SharedHostBudget::FLOOR_SECONDS && $configured <= SharedHostBudget::CEILING_SECONDS;
        $jobTimeouts = $this->jobTimeouts($window);
        $jobsOk = $jobTimeouts === [];
        $overlapOk = $this->scheduleFits($schedule);
        $workerOk = $this->workerFits($schedule);
        $within = $configuredOk && $jobsOk && $overlapOk && $workerOk;

        $jobsDetail = $jobsOk
            ? 'Every queued job timeout is from 1 second through that window.'
            : 'A queued job timeout is missing or outside that window.';

        foreach ($jobTimeouts as $job) {
            $shown = $job['timeout'] === 'missing' ? 'missing' : $job['timeout'].' seconds';
            $jobsDetail .= ' '.$job['class'].' is '.$shown.'.';
        }

        return [
            'within' => $within,
            'window' => $window,
            'configured' => $configured,
            'configuredOk' => $configuredOk,
            'worker' => $workerOk ? 'bounded' : 'unbounded',
            'jobsOk' => $jobsOk,
            'overlapOk' => $overlapOk,
            'jobTimeouts' => $jobTimeouts,
            'checks' => [
                [
                    'key' => 'window',
                    'label' => 'Window',
                    'state' => 'pass',
                    'detail' => 'The shared-host window is '.$window.' seconds.',
                ],
                [
                    'key' => 'configured',
                    'label' => 'Configured time',
                    'state' => $configuredOk ? 'pass' : 'fail',
                    'detail' => 'The configured queue work max time is '.$configured.', which is '.($configuredOk ? 'inside' : 'outside').' 10 to 20 seconds.',
                ],
                [
                    'key' => 'worker',
                    'label' => 'Queue worker',
                    'state' => $workerOk ? 'pass' : 'fail',
                    'detail' => $workerOk
                        ? 'The queue worker stops when empty and uses a max time inside 10 to 20 seconds.'
                        : 'The queue worker is not bounded to that window.',
                ],
                [
                    'key' => 'jobs',
                    'label' => 'Job timeouts',
                    'state' => $jobsOk ? 'pass' : 'fail',
                    'detail' => $jobsDetail,
                ],
                [
                    'key' => 'overlap',
                    'label' => 'Schedule',
                    'state' => $overlapOk ? 'pass' : 'fail',
                    'detail' => $overlapOk
                        ? 'Every scheduled task refuses to overlap.'
                        : 'A scheduled task can overlap, or nothing is scheduled.',
                ],
            ],
        ];
    }

    /**
     * @return list<array{class: string, timeout: string}>
     */
    private function jobTimeouts(int $window): array
    {
        $timeouts = [];

        foreach ($this->queuedClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $timeout = $reflection->getDefaultProperties()['timeout'] ?? null;

            if (! is_int($timeout) || $timeout < 1 || $timeout > $window) {
                $timeouts[] = [
                    'class' => $class,
                    'timeout' => is_int($timeout) ? (string) $timeout : 'missing',
                ];
            }
        }

        return $timeouts;
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
        $events = $schedule->events();

        foreach ($events as $event) {
            if (! $event->withoutOverlapping) {
                return false;
            }
        }

        return $events !== [];
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
