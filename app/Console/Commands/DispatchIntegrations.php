<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Integrations\Outbox;
use App\Services\Notifications\HookDelivery;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

class DispatchIntegrations extends Command
{
    protected $signature = 'integrations:dispatch';

    protected $description = 'Record undelivered notification hooks and advance the GoHighLevel outbox without calling either service.';

    public function handle(TenantManager $tenants, HookDelivery $delivery, Outbox $outbox): int
    {
        Tenant::query()
            ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Onboarding->value])
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($tenants, $delivery, $outbox): void {
                $previous = $tenants->get();
                $tenants->set($tenant);

                try {
                    $delivery->skipUnconfigured();
                    $outbox->attemptDue();
                } finally {
                    if ($previous instanceof Tenant) {
                        $tenants->set($previous);
                    } else {
                        $tenants->forget();
                    }
                }
            });

        return self::SUCCESS;
    }
}
