<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookAttemptFailed;
use App\Services\Webhooks\WebhookSender;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantScope;
use Throwable;

class DeliverWebhook extends TenantAwareJob
{
    public int $tries = 5;

    public int $timeout = 15;

    public function __construct(string $tenantId, public string $deliveryId)
    {
        parent::__construct($tenantId);
        $this->onConnection('database');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    protected function handleForTenant(Tenant $tenant): void
    {
        if ($tenant->id !== $this->tenantId) {
            throw new TenantContextException('Refusing to deliver a webhook for a different workspace.');
        }

        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if (! $delivery instanceof WebhookDelivery || $delivery->tenant_id !== $tenant->id) {
            return;
        }

        if ($delivery->status !== 'pending') {
            return;
        }

        try {
            app(WebhookSender::class)->send($delivery);
        } catch (WebhookAttemptFailed $exception) {
            if ($this->attempts() >= $this->tries) {
                $delivery->refresh();
                $delivery->status = 'failed';
                $delivery->save();

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = WebhookDelivery::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->deliveryId)
            ->first();

        if ($delivery instanceof WebhookDelivery && $delivery->status === 'pending') {
            $delivery->status = 'failed';
            $delivery->save();
        }
    }
}
