<?php

namespace App\Policies;

use App\Models\BookingListView;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermission;

class BookingListViewPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'bookings.view');
    }

    public function view(User $user, BookingListView $bookingListView): bool
    {
        return $this->owns($user, $bookingListView);
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'bookings.view');
    }

    public function update(User $user, BookingListView $bookingListView): bool
    {
        return $this->owns($user, $bookingListView);
    }

    public function delete(User $user, BookingListView $bookingListView): bool
    {
        return $this->owns($user, $bookingListView);
    }

    private function owns(User $user, BookingListView $bookingListView): bool
    {
        return $this->sameTenant($bookingListView->tenant_id)
            && $bookingListView->user_id === $user->id
            && $this->tenantAllows($user, 'bookings.view');
    }
}
