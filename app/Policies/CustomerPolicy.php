<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class CustomerPolicy
{
    use ChecksTenantPermission;

    public function export(User $user, Customer $customer): bool
    {
        return $this->sameTenant($customer->tenant_id) && $this->tenantAllows($user, 'customers.privacy');
    }

    public function erase(User $user, Customer $customer): bool
    {
        return $this->export($user, $customer);
    }
}
