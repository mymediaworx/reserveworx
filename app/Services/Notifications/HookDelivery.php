<?php

namespace App\Services\Notifications;

use App\Models\NotificationHook;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Carbon\Carbon;

/**
 * Records that a hook was not emailed. No mailer is installed, and PHP mail() is not used.
 */
final class HookDelivery
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function skipUnconfigured(): int
    {
        $tenantId = $this->tenants->id();

        if (! is_string($tenantId)) {
            throw new TenantContextException('Refusing to deliver notifications without a workspace.');
        }

        $hooks = NotificationHook::query()
            ->where('delivery_status', 'queued')
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($hooks as $hook) {
            if ($hook->tenant_id !== $tenantId) {
                throw new TenantContextException('Refusing to deliver a notification for a different workspace.');
            }

            $hook->delivery_status = 'skipped';
            $hook->delivery_error = 'No transactional email provider is configured.';
            $hook->attempted_at = Carbon::now('UTC');
            $hook->save();
        }

        return $hooks->count();
    }
}
