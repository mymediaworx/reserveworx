<?php

namespace App\Services\Integrations;

use App\Models\IntegrationOutbox;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

/**
 * Queues outbound GoHighLevel events. This class never calls HighLevel.
 * Marketplace OAuth is still an unapproved proposal, and a missing secret
 * must not turn into a live request.
 */
final class Outbox
{
    public function __construct(private readonly TenantManager $tenants) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(string $tenantId, string $kind, string $dedupeKey, array $payload): void
    {
        $context = $this->tenants->id();
        $payloadTenant = $payload['tenant_id'] ?? null;

        if (! is_string($context) || $context !== $tenantId || (is_string($payloadTenant) && $payloadTenant !== $context)) {
            throw new TenantContextException('Refusing to queue an integration event for a different workspace.');
        }

        try {
            IntegrationOutbox::query()->create([
                'tenant_id' => $tenantId,
                'destination' => 'ghl',
                'kind' => $kind,
                'dedupe_key' => $dedupeKey,
                'payload' => $payload,
                'status' => 'queued',
                'attempts' => 0,
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
        }
    }

    public function attemptDue(): int
    {
        $tenantId = $this->tenants->id();

        if (! is_string($tenantId)) {
            throw new TenantContextException('Refusing to send an integration event without a workspace.');
        }

        $now = Carbon::now('UTC');
        $rows = IntegrationOutbox::query()
            ->where('status', 'queued')
            ->where(function ($query) use ($now): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($rows as $row) {
            if ($row->tenant_id !== $tenantId) {
                throw new TenantContextException('Refusing to send an integration event for a different workspace.');
            }

            $attempts = $row->attempts + 1;
            $row->attempts = $attempts;
            $row->last_error = 'GoHighLevel is not connected. The event stays queued.';
            $row->available_at = $now->addMinutes($this->backoffMinutes($attempts));

            if ($attempts >= 5) {
                $row->status = 'held';
            }

            $row->save();
        }

        return $rows->count();
    }

    private function backoffMinutes(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 1,
            $attempts === 2 => 5,
            $attempts === 3 => 15,
            $attempts === 4 => 60,
            default => 180,
        };
    }
}
