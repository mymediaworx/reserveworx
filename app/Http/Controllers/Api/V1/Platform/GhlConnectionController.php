<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LinkGhlSubAccountRequest;
use App\Models\GhlConnection;
use App\Models\GhlSyncLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Integrations\GhlRequestFailed;
use App\Services\Integrations\GhlSyncLogger;
use App\Services\Integrations\GoHighLevelClient;
use App\Tenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GhlConnectionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GoHighLevelClient $client,
        private readonly GhlSyncLogger $logger,
    ) {}

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'data' => $this->payload($this->connection($tenant)),
        ]);
    }

    public function update(LinkGhlSubAccountRequest $request, Tenant $tenant): JsonResponse
    {
        $connection = $this->connection($tenant) ?? new GhlConnection([
            'tenant_id' => $tenant->id,
        ]);
        $connection->location_id = $request->string('location_id')->toString();
        $connection->private_integration_token = $request->string('private_integration_token')->toString();
        $connection->save();

        $this->audit->log(
            action: 'platform.ghl_linked',
            subject: $connection,
            properties: [
                'location_id' => $connection->location_id,
                'token_updated' => true,
            ],
            tenantId: $tenant->id,
            actor: $request->user() instanceof User ? $request->user() : null,
            request: $request,
            useContextTenant: false,
        );

        return response()->json([
            'data' => $this->payload($connection),
        ]);
    }

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        $connection = $this->connection($tenant);

        if ($connection instanceof GhlConnection) {
            $locationId = $connection->location_id;
            $connection->delete();

            $this->audit->log(
                action: 'platform.ghl_unlinked',
                subject: $connection,
                properties: [
                    'location_id' => $locationId,
                ],
                tenantId: $tenant->id,
                actor: $request->user() instanceof User ? $request->user() : null,
                request: $request,
                useContextTenant: false,
            );
        }

        return response()->json([
            'data' => $this->payload(null),
        ]);
    }

    public function testConnection(Request $request, Tenant $tenant): JsonResponse
    {
        $connection = $this->connection($tenant);

        if (! $connection instanceof GhlConnection) {
            return response()->json([
                'message' => 'GoHighLevel is not linked.',
            ], 422);
        }

        $token = $connection->private_integration_token;

        try {
            $this->client->assertLocation($token, $connection->location_id);
        } catch (GhlRequestFailed $exception) {
            $this->logger->write(
                tenantId: $tenant->id,
                kind: 'connection.test',
                attempt: 1,
                status: 'failed',
                locationId: $connection->location_id,
                bookingId: null,
                paymentId: null,
                httpStatus: $exception->httpStatus,
                error: $exception->getMessage(),
                secret: $token,
            );

            return response()->json([
                'message' => 'GoHighLevel refused the connection.',
            ], 422);
        }

        $this->logger->write(
            tenantId: $tenant->id,
            kind: 'connection.test',
            attempt: 1,
            status: 'succeeded',
            locationId: $connection->location_id,
            bookingId: null,
            paymentId: null,
            httpStatus: null,
            error: null,
            secret: $token,
        );

        return response()->json([
            'data' => [
                'ok' => true,
                'location_id' => $connection->location_id,
            ],
        ]);
    }

    public function logs(Tenant $tenant): JsonResponse
    {
        $logs = GhlSyncLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $logs->map(function (GhlSyncLog $log): array {
                return [
                    'id' => $log->id,
                    'booking_id' => $log->booking_id,
                    'payment_id' => $log->payment_id,
                    'kind' => $log->kind,
                    'attempt' => $log->attempt,
                    'status' => $log->status,
                    'http_status' => $log->http_status,
                    'error' => $log->error,
                    'location_id' => $log->location_id,
                    'created_at' => $log->created_at?->toISOString(),
                ];
            })->all(),
        ]);
    }

    private function connection(Tenant $tenant): ?GhlConnection
    {
        return GhlConnection::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->first();
    }

    /**
     * @return array{linked: bool, location_id: string|null, updated_at: string|null}
     */
    private function payload(?GhlConnection $connection): array
    {
        if (! $connection instanceof GhlConnection) {
            return [
                'linked' => false,
                'location_id' => null,
                'updated_at' => null,
            ];
        }

        return [
            'linked' => true,
            'location_id' => $connection->location_id,
            'updated_at' => $connection->updated_at?->toISOString(),
        ];
    }
}
