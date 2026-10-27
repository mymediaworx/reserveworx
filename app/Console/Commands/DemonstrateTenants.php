<?php

namespace App\Console\Commands;

use App\Services\Platform\TenantDemonstration;
use Illuminate\Console\Command;

class DemonstrateTenants extends Command
{
    protected $signature = 'reserveworx:demo';

    protected $description = 'Show whether the two demo workspaces keep their own records. This command does not print customer details.';

    public function handle(TenantDemonstration $demonstration): int
    {
        $built = $demonstration->build();

        foreach ($built['shops'] as $shop) {
            $this->line($shop['line']);
        }

        $this->line('shown='.($built['shown'] ? 'yes' : 'no'));

        return $built['shown'] ? self::SUCCESS : self::FAILURE;
    }
}
