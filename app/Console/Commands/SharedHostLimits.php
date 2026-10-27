<?php

namespace App\Console\Commands;

use App\Services\Platform\SharedHostLimitReport;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

class SharedHostLimits extends Command
{
    protected $signature = 'reserveworx:limits';

    protected $description = 'Report whether scheduled work stays inside the shared-host window. This command does not print secrets.';

    public function handle(Schedule $schedule, SharedHostLimitReport $report): int
    {
        $built = $report->build($schedule);

        foreach ($built['jobTimeouts'] as $job) {
            $this->line('job_timeout='.$job['class'].' '.$job['timeout']);
        }

        $this->line('window_seconds='.$built['window']);
        $this->line('configured_queue_work_max_time='.$built['configured']);
        $this->line('queue_worker='.$built['worker']);
        $this->line('jobs_within_window='.($built['jobsOk'] ? 'yes' : 'no'));
        $this->line('schedule_overlap='.($built['overlapOk'] ? 'yes' : 'no'));
        $this->line('within_limit='.($built['within'] ? 'yes' : 'no'));

        return $built['within'] ? self::SUCCESS : self::FAILURE;
    }
}
