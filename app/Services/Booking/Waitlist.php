<?php

namespace App\Services\Booking;

use App\Enums\WaitlistStatus;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

class Waitlist
{
    public function join(
        string $tenantId,
        string $serviceId,
        ?string $departureId,
        string $locationId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $participants,
        ?string $customerName,
        ?string $customerEmail,
        ?string $createdBy,
        ?string $idempotencyKey,
        ?string $variantId = null,
        ?string $customerPhone = null,
    ): WaitlistEntry {
        $key = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null;

        if ($key !== null) {
            $existing = WaitlistEntry::query()->where('idempotency_key', $key)->first();

            if ($existing instanceof WaitlistEntry) {
                return $existing;
            }
        }

        try {
            return WaitlistEntry::query()->create([
                'tenant_id' => $tenantId,
                'service_id' => $serviceId,
                'service_variant_id' => $variantId !== null && $variantId !== '' ? $variantId : null,
                'departure_id' => $departureId,
                'location_id' => $locationId,
                'created_by' => $createdBy,
                'starts_at' => $startsAt->utc(),
                'ends_at' => $endsAt->utc(),
                'participants' => $participants,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail !== null ? strtolower($customerEmail) : null,
                'customer_phone' => $this->phone($customerPhone),
                'status' => WaitlistStatus::Waiting,
                'idempotency_key' => $key,
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062 || $key === null) {
                throw $exception;
            }

            $existing = WaitlistEntry::query()->where('idempotency_key', $key)->first();

            if ($existing instanceof WaitlistEntry) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function phone(?string $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $trimmed = trim($phone);

        return $trimmed === '' ? null : $trimmed;
    }
}
