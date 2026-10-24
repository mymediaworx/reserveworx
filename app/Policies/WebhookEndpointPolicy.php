<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Policies\Concerns\ChecksTenantPermission;

class WebhookEndpointPolicy
{
    use ChecksTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->tenantAllows($user, 'webhooks.view');
    }

    public function view(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->sameTenant($endpoint->tenant_id) && $this->tenantAllows($user, 'webhooks.view');
    }

    public function create(User $user): bool
    {
        return $this->tenantAllows($user, 'webhooks.manage');
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->sameTenant($endpoint->tenant_id) && $this->tenantAllows($user, 'webhooks.manage');
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $this->sameTenant($endpoint->tenant_id) && $this->tenantAllows($user, 'webhooks.manage');
    }
}
