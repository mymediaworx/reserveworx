<?php

namespace App\Services\Notifications;

use App\Models\NotificationHook;
use App\Services\Integrations\GhlMessages;
use App\Services\Integrations\Outbox;
use Illuminate\Database\QueryException;

final class NotificationHooks
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly GhlMessages $messages,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $tenantId, ?string $bookingId, string $kind, string $dedupeKey, array $payload): void
    {
        try {
            NotificationHook::query()->create([
                'tenant_id' => $tenantId,
                'booking_id' => $bookingId,
                'kind' => $kind,
                'dedupe_key' => $dedupeKey,
                'payload' => $payload,
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
        }

        $this->outbox->enqueue($tenantId, $kind, $dedupeKey, array_merge($payload, [
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
        ]));
        $this->messages->queueFromHook($tenantId, $bookingId, $kind, $dedupeKey);
    }
}
