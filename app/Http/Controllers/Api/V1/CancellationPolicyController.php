<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Booking\CancellationPolicies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CancellationPolicyController extends Controller
{
    public function __construct(private readonly CancellationPolicies $policies) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('view', TenantSetting::query()->firstOrFail());

        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('update', TenantSetting::query()->firstOrFail());
        $validated = $request->validate([
            'customer_cancel' => ['present', 'array', 'max:8'],
            'customer_cancel.*.lead_minutes' => ['required', 'integer', 'between:0,525600'],
            'customer_cancel.*.refund_percent' => ['required', 'integer', 'between:0,100'],
            'no_show_refund_percent' => ['nullable', 'integer', 'between:0,100'],
        ]);
        $actor = $request->user();
        $tiers = [];

        foreach ($validated['customer_cancel'] as $tier) {
            $tiers[] = [
                'lead_minutes' => (int) $tier['lead_minutes'],
                'refund_percent' => (int) $tier['refund_percent'],
            ];
        }

        $this->policies->replace(
            $tiers,
            array_key_exists('no_show_refund_percent', $validated) && $validated['no_show_refund_percent'] !== null
                ? (int) $validated['no_show_refund_percent']
                : null,
            $actor instanceof User ? $actor : null,
        );

        return response()->json(['data' => $this->payload()]);
    }

    /**
     * @return array{customer_cancel: list<array{lead_minutes: int, refund_percent: int}>, no_show_refund_percent: ?int}
     */
    private function payload(): array
    {
        return [
            'customer_cancel' => $this->policies->customerTiers(),
            'no_show_refund_percent' => $this->policies->noShowPercent(),
        ];
    }
}
