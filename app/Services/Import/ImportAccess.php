<?php

namespace App\Services\Import;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\TenantManager;

/**
 * CSV import is limited to the owner and manager roles. There is no separate permission.
 */
final class ImportAccess
{
    public function allows(User $user): bool
    {
        if (app(TenantManager::class)->id() === null) {
            return false;
        }

        return Membership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->whereHas('role', fn ($query) => $query->whereIn('slug', ['owner', 'manager']))
            ->exists();
    }
}
