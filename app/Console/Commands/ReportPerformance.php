<?php

namespace App\Console\Commands;

use App\Services\Platform\PerformanceCheck;
use Illuminate\Console\Command;

class ReportPerformance extends Command
{
    protected $signature = 'reserveworx:performance';

    protected $description = 'Measure product, signup, and one booking page on this server. This command does not call the public internet and does not print secrets.';

    public function handle(PerformanceCheck $check): int
    {
        $built = $check->build();

        foreach ($built['checks'] as $row) {
            $this->line($row['key'].'='.$row['line']);
        }

        foreach ($built['facts'] as $fact) {
            $this->line($fact['key'].'='.$fact['value']);
        }

        $this->line('within_budget='.($built['within'] ? 'yes' : 'no'));

        return $built['within'] ? self::SUCCESS : self::FAILURE;
    }
}
