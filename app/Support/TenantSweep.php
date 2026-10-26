<?php

namespace App\Support;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\Cache;

/**
 * Visits active and onboarding workspaces in id order, and stops when the
 * shared-host window is used up. The next run continues after the last
 * workspace that finished. A short tail continues from the first workspace
 * while time remains.
 */
final class TenantSweep
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly SharedHostBudget $budget,
    ) {}

    /**
     * @param  callable(Tenant): (void|bool)  $visit  Return false when this workspace did not finish.
     */
    public function each(string $name, callable $visit): SweepResult
    {
        $cursorKey = 'shared-host-sweep:'.$name;
        $cursor = $this->cursor($cursorKey);
        $visited = 0;
        $deferred = $this->pass($cursorKey, $cursor, null, $visit, $visited);

        if (! $deferred && $cursor !== null) {
            $deferred = $this->pass($cursorKey, null, $cursor, $visit, $visited);
        }

        if (! $deferred) {
            Cache::forget($cursorKey);
        }

        return new SweepResult($visited, $deferred);
    }

    /**
     * @param  callable(Tenant): (void|bool)  $visit
     */
    private function pass(string $cursorKey, ?string $after, ?string $through, callable $visit, int &$visited): bool
    {
        $stopped = false;

        $query = Tenant::query()
            ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Onboarding->value])
            ->orderBy('id');

        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        if ($through !== null) {
            $query->where('id', '<=', $through);
        }

        $query->each(function (Tenant $tenant) use ($visit, $cursorKey, &$visited, &$stopped): bool {
            if ($this->budget->expired()) {
                $stopped = true;

                return false;
            }

            $finished = true;
            $this->withTenant($tenant, function (Tenant $current) use ($visit, &$finished): void {
                if ($visit($current) === false) {
                    $finished = false;
                }
            });

            if (! $finished) {
                $stopped = true;

                return false;
            }

            Cache::put($cursorKey, $tenant->id, now()->addHours(6));
            $visited++;

            return true;
        });

        return $stopped;
    }

    private function cursor(string $key): ?string
    {
        $cursor = Cache::get($key);

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * @param  callable(Tenant): void  $visit
     */
    private function withTenant(Tenant $tenant, callable $visit): void
    {
        $previous = $this->tenants->get();
        $this->tenants->set($tenant);

        try {
            $visit($tenant);
        } finally {
            if ($previous instanceof Tenant) {
                $this->tenants->set($previous);
            } else {
                $this->tenants->forget();
            }
        }
    }
}
