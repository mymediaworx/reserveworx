<?php

namespace App\Services\Branding;

use App\Enums\TransactionalEmail;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\TenantNotificationSetting;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;

/**
 * Creates the branding and notification rows that belong to one workspace.
 */
final class WorkspaceProfiles
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function provision(Tenant $tenant): void
    {
        $name = trim($tenant->name);

        TenantBranding::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'display_name' => $name === '' ? 'Workspace' : mb_substr($name, 0, 120),
                'primary_color' => TenantBranding::DEFAULT_PRIMARY,
                'accent_color' => TenantBranding::DEFAULT_ACCENT,
            ],
        );

        TenantNotificationSetting::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'staff_alert_recipients' => [],
                'transactional_emails' => TransactionalEmail::defaults(),
            ],
        );
    }

    public function branding(): TenantBranding
    {
        $existing = TenantBranding::query()->first();

        if ($existing instanceof TenantBranding) {
            return $existing;
        }

        $this->provision($this->tenant());

        return TenantBranding::query()->firstOrFail();
    }

    public function notificationSettings(): TenantNotificationSetting
    {
        $existing = TenantNotificationSetting::query()->first();

        if ($existing instanceof TenantNotificationSetting) {
            return $existing;
        }

        $this->provision($this->tenant());

        return TenantNotificationSetting::query()->firstOrFail();
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenants->get();

        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
