<?php

namespace App\Console\Commands;

use App\Services\Platform\SecurityCheck;
use Illuminate\Console\Command;

class ReportSecurity extends Command
{
    protected $signature = 'reserveworx:security';

    protected $description = 'Report whether stored credentials are encrypted and card data is absent. This command does not print secrets or call Stripe.';

    public function handle(SecurityCheck $check): int
    {
        $built = $check->build();

        foreach ($built['checks'] as $row) {
            $this->line($row['key'].'='.$row['line']);
        }

        $this->line('secure='.($built['secure'] ? 'yes' : 'no'));

        return $built['secure'] ? self::SUCCESS : self::FAILURE;
    }
}
