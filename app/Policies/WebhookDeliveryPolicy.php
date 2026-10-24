<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Policies\Concerns\ChecksTenantPermission;

class WebhookDeliveryPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'webhooks.view');
    }

    public function view(User $user, WebhookDelivery $delivery): bool
    {
        return $this->sameTenant($delivery->tenant_id) && $this->tenantAllows($user, 'webhooks.view');
    }

    public function redeliver(User $user, WebhookDelivery $delivery): bool
    {
        return $this->sameTenant($delivery->tenant_id) && $this->tenantAllows($user, 'webhooks.manage');
    }
}
