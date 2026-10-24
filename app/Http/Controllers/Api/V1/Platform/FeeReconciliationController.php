<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Payments\StripeNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Payments\FeeListTruncatedException;
use App\Services\Payments\FeeReconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeReconciliationController extends Controller
{
    public function __construct(
        private readonly FeeReconciliation $reconciliation,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        $from = $validated['from'];
        $to = $validated['to'];

        if (! is_string($from) || ! is_string($to)) {
            return response()->json(['message' => 'A start date and an end date are required.'], 422);
        }

        if ($from > $to) {
            return response()->json([
                'message' => 'The end date must be on or after the start date.',
            ], 422);
        }

        try {
            $data = $this->reconciliation->build($from, $to);
        } catch (StripeNotConfiguredException|FeeListTruncatedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $this->audit->log(
            action: 'platform.fee_reconciliation_viewed',
            properties: [
                'from' => $from,
                'to' => $to,
                'balanced' => $data['balanced'],
                'difference_count' => count($data['differences']),
            ],
            actor: $request->user() instanceof User ? $request->user() : null,
            request: $request,
            useContextTenant: false,
        );

        return response()->json(['data' => $data]);
    }
}
