<?php

namespace App\Services\Notifications;

use App\Enums\TransactionalEmail;
use App\Models\TenantNotificationSetting;
use App\Tenancy\TenantScope;

/**
 * Answers whether a workspace wants a transactional email sent.
 * Notification hooks are still recorded. A mailer checks this before it sends.
 */
final class TransactionalEmailPreferences
{
    public function enabled(string $tenantId, string $kind): bool
    {
        if (! in_array($kind, TransactionalEmail::values(), true)) {
            return false;
        }

        $row = TenantNotificationSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $row instanceof TenantNotificationSetting) {
            return true;
        }

        return $row->allows($kind);
    }
}
