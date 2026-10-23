<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WaitlistEntry;
use App\Policies\Concerns\ChecksTenantPermission;

class WaitlistEntryPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'bookings.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'bookings.manage');
    }

    public function update(User $user, WaitlistEntry $entry): bool
    {
        return $this->sameTenant($entry->tenant_id) && $this->tenantAllows($user, 'bookings.manage');
    }
}
