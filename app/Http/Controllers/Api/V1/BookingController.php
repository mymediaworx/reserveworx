<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OperationalStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Membership;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use App\Services\Booking\Waitlist;
use App\Services\Booking\WaitlistPromoter;
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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $bookings = Booking::query()->with('service')->orderBy('starts_at')->limit(100)->get();

        return response()->json(['data' => $bookings->map(fn (Booking $booking): array => $this->payload($booking))]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);

        return response()->json(['data' => $this->payload($booking->load(['holds', 'allocations', 'lineItems', 'service']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Booking::class);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $validated = $request->validate([
            'service_id' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'participants' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'departure_id' => ['nullable', 'string'],
            'guide_user_id' => ['nullable', 'string'],
            'join_waitlist' => ['nullable', 'boolean'],
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

            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $user = $request->user();

        if ($booking->created_by === null && $user !== null) {
            $booking->created_by = $user->id;
            $booking->save();
        }

        $booking->load('service');

        $this->audit->log('booking.held', $booking, [
            'service_id' => $booking->service_id,
            'starts_at' => $booking->starts_at->toIso8601String(),
        ], request: $request);

        return response()->json(['data' => $this->payload($booking)], 201);
    }

    public function confirm(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);

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
    }

    public function extend(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'ends_at' => ['required', 'date'],
        ]);

        try {
            $result = $this->allocator->extend($booking, CarbonImmutable::parse($validated['ends_at'])->utc());
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

        return response()->json(['data' => $this->payload($result['booking'])]);
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $cancelled = $this->allocator->cancel($booking);
        $this->promoter->afterRelease($cancelled);
        $this->audit->log('booking.cancelled', $cancelled, [], request: $request);

        return response()->json(['data' => $this->payload($cancelled)]);
    }

    public function noShow(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);

        try {
            $saved = $this->allocator->noShow($booking);
        } catch (AllocationUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $this->promoter->afterRelease($saved);
        $this->audit->log('booking.no_show', $saved, [], request: $request);

        return response()->json(['data' => $this->payload($saved)]);
    }

    public function checkout(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);

        try {
            $saved = $this->allocator->checkout($booking);
        } catch (AllocationUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $this->audit->log('booking.checked_out', $saved, [], request: $request);

        return response()->json(['data' => $this->payload($saved)]);
    }

    public function returnRental(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);

        try {
            $result = $this->allocator->returnRental($booking);
        } catch (AllocationUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($result['released_start'] instanceof CarbonImmutable && $result['released_end'] instanceof CarbonImmutable) {
            $this->promoter->afterRelease($result['booking'], $result['released_start'], $result['released_end']);
        }

        $this->audit->log('booking.returned', $result['booking'], [], request: $request);

        return response()->json(['data' => $this->payload($result['booking'])]);
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

        $booking->operational_status = OperationalStatus::from($status);
        $booking->save();
        $this->audit->log('booking.operational_status', $booking, [
            'operational_status' => $booking->operational_status->value,
        ], request: $request);

        return response()->json(['data' => $this->payload($booking->load('service'))]);
    }

    public function reschedule(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

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
    }

    public function reassign(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $validated = $request->validate([
            'from_asset_id' => ['required', 'string'],
            'to_asset_id' => ['required', 'string'],
        ]);

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
            ->with('service')
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
        return [
            'id' => $booking->id,
            'service_id' => $booking->service_id,
            'departure_id' => $booking->departure_id,
            'location_id' => $booking->location_id,
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status->value,
            'operational_status' => $booking->operational_status->value,
            'starts_at' => $booking->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $booking->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'participants' => $booking->participants,
            'created_by' => $booking->created_by,
            'guide_user_id' => $booking->guide_user_id,
            'below_minimum' => $this->belowMinimum($booking),
            'holds' => $booking->relationLoaded('holds') ? $booking->holds->count() : null,
            'allocations' => $booking->relationLoaded('allocations') ? $booking->allocations->count() : null,
        ];
    }

    private function belowMinimum(Booking $booking): bool
    {
        $minimum = $booking->service?->min_participants;

        return $minimum !== null && $booking->participants < $minimum;
    }
}
