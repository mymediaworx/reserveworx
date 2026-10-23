<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Tenant;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(
        private readonly Allocator $allocator,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $bookings = Booking::query()->orderBy('starts_at')->limit(100)->get();

        return response()->json(['data' => $bookings->map(fn (Booking $booking): array => $this->payload($booking))]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);

        return response()->json(['data' => $this->payload($booking->load(['holds', 'allocations', 'lineItems']))]);
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
        ]);

        $header = $request->headers->get('Idempotency-Key');
        $key = is_string($header) && $header !== '' ? $header : ($validated['idempotency_key'] ?? null);

        $intent = new AllocationIntent(
            $tenant->id,
            $validated['service_id'],
            CarbonImmutable::parse($validated['starts_at'])->utc(),
            CarbonImmutable::parse($validated['ends_at'])->utc(),
            (int) $validated['participants'],
            is_string($key) ? $key : null,
            $validated['customer_name'] ?? null,
            $validated['customer_email'] ?? null,
        );

        try {
            $booking = $this->allocator->hold($intent);
        } catch (AllocationUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (IdempotencyConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

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
        $this->audit->log('booking.cancelled', $cancelled, [], request: $request);

        return response()->json(['data' => $this->payload($cancelled)]);
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
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'data' => [
                'view' => $validated['view'],
                'starts_at' => $start->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $end->format('Y-m-d\TH:i:s\Z'),
                'bookings' => $bookings->map(fn (Booking $booking): array => $this->payload($booking))->all(),
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
            'location_id' => $booking->location_id,
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status->value,
            'operational_status' => $booking->operational_status->value,
            'starts_at' => $booking->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $booking->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'participants' => $booking->participants,
            'holds' => $booking->relationLoaded('holds') ? $booking->holds->count() : null,
            'allocations' => $booking->relationLoaded('allocations') ? $booking->allocations->count() : null,
        ];
    }
}
