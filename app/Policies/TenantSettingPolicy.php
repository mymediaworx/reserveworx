<?php

namespace App\Policies;

use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;

class TenantSettingPolicy
{
    public function __construct(
        private readonly Access $access,
        private readonly TenantManager $tenants,
    ) {}

    public function view(User $user, TenantSetting $setting): bool
    {
        return $this->matchesContext($setting)
            && $this->access->allows($user, $setting->tenant_id, 'settings.view');
    }

    public function update(User $user, TenantSetting $setting): bool
    {
        return $this->matchesContext($setting)
            && $this->access->allows($user, $setting->tenant_id, 'settings.update');
    }

    private function matchesContext(TenantSetting $setting): bool
    {
        return $this->tenants->id() !== null && $this->tenants->id() === $setting->tenant_id;
    }
}
