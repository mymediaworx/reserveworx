<?php

namespace App\Services\Integrations;

use App\Jobs\SendGhlBookingMessage;
use App\Jobs\SyncBookingToGoHighLevel;
use App\Models\GhlConnection;
use App\Models\GhlMessageLog;
use App\Models\GhlSyncLog;
use App\Services\AuditLogger;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Queues one more try after GoHighLevel has stopped on its own.
 * The booking, its price, and its payments are left as they are.
 */
final class IntegrationRecovery
{
    public const RETRY_QUEUED = 'Retry queued.';

    public function __construct(
        private readonly TenantManager $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function retrySync(GhlSyncLog $log, ?Request $request = null): void
    {
        $this->assertContext($log->tenant_id);

        DB::transaction(function () use ($log): void {
            $locked = GhlSyncLog::query()->whereKey($log->id)->lockForUpdate()->first();

            if (! $locked instanceof GhlSyncLog) {
                throw new IntegrationRetryRefused('This sync is not waiting on a retry.');
            }

            $this->guardSync($locked);
            GhlSyncLog::query()->create([
                'tenant_id' => $locked->tenant_id,
                'booking_id' => $locked->booking_id,
                'payment_id' => $locked->payment_id,
                'kind' => $locked->kind,
                'attempt' => $locked->attempt,
                'status' => 'skipped',
                'http_status' => null,
                'error' => self::RETRY_QUEUED,
                'location_id' => $locked->location_id,
            ]);
            SyncBookingToGoHighLevel::dispatch(
                $locked->tenant_id,
                (string) $locked->booking_id,
                $locked->kind,
                $locked->payment_id,
            );
        });

        $this->audit->log('ghl_sync.retried', $log, [
            'kind' => $log->kind,
            'attempt' => $log->attempt,
        ], request: $request);
    }

    public function retryMessage(GhlMessageLog $log, ?Request $request = null): void
    {
        $this->assertContext($log->tenant_id);

        DB::transaction(function () use ($log): void {
            $locked = GhlMessageLog::query()->whereKey($log->id)->lockForUpdate()->first();

            if (! $locked instanceof GhlMessageLog) {
                throw new IntegrationRetryRefused('This message is not waiting on a retry.');
            }

            $this->guardMessage($locked);
            $locked->status = 'queued';
            $locked->save();
            SendGhlBookingMessage::dispatch($locked->tenant_id, $locked->id);
        });

        $this->audit->log('ghl_message.retried', $log, [
            'kind' => $log->kind,
            'attempt' => $log->attempts,
        ], request: $request);
    }

    private function guardSync(GhlSyncLog $log): void
    {
        if (! is_string($log->booking_id) || $log->booking_id === '') {
            throw new IntegrationRetryRefused('This sync has no booking to send again.');
        }

        $latest = $this->latestSync($log);

        if ($latest->id !== $log->id) {
            if ($latest->status === 'skipped' && $latest->error === self::RETRY_QUEUED) {
                throw new IntegrationRetryRefused('This sync is already queued.');
            }

            throw new IntegrationRetryRefused('A later try is already recorded.');
        }

        if ($log->status !== 'failed') {
            throw new IntegrationRetryRefused('This sync is not waiting on a retry.');
        }

        if ($log->attempt < 5) {
            throw new IntegrationRetryRefused('This sync is still retrying.');
        }

        if (! $this->linked($log->tenant_id)) {
            throw new IntegrationRetryRefused('GoHighLevel is not linked.');
        }
    }

    private function guardMessage(GhlMessageLog $log): void
    {
        if (! is_string($log->booking_id) || $log->booking_id === '') {
            throw new IntegrationRetryRefused('This message has no booking to send again.');
        }

        if (! $this->linked($log->tenant_id)) {
            throw new IntegrationRetryRefused('GoHighLevel is not linked.');
        }

        if ($log->status === 'queued') {
            throw new IntegrationRetryRefused('This message is already queued.');
        }

        if ($log->status !== 'failed') {
            throw new IntegrationRetryRefused('This message is not waiting on a retry.');
        }

        if ($log->attempts < 5) {
            throw new IntegrationRetryRefused('This message is still retrying.');
        }
    }

    private function latestSync(GhlSyncLog $log): GhlSyncLog
    {
        $query = GhlSyncLog::query()
            ->where('booking_id', $log->booking_id)
            ->where('kind', $log->kind);

        if ($log->payment_id === null) {
            $query->whereNull('payment_id');
        } else {
            $query->where('payment_id', $log->payment_id);
        }

        $latest = $query->orderByDesc('created_at')->orderByDesc('id')->first();

        return $latest instanceof GhlSyncLog ? $latest : $log;
    }

    private function linked(string $tenantId): bool
    {
        $connection = GhlConnection::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->first();

        return $connection instanceof GhlConnection
            && $connection->location_id !== ''
            && $connection->private_integration_token !== '';
    }

    private function assertContext(string $tenantId): void
    {
        $context = $this->tenants->id();

        if (! is_string($context) || $context !== $tenantId) {
            throw new TenantContextException('Refusing to retry an integration event for a different workspace.');
        }
    }
}
