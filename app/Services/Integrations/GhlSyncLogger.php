<?php

namespace App\Services\Integrations;

use App\Models\GhlSyncLog;

class GhlSyncLogger
{
    public function write(
        string $tenantId,
        string $kind,
        int $attempt,
        string $status,
        ?string $locationId,
        ?string $bookingId,
        ?string $paymentId,
        ?int $httpStatus,
        ?string $error,
        ?string $secret,
    ): GhlSyncLog {
        if (! in_array($status, ['succeeded', 'failed', 'skipped'], true)) {
            $status = 'failed';
        }

        return GhlSyncLog::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
            'payment_id' => $paymentId,
            'kind' => $kind,
            'attempt' => max(1, $attempt),
            'status' => $status,
            'http_status' => $httpStatus,
            'error' => $this->safe($error, $secret),
            'location_id' => $locationId,
        ]);
    }

    private function safe(?string $error, ?string $secret): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        if (is_string($secret) && $secret !== '') {
            $error = str_replace($secret, '[redacted]', $error);
        }

        $scrubbed = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $error);
        $error = is_string($scrubbed) ? $scrubbed : $error;

        return mb_substr($error, 0, 255);
    }
}
