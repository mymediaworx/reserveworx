<?php

namespace App\Services;

use App\Enums\TenantStatus;

class TenantLifecycle
{
    public function canTransition(TenantStatus $from, TenantStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            TenantStatus::Onboarding => in_array($to, [TenantStatus::Active, TenantStatus::Suspended], true),
            TenantStatus::Active => in_array($to, [TenantStatus::Suspended, TenantStatus::Terminated], true),
            TenantStatus::Suspended => in_array($to, [TenantStatus::Active, TenantStatus::Terminated], true),
            TenantStatus::Terminated => false,
        };
    }
}
