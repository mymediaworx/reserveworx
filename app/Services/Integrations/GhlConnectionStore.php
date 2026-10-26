<?php

namespace App\Services\Integrations;

use App\Models\GhlConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantScope;
use Illuminate\Http\Request;

/**
 * The only place a Private Integration Token is written.
 * Callers pass the token in memory and never put it on the audit row.
 */
final class GhlConnectionStore
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function find(Tenant $tenant): ?GhlConnection
    {
        return GhlConnection::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->first();
    }

    public function link(
        Tenant $tenant,
        string $locationId,
        ?string $token,
        ?User $actor,
        ?Request $request,
    ): GhlConnection {
        $connection = $this->find($tenant) ?? new GhlConnection([
            'tenant_id' => $tenant->id,
        ]);
        $replaceToken = is_string($token) && $token !== '';
        $connection->location_id = $locationId;

        if ($replaceToken || ! $connection->exists) {
            $connection->private_integration_token = (string) $token;
        }

        $connection->save();

        $this->audit->log(
            action: 'platform.ghl_linked',
            subject: $connection,
            properties: [
                'location_id' => $connection->location_id,
                'token_updated' => $replaceToken,
            ],
            tenantId: $tenant->id,
            actor: $actor,
            request: $request,
            useContextTenant: false,
        );

        return $connection;
    }

    public function unlink(Tenant $tenant, ?User $actor, ?Request $request): void
    {
        $connection = $this->find($tenant);

        if (! $connection instanceof GhlConnection) {
            return;
        }

        $locationId = $connection->location_id;
        $connection->delete();

        $this->audit->log(
            action: 'platform.ghl_unlinked',
            subject: $connection,
            properties: [
                'location_id' => $locationId,
            ],
            tenantId: $tenant->id,
            actor: $actor,
            request: $request,
            useContextTenant: false,
        );
    }
}
