<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Payments\FeeRevenueReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeRevenueController extends Controller
{
    public function __construct(
        private readonly FeeRevenueReport $report,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $from = $this->day($validated['from'] ?? null);
        $to = $this->day($validated['to'] ?? null);

        if ($from !== null && $to !== null && $from > $to) {
            return response()->json([
                'message' => 'The end date must be on or after the start date.',
            ], 422);
        }

        $data = $this->report->build($from, $to);

        $this->audit->log(
            action: 'platform.fee_revenue_viewed',
            properties: ['from' => $from, 'to' => $to],
            actor: $request->user() instanceof User ? $request->user() : null,
            request: $request,
            useContextTenant: false,
        );

        return response()->json(['data' => $data]);
    }

    private function day(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
