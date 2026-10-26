<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OperationalStatus;
use App\Enums\ResourceStatus;
use App\Enums\ReturnCondition;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Membership;
use App\Models\Participant;
use App\Models\RentalCheckout;
use App\Models\RentalReturn;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use App\Services\Booking\BookingMutationIdempotency;
use App\Services\Booking\CancellationSettlement;
use App\Services\Booking\RentalExtensions;
use App\Services\Booking\Waitlist;
use App\Services\Booking\WaitlistPromoter;
use App\Services\Catalog\BookingAddOnLines;
use App\Services\Catalog\BookingOperatorFeeLines;
use App\Support\LateFee;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function __construct(
        private readonly Allocator $allocator,
        private readonly AuditLogger $audit,
        private readonly Waitlist $waitlist,
        private readonly WaitlistPromoter $promoter,
        private readonly BookingAddOnLines $addOnLines,
        private readonly BookingOperatorFeeLines $operatorFees,
        private readonly RentalExtensions $extensions,
        private readonly CancellationSettlement $settlement,
        private readonly BookingMutationIdempotency $mutations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $bookings = Booking::query()->with(['service', 'customer', 'addOns', 'operatorFees'])->withCount('namedParticipants')->orderBy('starts_at')->limit(100)->get();

        return response()->json(['data' => $bookings->map(fn (Booking $booking): array => $this->payload($booking))]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);

        return response()->json(['data' => $this->payload($booking->load(['holds', 'allocations', 'lineItems', 'service', 'namedParticipants']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Booking::class);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $validated = $request->validate([
            'service_id' => ['required', 'string'],
            'variant_id' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'participants' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:255'],
            'departure_id' => ['nullable', 'string'],
            'guide_user_id' => ['nullable', 'string'],
            'join_waitlist' => ['nullable', 'boolean'],
            'add_ons' => ['sometimes', 'array', 'max:20'],
            'add_ons.*.id' => ['required', 'string', 'distinct'],
            'add_ons.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $header = $request->headers->get('Idempotency-Key');
        $key = is_string($header) && $header !== '' ? $header : ($validated['idempotency_key'] ?? null);
        $startsAt = CarbonImmutable::parse($validated['starts_at'])->utc();
        $endsAt = CarbonImmutable::parse($validated['ends_at'])->utc();
        $departureId = $validated['departure_id'] ?? null;
        $locationId = null;

        if (is_string($departureId) && $departureId !== '') {
            $departure = Departure::query()->find($departureId);

            if (! $departure instanceof Departure || $departure->service_id !== $validated['service_id']) {
                throw ValidationException::withMessages([
                    'departure_id' => 'That departure is not on this service.',
                ]);
            }

            $startsAt = CarbonImmutable::instance($departure->starts_at)->utc();
            $endsAt = CarbonImmutable::instance($departure->ends_at)->utc();
            $locationId = $departure->location_id;
        } else {
            $departureId = null;
        }

        $guideUserId = $validated['guide_user_id'] ?? null;

        if (is_string($guideUserId) && $guideUserId !== '') {
            $activeGuide = Membership::query()
                ->where('user_id', $guideUserId)
                ->where('status', MembershipStatus::Active)
                ->whereHas('role', fn ($query) => $query->whereIn('slug', ['owner', 'manager', 'staff']))
                ->exists();

            if (! $activeGuide) {
                throw ValidationException::withMessages([
                    'guide_user_id' => 'That guide is not an active member of this workspace.',
                ]);
            }
        } else {
            $guideUserId = null;
        }

        $requestedAddOns = $validated['add_ons'] ?? [];
        $addOns = $request->exists('add_ons') && is_array($requestedAddOns)
            ? $this->addOnLines->price((int) $validated['participants'], $requestedAddOns)
            : [];
        $variantId = $this->variantId($validated['service_id'], $validated['variant_id'] ?? null);

        $intent = new AllocationIntent(
            $tenant->id,
            $validated['service_id'],
            $startsAt,
            $endsAt,
            (int) $validated['participants'],
            is_string($key) ? $key : null,
            $validated['customer_name'] ?? null,
            $validated['customer_email'] ?? null,
            is_string($departureId) ? $departureId : null,
            is_string($guideUserId) ? $guideUserId : null,
            $this->customerPhone($validated['customer_phone'] ?? null),
            $addOns,
            $variantId,
        );

        try {
            $booking = $this->allocator->hold($intent);
        } catch (AllocationUnavailableException $exception) {
            if ($request->boolean('join_waitlist')) {
                $service = Service::query()->find($validated['service_id']);

                if ($service instanceof Service && $service->waitlist_enabled) {
                    $entry = $this->waitlist->join(
                        $tenant->id,
                        $service->id,
                        is_string($departureId) ? $departureId : null,
                        $locationId ?? $service->location_id,
                        $startsAt,
                        $endsAt,
                        (int) $validated['participants'],
                        $validated['customer_name'] ?? null,
                        $validated['customer_email'] ?? null,
                        $request->user()?->id,
                        is_string($key) ? $key : null,
                        $variantId,
                    );

                    return response()->json(['data' => [
                        'id' => $entry->id,
                        'service_id' => $entry->service_id,
                        'departure_id' => $entry->departure_id,
                        'status' => $entry->status->value,
                        'participants' => $entry->participants,
                    ]], 202);
                }
            }

            $payload = ['message' => $exception->getMessage()];

            if ($exception->offers !== []) {
                $payload['offers'] = $exception->offers;
            }

            return response()->json($payload, 409);
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $user = $request->user();

        if ($booking->created_by === null && $user !== null) {
            $booking->created_by = $user->id;
            $booking->save();
        }

        $booking->load(['service', 'addOns', 'operatorFees']);

        $this->audit->log('booking.held', $booking, [
            'service_id' => $booking->service_id,
            'starts_at' => $booking->starts_at->toIso8601String(),
        ], request: $request);

        return response()->json(['data' => $this->payload($booking)], 201);
    }

    public function confirm(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);

        return $this->mutations->once($request, $booking, 'confirm', [], function () use ($request, $booking): JsonResponse {
            try {
                $confirmed = $this->allocator->confirm($booking);
            } catch (AllocationUnavailableException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            $action = $confirmed->status->value === 'payment_exception' ? 'booking.payment_exception' : 'booking.confirmed';
            $this->audit->log($action, $confirmed, [], request: $request);

            $status = $confirmed->status->value === 'payment_exception' ? 409 : 200;

            return response()->json([
                'message' => $status === 409 ? 'That time is no longer available.' : 'Confirmed.',
                'data' => $this->payload($confirmed),
            ], $status);
        });
    }

    public function extend(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'ends_at' => ['required', 'date'],
            'asset_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);
        $back = url('/staff/bookings/'.$booking->id);
        $assetId = $validated['asset_id'] ?? null;
        $assetId = is_string($assetId) && $assetId !== '' ? $assetId : null;

        return $this->mutations->once($request, $booking, 'extend', [
            'ends_at' => $validated['ends_at'],
            'asset_id' => $assetId,
        ], function () use ($request, $booking, $validated, $back, $assetId): JsonResponse {
            try {
                $result = $this->extensions->request(
                    $booking,
                    CarbonImmutable::parse($validated['ends_at'])->utc(),
                    $assetId,
                    $back.'?paid=1',
                    $back,
                    false,
                    $request,
                );
            } catch (AllocationUnavailableException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            if ($result['offers'] !== []) {
                return response()->json([
                    'message' => 'That time is no longer available.',
                    'offers' => $result['offers'],
                    'data' => $this->payload($result['booking']),
                ], 409);
            }

            $this->audit->log('booking.extended', $result['booking'], [], request: $request);
            $body = ['data' => $this->payload($result['booking'])];

            if ($result['checkout'] !== null) {
                $body['checkout'] = [
                    'payment_id' => $result['checkout']['payment_id'],
                    'checkout_url' => $result['checkout']['url'],
                    'amount_cents' => $result['checkout']['amount_cents'],
                    'fee_cents' => $result['checkout']['fee_cents'],
                ];
            }

            if ($result['charge_message'] !== null) {
                $body['charge_message'] = $result['charge_message'];
            }

            return response()->json($body);
        });
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);

        return $this->mutations->once($request, $booking, 'cancel', [], function () use ($request, $booking): JsonResponse {
            $cancelled = $this->allocator->cancel($booking);
            $this->promoter->afterRelease($cancelled);
            $this->audit->log('booking.cancelled', $cancelled, [], request: $request);

            return response()->json(['data' => $this->payload($cancelled)]);
        });
    }

    public function noShow(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);

        return $this->mutations->once($request, $booking, 'no_show', [], function () use ($request, $booking): JsonResponse {
            $actor = $request->user();
            $settlement = $this->settlement->refundNoShow($booking, $actor instanceof User ? $actor : null);

            if ($settlement['problem'] !== null) {
                return response()->json(['message' => 'That booking was not marked as a no-show: '.$settlement['problem']], 409);
            }

            try {
                $saved = $this->allocator->noShow($booking);
            } catch (AllocationUnavailableException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            $this->promoter->afterRelease($saved);
            $this->audit->log('booking.no_show', $saved, [], request: $request);

            return response()->json(['data' => $this->payload($saved)]);
        });
    }

    public function checkout(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);

        return $this->mutations->once($request, $booking, 'rental_checkout', [], function () use ($request, $booking): JsonResponse {
            try {
                $saved = $this->allocator->checkout($booking);
            } catch (AllocationUnavailableException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            $this->audit->log('booking.checked_out', $saved, [], request: $request);

            return response()->json(['data' => $this->payload($saved)]);
        });
    }

    public function returnRental(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $conditions = $this->conditions($request);

        return $this->mutations->once($request, $booking, 'return', [
            'conditions' => $conditions,
        ], function () use ($request, $booking, $conditions): JsonResponse {
            try {
                $result = $this->allocator->returnRental($booking, $conditions);
            } catch (AllocationUnavailableException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            if ($result['released_start'] instanceof CarbonImmutable && $result['released_end'] instanceof CarbonImmutable) {
                $this->promoter->afterRelease($result['booking'], $result['released_start'], $result['released_end']);
            }

            $this->recordLateFee($result['booking']);

            $this->audit->log('booking.returned', $result['booking'], [
                'conditions' => $result['conditions'],
            ], request: $request);

            foreach ($result['maintained_asset_ids'] as $assetId) {
                $asset = Asset::query()->find($assetId);

                if ($asset instanceof Asset) {
                    $this->audit->log('asset.updated', $asset, [
                        'status' => ResourceStatus::Maintenance->value,
                    ], request: $request);
                }
            }

            $data = $this->payload($result['booking']);
            $data['returns'] = $this->returnRecords($result['booking']);

            return response()->json(['data' => $data]);
        });
    }

    public function indexReturns(Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);

        return response()->json(['data' => $this->returnRecords($booking)]);
    }

    public function update(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'operational_status' => ['required', Rule::enum(OperationalStatus::class)],
        ]);
        $status = $validated['operational_status'];

        if (! is_string($status)) {
            abort(422);
        }

        return $this->mutations->once($request, $booking, 'operational_status', [
            'operational_status' => $status,
        ], function () use ($request, $booking, $status): JsonResponse {
            $booking->operational_status = OperationalStatus::from($status);
            $booking->save();
            $this->audit->log('booking.operational_status', $booking, [
                'operational_status' => $booking->operational_status->value,
            ], request: $request);

            return response()->json(['data' => $this->payload($booking->load('service'))]);
        });
    }

    public function reschedule(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'departure_id' => ['sometimes', 'nullable', 'string'],
            'starts_at' => ['required_without:departure_id', 'date'],
            'ends_at' => ['required_without:departure_id', 'date', 'after:starts_at'],
        ]);
        $departureId = $validated['departure_id'] ?? null;

        if (is_string($departureId) && $departureId !== '') {
            return $this->mutations->once($request, $booking, 'reschedule', [
                'departure_id' => $departureId,
            ], function () use ($request, $booking, $departureId): JsonResponse {
                $from = $booking->departure_id;

                try {
                    $saved = $this->allocator->moveToDeparture($booking, $departureId);
                } catch (AllocationUnavailableException $exception) {
                    return response()->json([
                        'message' => $exception->getMessage(),
                        'offers' => $exception->offers,
                    ], 409);
                }

                $this->audit->log('booking.rescheduled', $saved, [
                    'from_departure_id' => $from,
                    'to_departure_id' => $saved->departure_id,
                ], request: $request);

                return response()->json(['data' => $this->payload($saved)]);
            });
        }

        return $this->mutations->once($request, $booking, 'reschedule', [
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
        ], function () use ($request, $booking, $validated): JsonResponse {
            try {
                $saved = $this->allocator->reschedule(
                    $booking,
                    CarbonImmutable::parse($validated['starts_at'])->utc(),
                    CarbonImmutable::parse($validated['ends_at'])->utc(),
                );
            } catch (AllocationUnavailableException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'offers' => $exception->offers,
                ], 409);
            }

            $this->audit->log('booking.rescheduled', $saved, [], request: $request);

            return response()->json(['data' => $this->payload($saved)]);
        });
    }

    public function reassign(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'from_asset_id' => ['required', 'string'],
            'to_asset_id' => ['required', 'string'],
        ]);

        return $this->mutations->once($request, $booking, 'reassign', [
            'from_asset_id' => $validated['from_asset_id'],
            'to_asset_id' => $validated['to_asset_id'],
        ], function () use ($request, $booking, $validated): JsonResponse {
            try {
                $saved = $this->allocator->reassignAsset($booking, $validated['from_asset_id'], $validated['to_asset_id']);
            } catch (AllocationUnavailableException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'offers' => $exception->offers,
                ], 409);
            }

            $this->audit->log('booking.reassigned', $saved, [
                'from_asset_id' => $validated['from_asset_id'],
                'to_asset_id' => $validated['to_asset_id'],
            ], request: $request);

            return response()->json(['data' => $this->payload($saved)]);
        });
    }

    public function partySize(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'participants' => ['required', 'integer', 'min:1'],
        ]);
        $previous = $booking->participants;

        return $this->mutations->once($request, $booking, 'party_size', [
            'participants' => (int) $validated['participants'],
        ], function () use ($request, $booking, $validated, $previous): JsonResponse {
            try {
                $saved = $this->allocator->changePartySize($booking, (int) $validated['participants']);
            } catch (AllocationUnavailableException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'offers' => $exception->offers,
                ], 409);
            }

            $this->audit->log('booking.party_size_changed', $saved, [
                'from' => $previous,
                'to' => $saved->participants,
            ], request: $request);

            return response()->json(['data' => $this->payload($saved)]);
        });
    }

    public function calendar(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);
        $validated = $request->validate([
            'view' => ['required', 'in:day,week,month,agenda'],
            'on' => ['required', 'date'],
            'location_id' => ['nullable', 'string'],
            'service_id' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'asset_id' => ['nullable', 'string'],
            'pool_id' => ['nullable', 'string'],
            'staff_user_id' => ['nullable', 'string'],
        ]);

        $view = $validated['view'];

        if (! is_string($view)) {
            abort(422);
        }

        $on = CarbonImmutable::parse($validated['on'])->utc()->startOfDay();
        $end = match ($view) {
            'day' => $on->addDay(),
            'week', 'agenda' => $on->addDays(7),
            'month' => $on->startOfMonth()->addMonth(),
            default => abort(422),
        };
        $start = $view === 'month' ? $on->startOfMonth() : $on;

        $bookings = Booking::query()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($validated['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($validated['service_id'] ?? null, fn ($query, $id) => $query->where('service_id', $id))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['staff_user_id'] ?? null, fn ($query, $id) => $query->where('created_by', $id))
            ->when($validated['asset_id'] ?? null, function ($query, string $id): void {
                $query->where(function ($inner) use ($id): void {
                    $inner->whereHas('allocations', fn ($rows) => $rows->where('asset_id', $id))
                        ->orWhereHas('holds', fn ($rows) => $rows->where('asset_id', $id));
                });
            })
            ->when($validated['pool_id'] ?? null, function ($query, string $id): void {
                $query->where(function ($inner) use ($id): void {
                    $inner->whereHas('allocations', fn ($rows) => $rows->where('pool_id', $id))
                        ->orWhereHas('holds', fn ($rows) => $rows->where('pool_id', $id));
                });
            })
            ->with(['service', 'customer', 'operatorFees'])
            ->withCount('namedParticipants')
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'data' => [
                'view' => $validated['view'],
                'starts_at' => $start->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $end->format('Y-m-d\TH:i:s\Z'),
                'bookings' => $bookings->map(fn (Booking $booking): array => $this->payload($booking))->all(),
                'departures' => Departure::query()
                    ->with('service')
                    ->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start)
                    ->when($validated['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
                    ->when($validated['service_id'] ?? null, fn ($query, $id) => $query->where('service_id', $id))
                    ->orderBy('starts_at')
                    ->get()
                    ->map(function (Departure $departure): array {
                        $participants = $departure->headcount();
                        $minimum = $departure->service?->min_participants;

                        return [
                            'id' => $departure->id,
                            'service_id' => $departure->service_id,
                            'location_id' => $departure->location_id,
                            'starts_at' => $departure->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
                            'ends_at' => $departure->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
                            'status' => $departure->status->value,
                            'private' => $departure->private,
                            'participants' => $participants,
                            'below_minimum' => $minimum !== null && $participants > 0 && $participants < $minimum,
                        ];
                    })->all(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Booking $booking): array
    {
        $booking->loadMissing('customer');

        return [
            'id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'customer' => $this->customerPayload($booking),
            'service_id' => $booking->service_id,
            'variant_id' => $booking->service_variant_id,
            'departure_id' => $booking->departure_id,
            'location_id' => $booking->location_id,
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status->value,
            'operational_status' => $booking->operational_status->value,
            'starts_at' => $booking->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $booking->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'participants' => $booking->participants,
            'named_participant_count' => $this->namedParticipantCount($booking),
            'named_participants' => $this->namedParticipantPayload($booking),
            'created_by' => $booking->created_by,
            'guide_user_id' => $booking->guide_user_id,
            'attention_reason' => $booking->attention_reason,
            'below_minimum' => $this->belowMinimum($booking),
            'holds' => $booking->relationLoaded('holds') ? $booking->holds->count() : null,
            'allocations' => $booking->relationLoaded('allocations') ? $booking->allocations->count() : null,
            'add_ons' => $this->addOnPayload($booking),
            'operator_fees' => $this->operatorFeePayload($booking),
        ];
    }

    /**
     * @return list<array{id: string, operator_fee_id: string, name: string, scope: string, pricing: string, amount_cents: int|null, percent_bps: int|null, quantity: int, total_cents: int}>
     */
    private function operatorFeePayload(Booking $booking): array
    {
        $booking->loadMissing('operatorFees');
        $rows = [];

        foreach ($booking->operatorFees as $line) {
            $rows[] = $this->operatorFees->row($line);
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, add_on_id: string, name: string, kind: string, pricing: string, unit_amount_cents: int, quantity: int, total_cents: int}>
     */
    private function addOnPayload(Booking $booking): array
    {
        $booking->loadMissing('addOns');
        $rows = [];

        foreach ($booking->addOns as $line) {
            $rows[] = $this->addOnLines->row($line);
        }

        return $rows;
    }

    private function variantId(string $serviceId, mixed $variantId): ?string
    {
        if (! is_string($variantId) || $variantId === '') {
            return null;
        }

        $variant = ServiceVariant::query()->where('service_id', $serviceId)->find($variantId);

        if (! $variant instanceof ServiceVariant) {
            throw ValidationException::withMessages([
                'variant_id' => 'That variant is not on this service.',
            ]);
        }

        return $variant->id;
    }

    private function customerPhone(mixed $phone): ?string
    {
        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /**
     * @return array{id: string, name: string, email: string|null, phone: string|null}|null
     */
    private function customerPayload(Booking $booking): ?array
    {
        $customer = $booking->customer;

        if (! $customer instanceof Customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ];
    }

    /**
     * @return list<array{asset_id: string, condition: string, notes: ?string, recorded_by: string}>|null
     */
    private function conditions(Request $request): ?array
    {
        if (! $request->exists('conditions')) {
            return null;
        }

        $validated = $request->validate([
            'conditions' => ['present', 'array'],
            'conditions.*.asset_id' => ['required', 'string'],
            'conditions.*.condition' => ['required', 'string', Rule::in(ReturnCondition::apiValues())],
            'conditions.*.notes' => ['nullable', 'string', 'max:500'],
        ], [
            'conditions.*.condition.in' => 'Condition must be ok, damaged, or missing.',
        ]);
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $rows = [];
        $seen = [];

        foreach ($validated['conditions'] as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'conditions' => 'Record a condition for every asset on this rental.',
                ]);
            }

            $assetId = $row['asset_id'] ?? null;
            $condition = $row['condition'] ?? null;

            if (! is_string($assetId) || $assetId === '' || ! is_string($condition)) {
                throw ValidationException::withMessages([
                    'conditions' => 'Record a condition for every asset on this rental.',
                ]);
            }

            if (isset($seen[$assetId])) {
                throw ValidationException::withMessages([
                    'conditions' => 'Each asset can be recorded once.',
                ]);
            }

            $seen[$assetId] = true;
            $notes = $row['notes'] ?? null;
            $rows[] = [
                'asset_id' => $assetId,
                'condition' => $condition,
                'notes' => is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
                'recorded_by' => $user->id,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, booking_id: string, rental_checkout_id: string, asset_id: string|null, condition: string, notes: ?string, minutes_late: ?int, recorded_by: ?string, created_at: ?string}>
     */
    private function returnRecords(Booking $booking): array
    {
        $rows = [];

        foreach (RentalReturn::query()->where('booking_id', $booking->id)->orderBy('id')->get() as $row) {
            $rows[] = [
                'id' => $row->id,
                'booking_id' => $row->booking_id,
                'rental_checkout_id' => $row->rental_checkout_id,
                'asset_id' => $row->asset_id,
                'condition' => $row->condition->value,
                'notes' => $row->notes,
                'minutes_late' => $row->minutes_late,
                'recorded_by' => $row->recorded_by,
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    private function recordLateFee(Booking $booking): void
    {
        $setting = TenantSetting::query()->where('tenant_id', $booking->tenant_id)->first();
        $rate = $setting instanceof TenantSetting ? $setting->late_fee_per_minute_cents : null;
        $grace = LateFee::graceMinutes($setting instanceof TenantSetting ? $setting : null);

        if ($rate === null || $rate < 1) {
            return;
        }

        $checkouts = RentalCheckout::query()->where('booking_id', $booking->id)->whereNotNull('returned_at')->get();

        foreach ($checkouts as $checkout) {
            $returnedAt = $checkout->returned_at;

            if ($returnedAt === null) {
                continue;
            }

            $amount = LateFee::amountCents(LateFee::billableMinutes($checkout->due_at, $returnedAt, $grace), $rate);

            if ($amount < 1) {
                continue;
            }

            $checkout->late_fee_cents = $amount;
            $checkout->save();
        }
    }

    private function namedParticipantCount(Booking $booking): int
    {
        if ($booking->relationLoaded('namedParticipants')) {
            return $booking->namedParticipants->count();
        }

        $stored = $booking->getAttribute('named_participants_count');

        return is_numeric($stored) ? (int) $stored : $booking->namedParticipants()->count();
    }

    /**
     * @return list<array{id: string, name: string}>|null
     */
    private function namedParticipantPayload(Booking $booking): ?array
    {
        if (! $booking->relationLoaded('namedParticipants')) {
            return null;
        }

        return $booking->namedParticipants->map(fn (Participant $person): array => [
            'id' => $person->id,
            'name' => $person->name,
        ])->values()->all();
    }

    private function belowMinimum(Booking $booking): bool
    {
        $minimum = $booking->service?->min_participants;

        return $minimum !== null && $booking->participants < $minimum;
    }
}
