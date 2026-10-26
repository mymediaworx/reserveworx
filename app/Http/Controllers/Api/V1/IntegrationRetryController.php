<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GhlMessageLog;
use App\Models\GhlSyncLog;
use App\Models\TenantSetting;
use App\Services\Integrations\IntegrationRecovery;
use App\Services\Integrations\IntegrationRetryRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationRetryController extends Controller
{
    public function __construct(private readonly IntegrationRecovery $recovery) {}

    public function retrySync(Request $request, GhlSyncLog $ghl_sync_log): JsonResponse
    {
        $this->authorizeRetry();

        try {
            $this->recovery->retrySync($ghl_sync_log, $request);
        } catch (IntegrationRetryRefused $exception) {
            abort(409, $exception->getMessage());
        }

        return response()->json([
            'data' => [
                'id' => $ghl_sync_log->id,
                'kind' => $ghl_sync_log->kind,
                'accepted' => true,
            ],
        ], 202);
    }

    public function retryMessage(Request $request, GhlMessageLog $ghl_message_log): JsonResponse
    {
        $this->authorizeRetry();

        try {
            $this->recovery->retryMessage($ghl_message_log, $request);
        } catch (IntegrationRetryRefused $exception) {
            abort(409, $exception->getMessage());
        }

        return response()->json([
            'data' => [
                'id' => $ghl_message_log->id,
                'kind' => $ghl_message_log->kind,
                'accepted' => true,
            ],
        ], 202);
    }

    private function authorizeRetry(): void
    {
        $setting = TenantSetting::query()->first();

        if (! $setting instanceof TenantSetting) {
            abort(403);
        }

        $this->authorize('update', $setting);
    }
}
