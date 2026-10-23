<?php

namespace App\Jobs;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $tenantId) {}

    public function handle(TenantManager $tenants): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            throw new TenantContextException('Tenant-aware job could not find its tenant.');
        }

        if ($tenant->status === TenantStatus::Terminated) {
            throw new TenantContextException('Tenant-aware job refused a terminated tenant.');
        }

        $tenants->set($tenant);

        try {
            $this->handleForTenant($tenant);
        } finally {
            $tenants->forget();
        }
    }

    abstract protected function handleForTenant(Tenant $tenant): void;
}
