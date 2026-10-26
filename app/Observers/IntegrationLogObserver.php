<?php

namespace App\Observers;

use App\Models\GhlMessageLog;
use App\Models\GhlSyncLog;
use App\Models\IntegrationOutbox;
use App\Models\WebhookDeliveryAttempt;
use App\Services\Integrations\IntegrationLogger;
use Illuminate\Database\Eloquent\Model;

class IntegrationLogObserver
{
    public function __construct(private readonly IntegrationLogger $logger) {}

    public function created(Model $model): void
    {
        if ($model instanceof WebhookDeliveryAttempt) {
            $this->logger->fromWebhookAttempt($model);

            return;
        }

        if ($model instanceof GhlSyncLog) {
            $this->logger->fromSync($model);

            return;
        }

        if ($model instanceof GhlMessageLog && $model->attempts >= 1) {
            $this->logger->fromMessage($model);
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof IntegrationOutbox) {
            if (! $model->wasChanged('attempts') || $model->attempts < 1) {
                return;
            }

            $this->logger->fromOutbox($model);

            return;
        }

        if ($model instanceof GhlMessageLog && $model->wasChanged('attempts') && $model->attempts >= 1) {
            $this->logger->fromMessage($model);
        }
    }
}
