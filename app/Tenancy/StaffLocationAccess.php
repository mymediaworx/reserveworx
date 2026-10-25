<?php

namespace App\Tenancy;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Location limits for one active membership. No rows and the flag off means
 * every location, which is the membership default.
 */
class StaffLocationAccess
{
    private int $suspended = 0;

    /**
     * @var array<string, list<string>|null>
     */
    private array $cache = [];

    public function suspend(callable $callback): mixed
    {
        $this->suspended++;

        try {
            return $callback();
        } finally {
            $this->suspended--;
        }
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * Null means the current user is not limited. An empty list means they
     * are limited to no locations.
     *
     * @return list<string>|null
     */
    public function restrictedLocationIds(): ?array
    {
        if ($this->suspended > 0) {
            return null;
        }

        $user = $this->currentUser();
        $tenantId = app(TenantManager::class)->id();

        if (! $user instanceof User || $tenantId === null) {
            return null;
        }

        return $this->assignedIds($user, $tenantId);
    }

    public function isRestricted(?User $user = null): bool
    {
        $actor = $user ?? $this->currentUser();
        $tenantId = app(TenantManager::class)->id();

        if (! $actor instanceof User || $tenantId === null) {
            return false;
        }

        return $this->assignedIds($actor, $tenantId) !== null;
    }

    public function allows(?string $locationId, ?User $user = null): bool
    {
        $actor = $user ?? $this->currentUser();
        $tenantId = app(TenantManager::class)->id();

        if (! $actor instanceof User || $tenantId === null) {
            return $actor === null;
        }

        $ids = $this->assignedIds($actor, $tenantId);

        if ($ids === null) {
            return true;
        }

        return is_string($locationId) && $locationId !== '' && in_array($locationId, $ids, true);
    }

    public function locationExistsRule(?string $tenantId): Exists
    {
        return Rule::exists('locations', 'id')->where(function (QueryBuilder $query) use ($tenantId): void {
            $query->where('tenant_id', $tenantId);
            $this->constrain($query);
        });
    }

    public function constrain(QueryBuilder $query, string $column = 'id'): void
    {
        $ids = $this->restrictedLocationIds();

        if ($ids === null) {
            return;
        }

        $query->whereIn($column, $ids === [] ? [''] : $ids);
    }

    /**
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>  $query
     */
    public function constrainToVisibleBookings(EloquentBuilder $query, string $relation = 'booking'): void
    {
        if ($this->restrictedLocationIds() === null) {
            return;
        }

        $query->whereHas($relation);
    }

    /**
     * Portfolio reads several workspaces at once. Drop the current-workspace
     * scope, then keep each workspace to that membership's locations.
     *
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>  $query
     * @param  list<string>  $tenantIds
     */
    public function limitAcrossTenants(EloquentBuilder $query, array $tenantIds): void
    {
        $query->withoutGlobalScope(StaffLocationScope::class);

        $user = $this->currentUser();

        if (! $user instanceof User || $tenantIds === []) {
            return;
        }

        /** @var array<string, list<string>|null> $limits */
        $limits = [];
        $restricted = false;

        foreach ($tenantIds as $tenantId) {
            $ids = $this->assignedIds($user, $tenantId);
            $limits[$tenantId] = $ids;

            if ($ids !== null) {
                $restricted = true;
            }
        }

        if (! $restricted) {
            return;
        }

        $query->where(function (EloquentBuilder $outer) use ($limits): void {
            foreach ($limits as $tenantId => $ids) {
                $outer->orWhere(function (EloquentBuilder $inner) use ($tenantId, $ids): void {
                    $inner->where($inner->getModel()->qualifyColumn('tenant_id'), $tenantId);

                    if (is_array($ids)) {
                        $inner->whereIn(
                            $inner->getModel()->qualifyColumn('location_id'),
                            $ids === [] ? [''] : $ids,
                        );
                    }
                });
            }
        });
    }

    /**
     * @return list<string>|null
     */
    public function assignedIds(User $user, string $tenantId): ?array
    {
        $key = $user->id.'|'.$tenantId;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $membership = Membership::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active)
            ->first();

        if (! $membership instanceof Membership || ! $membership->locations_restricted) {
            $this->cache[$key] = null;

            return null;
        }

        $ids = MembershipLocation::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('membership_id', $membership->id)
            ->orderBy('location_id')
            ->pluck('location_id')
            ->all();

        $this->cache[$key] = array_values(array_map(strval(...), $ids));

        return $this->cache[$key];
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        if ($user instanceof User) {
            return $user;
        }

        $requestUser = request()->user();

        return $requestUser instanceof User ? $requestUser : null;
    }
}
