<?php

namespace Tests\Support;

use App\Jobs\TenantAwareJob;
use App\Models\Tenant;
use App\Models\TenantSetting;

class SetsHoldMinutesJob extends TenantAwareJob
{
    public function __construct(string $tenantId, public int $minutes)
    {
        parent::__construct($tenantId);
    }

    protected function handleForTenant(Tenant $tenant): void
    {
        $setting = TenantSetting::query()->firstOrFail();
        $setting->hold_minutes = $this->minutes;
        $setting->save();
    }
}
