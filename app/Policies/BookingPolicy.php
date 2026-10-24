<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class BookingPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'bookings.view');
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->sameTenant($booking->tenant_id) && $this->tenantAllows($user, 'bookings.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'bookings.manage');
    }

    public function update(User $user, Booking $booking): bool
    {
        return $this->sameTenant($booking->tenant_id) && $this->tenantAllows($user, 'bookings.manage');
    }

    public function checkInAny(User $user): bool
    {
        return $this->tenantAllows($user, 'bookings.check_in');
    }

    public function checkIn(User $user, Booking $booking): bool
    {
        return $this->sameTenant($booking->tenant_id) && $this->tenantAllows($user, 'bookings.check_in');
    }
}
