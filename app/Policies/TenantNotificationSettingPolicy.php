<?php

namespace App\Policies;

use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;

class TenantNotificationSettingPolicy
{
    public function __construct(
        private readonly Access $access,
        private readonly TenantManager $tenants,
    ) {}

    public function view(User $user, TenantNotificationSetting $setting): bool
    {
        return $this->matches($setting->tenant_id)
            && $this->access->allows($user, $setting->tenant_id, 'settings.view');
    }

    public function update(User $user, TenantNotificationSetting $setting): bool
    {
        return $this->matches($setting->tenant_id)
            && $this->access->allows($user, $setting->tenant_id, 'settings.update');
    }

    private function matches(string $tenantId): bool
    {
        return $this->tenants->id() !== null && $this->tenants->id() === $tenantId;
    }
}
