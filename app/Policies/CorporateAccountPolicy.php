<?php

namespace App\Policies;

use App\Models\CorporateAccount;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class CorporateAccountPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, CorporateAccount $corporateAccount): bool
    {
        return $this->sameTenant($corporateAccount->tenant_id) && $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }

    public function update(User $user, CorporateAccount $corporateAccount): bool
    {
        return $this->sameTenant($corporateAccount->tenant_id) && $this->tenantAllows($user, 'inventory.manage');
    }

    public function delete(User $user, CorporateAccount $corporateAccount): bool
    {
        return $this->update($user, $corporateAccount);
    }
}
