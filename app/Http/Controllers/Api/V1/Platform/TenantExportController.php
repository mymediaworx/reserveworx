<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\TenantExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantExportController extends Controller
{
    public function __construct(private readonly TenantExporter $exports) {}

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $export = $this->exports->create($tenant, $actor, $request);

        return response()->json([
            'data' => [
                'id' => $export->id,
                'bytes' => $export->bytes,
                'row_counts' => $export->row_counts,
                'completed_at' => $export->completed_at->toIso8601String(),
            ],
        ], 201);
    }

    public function download(Tenant $tenant, string $export): StreamedResponse
    {
        return $this->exports->download($tenant, $export);
    }
}
