<?php

namespace App\Policies;

use App\Models\TenantBranding;
use App\Models\User;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;

class TenantBrandingPolicy
{
    public function __construct(
        private readonly Access $access,
        private readonly TenantManager $tenants,
    ) {}

    public function view(User $user, TenantBranding $branding): bool
    {
        return $this->matches($branding->tenant_id)
            && $this->access->allows($user, $branding->tenant_id, 'settings.view');
    }

    public function update(User $user, TenantBranding $branding): bool
    {
        return $this->matches($branding->tenant_id)
            && $this->access->allows($user, $branding->tenant_id, 'settings.update');
    }

    private function matches(string $tenantId): bool
    {
        return $this->tenants->id() !== null && $this->tenants->id() === $tenantId;
    }
}
