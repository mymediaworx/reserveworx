<?php

namespace App\Services\Integrations;

use App\Models\GhlMessageLog;
use App\Models\GhlSyncLog;
use App\Models\IntegrationLog;
use App\Models\IntegrationOutbox;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Tenancy\TenantScope;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Writes one integration_logs row per outbound attempt.
 * The row never stores a payload, a response body, a webhook secret, or a token.
 */
final class IntegrationLogger
{
    /**
     * @var list<string>
     */
    private const STATUSES = ['deferred', 'held', 'succeeded', 'failed', 'skipped'];

    /**
     * @var list<string>
     */
    private const WEBHOOK_ERRORS = [
        'The endpoint is not active.',
        'The endpoint could not be reached.',
        'The endpoint address is not public.',
    ];

    public function fromOutbox(IntegrationOutbox $row): IntegrationLog
    {
        $available = $row->available_at;

        return $this->record(
            (string) $row->tenant_id,
            IntegrationLog::CHANNEL_OUTBOX,
            (string) $row->kind,
            (int) $row->attempts,
            (string) $row->status === 'held' ? 'held' : 'deferred',
            null,
            is_string($row->last_error) ? $row->last_error : null,
            $available instanceof DateTimeInterface ? $available : null,
        );
    }

    public function fromWebhookAttempt(WebhookDeliveryAttempt $attempt): IntegrationLog
    {
        $delivery = WebhookDelivery::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $attempt->tenant_id)
            ->whereKey($attempt->webhook_delivery_id)
            ->first();

        $event = $delivery instanceof WebhookDelivery ? $delivery->getAttribute('event') : null;
        $kind = is_string($event) && $event !== '' ? $event : 'webhook';

        $error = null;
        $status = (string) $attempt->status;

        if ($status !== 'succeeded') {
            $body = $attempt->response_body;
            $error = is_string($body) && in_array($body, self::WEBHOOK_ERRORS, true)
                ? $body
                : 'The endpoint refused the delivery.';
        }

        $code = $attempt->response_code;

        return $this->record(
            (string) $attempt->tenant_id,
            IntegrationLog::CHANNEL_WEBHOOK,
            $kind,
            (int) $attempt->attempt,
            $status === 'succeeded' ? 'succeeded' : 'failed',
            is_int($code) ? $code : null,
            $error,
            null,
        );
    }

    public function fromSync(GhlSyncLog $log): IntegrationLog
    {
        $code = $log->http_status;

        return $this->record(
            (string) $log->tenant_id,
            IntegrationLog::CHANNEL_GHL_SYNC,
            (string) $log->kind,
            (int) $log->attempt,
            (string) $log->status,
            is_int($code) ? $code : null,
            is_string($log->error) ? $log->error : null,
            null,
        );
    }

    public function fromMessage(GhlMessageLog $log): IntegrationLog
    {
        $code = $log->http_status;

        return $this->record(
            (string) $log->tenant_id,
            IntegrationLog::CHANNEL_GHL_MESSAGE,
            (string) $log->kind,
            (int) $log->attempts,
            (string) $log->status,
            is_int($code) ? $code : null,
            is_string($log->error) ? $log->error : null,
            null,
        );
    }

    public function record(
        string $tenantId,
        string $channel,
        string $kind,
        int $attempt,
        string $status,
        ?int $httpStatus,
        ?string $error,
        ?DateTimeInterface $availableAt,
    ): IntegrationLog {
        if ($tenantId === '') {
            throw new InvalidArgumentException('An integration log needs a workspace.');
        }

        if (! in_array($channel, [
            IntegrationLog::CHANNEL_OUTBOX,
            IntegrationLog::CHANNEL_WEBHOOK,
            IntegrationLog::CHANNEL_GHL_SYNC,
            IntegrationLog::CHANNEL_GHL_MESSAGE,
        ], true)) {
            throw new InvalidArgumentException('Unknown integration channel.');
        }

        $kind = mb_substr(trim($kind), 0, 64);

        if ($kind === '') {
            $kind = $channel;
        }

        if (! in_array($status, self::STATUSES, true)) {
            $status = 'failed';
        }

        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            $httpStatus = null;
        }

        if ($channel !== IntegrationLog::CHANNEL_OUTBOX) {
            $availableAt = null;
        }

        return IntegrationLog::query()->create([
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'kind' => $kind,
            'attempt' => max(1, $attempt),
            'status' => $status,
            'http_status' => $httpStatus,
            'error' => $this->redact($error),
            'available_at' => $availableAt,
        ]);
    }

    public function redact(?string $error): ?string
    {
        if ($error === null || trim($error) === '') {
            return null;
        }

        $scrubbed = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $error);
        $error = is_string($scrubbed) ? $scrubbed : $error;
        $scrubbed = preg_replace('/\bwhsec_[A-Za-z0-9]+\b/', '[redacted]', $error);
        $error = is_string($scrubbed) ? $scrubbed : $error;

        return mb_substr($error, 0, 255);
    }
}
