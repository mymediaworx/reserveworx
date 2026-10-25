<?php

namespace App\Policies;

use App\Models\PromoCode;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class PromoCodePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, PromoCode $promoCode): bool
    {
        return $this->sameTenant($promoCode->tenant_id) && $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }

    public function update(User $user, PromoCode $promoCode): bool
    {
        return $this->sameTenant($promoCode->tenant_id) && $this->tenantAllows($user, 'inventory.manage');
    }

    public function delete(User $user, PromoCode $promoCode): bool
    {
        return $this->update($user, $promoCode);
    }
}
