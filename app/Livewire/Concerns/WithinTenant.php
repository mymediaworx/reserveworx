<?php

namespace App\Livewire\Concerns;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\Access;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared helpers for tenant pages. The tenant always comes from the request's
 * verified domain (set by ResolveTenant on page loads and by
 * ResolveTenantUnlessPlatform on Livewire updates), never from component state.
 */
trait WithinTenant
{
    protected function tenant(): Tenant
    {
        $tenant = app(TenantManager::class)->get();
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    protected function settings(): ?TenantSetting
    {
        return TenantSetting::query()->where('tenant_id', $this->tenant()->id)->first();
    }

    protected function currentUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function allows(string $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(Access::class)->allows($user, $this->tenant()->id, $permission);
    }

    protected function requirePermission(string $permission): void
    {
        abort_unless($this->allows($permission), 403);
    }

    /**
     * Timezone for "today" on tenant-wide screens: the first active location's zone.
     */
    protected function defaultTimezone(): string
    {
        $zone = Location::query()->orderBy('created_at')->value('timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function audit(string $action, ?Model $subject = null, array $properties = []): void
    {
        app(AuditLogger::class)->log($action, $subject, $properties, request: request());
    }
}
