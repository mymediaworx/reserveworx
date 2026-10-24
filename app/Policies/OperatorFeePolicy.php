<?php

namespace App\Policies;

use App\Models\OperatorFee;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class OperatorFeePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function view(User $user, OperatorFee $operatorFee): bool
    {
        return $this->sameTenant($operatorFee->tenant_id) && $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }

    public function update(User $user, OperatorFee $operatorFee): bool
    {
        return $this->sameTenant($operatorFee->tenant_id) && $this->tenantAllows($user, 'inventory.manage');
    }

    public function delete(User $user, OperatorFee $operatorFee): bool
    {
        return $this->sameTenant($operatorFee->tenant_id) && $this->tenantAllows($user, 'inventory.manage');
    }
}
