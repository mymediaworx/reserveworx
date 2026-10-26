<?php

namespace App\Console\Commands;

use App\Services\Integrations\Outbox;
use App\Services\Notifications\HookDelivery;
use App\Support\TenantSweep;
use Illuminate\Console\Command;

class DispatchIntegrations extends Command
{
    protected $signature = 'integrations:dispatch';

    protected $description = 'Email queued customer notifications and advance the GoHighLevel outbox.';

    public function handle(TenantSweep $sweep, HookDelivery $delivery, Outbox $outbox): int
    {
        $result = $sweep->each('integrations', function () use ($delivery, $outbox): void {
            $delivery->deliverQueued();
            $outbox->attemptDue();
        });

        $this->line('sweep_visited='.$result->visited);
        $this->line('sweep_deferred='.($result->deferred ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
