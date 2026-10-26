<?php

namespace App\Services\Integrations;

use App\Models\GhlMapping;
use App\Tenancy\TenantScope;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * One HighLevel contact per customer and location.
 * The first send stores the id. Later sends update that contact.
 */
final class GhlContacts
{
    public function __construct(private readonly GoHighLevelClient $client) {}

    /**
     * @param  array{email?: string, phone?: string, name?: string, firstName?: string, lastName?: string}  $contact
     */
    public function resolve(string $token, string $tenantId, string $locationId, string $customerId, array $contact): string
    {
        try {
            $contactId = Cache::lock($this->key($tenantId, $customerId, $locationId), 30)->block(
                15,
                fn (): string => $this->locked($token, $tenantId, $locationId, $customerId, $contact),
            );
        } catch (LockTimeoutException) {
            throw new GhlRequestFailed(null, 'contact', true);
        }

        if (! is_string($contactId) || $contactId === '') {
            throw new GhlRequestFailed(null, 'contact', true);
        }

        return $contactId;
    }

    /**
     * @param  array{email?: string, phone?: string, name?: string, firstName?: string, lastName?: string}  $contact
     */
    private function locked(string $token, string $tenantId, string $locationId, string $customerId, array $contact): string
    {
        $mapped = $this->mapping($tenantId, $customerId, $locationId);

        if ($mapped instanceof GhlMapping) {
            try {
                $this->client->updateContact($token, $mapped->contact_id, $locationId, $contact);

                return $mapped->contact_id;
            } catch (GhlRequestFailed $exception) {
                if ($exception->httpStatus !== 404 && ! $exception->wrongLocation) {
                    throw $exception;
                }

                $mapped->delete();
            }
        }

        $contactId = $this->client->upsertContact($token, $locationId, $contact);
        $this->remember($tenantId, $customerId, $locationId, $contactId);

        return $contactId;
    }

    private function mapping(string $tenantId, string $customerId, string $locationId): ?GhlMapping
    {
        $mapped = GhlMapping::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('location_id', $locationId)
            ->first();

        return $mapped instanceof GhlMapping && $mapped->contact_id !== '' ? $mapped : null;
    }

    private function remember(string $tenantId, string $customerId, string $locationId, string $contactId): void
    {
        GhlMapping::withoutGlobalScope(TenantScope::class)->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'location_id' => $locationId,
            ],
            ['contact_id' => $contactId],
        );
    }

    private function key(string $tenantId, string $customerId, string $locationId): string
    {
        return 'ghl-contact:'.$tenantId.':'.$customerId.':'.$locationId;
    }
}
