<?php

namespace App\Services\Team;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\Access;
use Illuminate\Validation\ValidationException;

/**
 * System roles are ordered. A member can assign or edit only roles at or below
 * their own, and a workspace must keep one active owner.
 */
final class RoleGuard
{
    public const LAST_OWNER = 'The last owner of a workspace cannot be removed.';

    public const ABOVE_ROLE = 'You cannot assign a role above your own.';

    public const ABOVE_MEMBER = 'You cannot change a member above your own role.';

    /**
     * Higher numbers outrank lower ones. A slug that is not a system role cannot be assigned.
     *
     * @var array<string, int>
     */
    private const RANKS = [
        'staff' => 1,
        'manager' => 2,
        'owner' => 3,
    ];

    public function __construct(private readonly Access $access) {}

    public function covers(?string $actorSlug, string $targetSlug): bool
    {
        $actor = self::RANKS[$actorSlug ?? ''] ?? 0;
        $target = self::RANKS[$targetSlug] ?? PHP_INT_MAX;

        return $actor >= $target;
    }

    /**
     * @return list<string>
     */
    public function assignableSlugs(User $actor, string $tenantId): array
    {
        $actorSlug = $this->actorSlug($actor, $tenantId);
        $allowed = [];

        foreach (['owner', 'manager', 'staff'] as $slug) {
            if ($this->covers($actorSlug, $slug)) {
                $allowed[] = $slug;
            }
        }

        return $allowed;
    }

    public function assertCanAssign(User $actor, string $tenantId, string $targetSlug, string $field = 'role'): void
    {
        if ($this->covers($this->actorSlug($actor, $tenantId), $targetSlug)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => self::ABOVE_ROLE,
        ]);
    }

    public function assertCanTouch(User $actor, Membership $membership, string $field = 'team'): void
    {
        $membership->loadMissing('role');

        if ($this->covers($this->actorSlug($actor, $membership->tenant_id), $membership->role->slug)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => self::ABOVE_MEMBER,
        ]);
    }

    public function assertKeepsAnOwner(
        Membership $membership,
        string $nextRoleSlug,
        MembershipStatus $nextStatus,
        string $field = 'team',
    ): void {
        if (! $this->isLastActiveOwner($membership)) {
            return;
        }

        if ($nextStatus === MembershipStatus::Active && $nextRoleSlug === 'owner') {
            return;
        }

        throw ValidationException::withMessages([
            $field => self::LAST_OWNER,
        ]);
    }

    public function isLastActiveOwner(Membership $membership): bool
    {
        $membership->loadMissing('role');

        if ($membership->role?->slug !== 'owner' || $membership->status !== MembershipStatus::Active) {
            return false;
        }

        return Membership::query()
            ->where('status', MembershipStatus::Active)
            ->whereHas('role', fn ($query) => $query->where('slug', 'owner'))
            ->count() <= 1;
    }

    private function actorSlug(User $actor, string $tenantId): ?string
    {
        return $this->access->membership($actor, $tenantId)?->role?->slug;
    }
}
