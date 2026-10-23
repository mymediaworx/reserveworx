<?php

namespace App\Services\Notifications;

use App\Models\NotificationHook;
use Illuminate\Database\QueryException;

final class NotificationHooks
{
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
    }
}
