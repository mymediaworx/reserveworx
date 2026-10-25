<?php

namespace App\Policies;

use App\Models\LocationTaxRule;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;

class LocationTaxRulePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, LocationTaxRule $locationTaxRule): bool
    {
        return $this->sameTenant($locationTaxRule->tenant_id) && $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, LocationTaxRule $locationTaxRule): bool
    {
        return $this->sameTenant($locationTaxRule->tenant_id) && $this->manages($user);
    }

    public function delete(User $user, LocationTaxRule $locationTaxRule): bool
    {
        return $this->sameTenant($locationTaxRule->tenant_id) && $this->manages($user);
    }

    private function manages(User $user): bool
    {
        $tenantId = app(TenantManager::class)->id();

        return is_string($tenantId) && app(Access::class)->hasRole($user, $tenantId, 'owner', 'manager');
    }
}
