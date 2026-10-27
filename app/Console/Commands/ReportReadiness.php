<?php

namespace App\Console\Commands;

use App\Services\Platform\ReadinessReport;
use Illuminate\Console\Command;

class ReportReadiness extends Command
{
    protected $signature = 'reserveworx:readiness';

    protected $description = 'Report whether this server passes the local production-readiness checks. This command does not print secrets.';

    public function handle(ReadinessReport $report): int
    {
        $built = $report->build();

        foreach ($built['checks'] as $check) {
            $this->line($check['key'].'='.$check['line']);
        }

        $this->line('ready='.($built['ready'] ? 'yes' : 'no'));

        return $built['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
