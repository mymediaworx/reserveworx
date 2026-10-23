<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantSetting;
use App\Services\Integrations\OutboxDiagnostics;
use Illuminate\Http\JsonResponse;

class OutboxController extends Controller
{
    public function __construct(private readonly OutboxDiagnostics $diagnostics) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', TenantSetting::class);

        return response()->json(['data' => $this->diagnostics->summary()]);
    }
}
