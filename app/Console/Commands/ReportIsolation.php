<?php

namespace App\Console\Commands;

use App\Services\Platform\CrossTenantIsolationCheck;
use Illuminate\Console\Command;

class ReportIsolation extends Command
{
    protected $signature = 'reserveworx:isolation';

    protected $description = 'Report whether one workspace can read another workspace\'s customer. This command does not print the sample or call Stripe.';

    public function handle(CrossTenantIsolationCheck $check): int
    {
        $built = $check->build();

        foreach ($built['checks'] as $row) {
            $this->line($row['key'].'='.$row['line']);
        }

        $this->line('isolated='.($built['isolated'] ? 'yes' : 'no'));

        return $built['isolated'] ? self::SUCCESS : self::FAILURE;
    }
}
