<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\Incident;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class IncidentPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'bookings.view');
    }

    public function view(User $user, Incident $incident): bool
    {
        $booking = Booking::query()->find($incident->booking_id);

        return $booking instanceof Booking && $user->can('view', $booking);
    }

    public function create(User $user, Booking $booking): bool
    {
        return $user->can('checkIn', $booking);
    }

    public function resolve(User $user, Incident $incident): bool
    {
        $booking = Booking::query()->find($incident->booking_id);

        return $booking instanceof Booking && $user->can('checkIn', $booking);
    }
}
