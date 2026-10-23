<?php

namespace App\Policies;

use App\Models\AvailabilitySchedule;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class AvailabilitySchedulePolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'inventory.manage');
    }

    public function delete(User $user, AvailabilitySchedule $schedule): bool
    {
        return $this->sameTenant($schedule->tenant_id) && $this->tenantAllows($user, 'inventory.manage');
    }
}
