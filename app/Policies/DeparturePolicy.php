<?php

namespace App\Policies;

use App\Models\Departure;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class DeparturePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'bookings.view');
    }

    public function view(User $user, Departure $departure): bool
    {
        return $this->sameTenant($departure->tenant_id) && $this->tenantAllows($user, 'bookings.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'bookings.manage');
    }

    public function update(User $user, Departure $departure): bool
    {
        return $this->sameTenant($departure->tenant_id) && $this->tenantAllows($user, 'bookings.manage');
    }
}
