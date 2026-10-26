<?php

namespace App\Services\Booking;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Active workspace members who can be named as a booking's guide.
 */
final class GuideRoster
{
    /**
     * @return Collection<int, User>
     */
    public function active(): Collection
    {
        $ids = Membership::query()
            ->where('status', MembershipStatus::Active)
            ->whereHas('role', fn (Builder $query): Builder => $query->whereIn('slug', ['owner', 'manager', 'staff']))
            ->pluck('user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->orderBy('id')->get(['id', 'name']);
    }

    public function accepts(string $userId): bool
    {
        return Membership::query()
            ->where('user_id', $userId)
            ->where('status', MembershipStatus::Active)
            ->whereHas('role', fn (Builder $query): Builder => $query->whereIn('slug', ['owner', 'manager', 'staff']))
            ->exists();
    }
}
