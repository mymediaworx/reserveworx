<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fees\FeeChannel;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\PromoCode;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Catalog\BookingAddOnLines;
use App\Services\Pricing\BookingPricer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PricingController extends Controller
{
    public function __construct(
        private readonly BookingPricer $pricer,
        private readonly AuditLogger $audit,
        private readonly BookingAddOnLines $addOnLines,
    ) {}

    public function storeRule(Request $request, Service $service): JsonResponse
    {
        $this->authorize('update', $service);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', 'in:base,per_person,duration'],
            'amount_cents' => ['required', 'integer', 'min:0'],
            'window' => ['sometimes', 'in:always,weekday,weekend'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
        ]);

        $rule = PriceRule::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'amount_cents' => $validated['amount_cents'],
            'window' => $validated['window'] ?? 'always',
            'duration_minutes' => $validated['duration_minutes'] ?? null,
            'starts_on' => $validated['starts_on'] ?? null,
            'ends_on' => $validated['ends_on'] ?? null,
        ]);

        $this->audit->log('price_rule.created', $rule, ['service_id' => $service->id], request: $request);

        return response()->json(['data' => [
            'id' => $rule->id,
            'kind' => $rule->kind,
            'amount_cents' => $rule->amount_cents,
            'window' => $rule->window,
        ]], 201);
    }

    public function storePromo(Request $request): JsonResponse
    {
        $this->authorize('create', Service::class);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'percent_off' => ['nullable', 'integer', 'between:1,100'],
            'amount_off_cents' => ['nullable', 'integer', 'min:1'],
        ]);

        if (($validated['percent_off'] ?? null) === null && ($validated['amount_off_cents'] ?? null) === null) {
            throw ValidationException::withMessages(['code' => 'A promo needs a percent or an amount off.']);
        }

        $promo = PromoCode::query()->create([
            'tenant_id' => $tenant->id,
            'code' => strtoupper($validated['code']),
            'percent_off' => $validated['percent_off'] ?? null,
            'amount_off_cents' => $validated['amount_off_cents'] ?? null,
            'active' => true,
        ]);

        return response()->json(['data' => ['id' => $promo->id, 'code' => $promo->code]], 201);
    }

    public function priceBooking(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'channel' => ['sometimes', Rule::enum(FeeChannel::class)],
            'tax_basis_points' => ['sometimes', 'integer', 'between:0,10000'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'deposit_percent' => ['sometimes', 'integer', 'between:1,100'],
            'add_ons' => ['sometimes', 'array', 'max:20'],
            'add_ons.*.id' => ['required', 'string', 'distinct'],
            'add_ons.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $channel = isset($validated['channel']) && is_string($validated['channel'])
            ? FeeChannel::from($validated['channel'])
            : FeeChannel::Direct;
        $requestedAddOns = $validated['add_ons'] ?? [];

        if ($request->exists('add_ons') && is_array($requestedAddOns) && ! PricingSnapshot::query()->where('booking_id', $booking->id)->exists()) {
            $this->addOnLines->replace(
                $booking,
                $this->addOnLines->price($booking->participants, $requestedAddOns),
            );
        }

        try {
            $snapshot = $this->pricer->quote(
                $booking->load('location'),
                $tenant,
                $channel,
                (int) ($validated['tax_basis_points'] ?? 0),
                $validated['promo_code'] ?? null,
                (int) ($validated['deposit_percent'] ?? 100),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => [
            'id' => $snapshot->id,
            'currency' => $snapshot->currency,
            'rules_version' => $snapshot->rules_version,
            'disclosure_version_id' => $snapshot->disclosure_version_id,
            'payload' => $snapshot->payload,
        ]]);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }
}
