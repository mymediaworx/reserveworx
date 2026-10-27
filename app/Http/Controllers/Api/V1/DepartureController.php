<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DepartureStatus;
use App\Enums\ServiceKind;
use App\Http\Controllers\Controller;
use App\Models\Departure;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Booking\DepartureMutationIdempotency;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DepartureController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DepartureMutationIdempotency $mutations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Departure::class);
        $validated = $request->validate([
            'service_id' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $departures = Departure::query()
            ->with('service')
            ->when($validated['service_id'] ?? null, fn ($query, $id) => $query->where('service_id', $id))
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->where('ends_at', '>', CarbonImmutable::parse($from)->utc()))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->where('starts_at', '<', CarbonImmutable::parse($to)->utc()))
            ->orderBy('starts_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $departures->map(fn (Departure $departure): array => $this->payload($departure))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Departure::class);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $validated = $request->validate([
            'service_id' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'private' => ['nullable', 'boolean'],
            'seats' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        $service = Service::query()->find($validated['service_id']);

        if (! $service instanceof Service || $service->kind !== ServiceKind::Tour) {
            throw ValidationException::withMessages([
                'service_id' => 'Departures belong to a tour.',
            ]);
        }

        $startsAt = $validated['starts_at'];
        $endsAt = $validated['ends_at'];

        if (! is_string($startsAt) || ! is_string($endsAt)) {
            abort(422);
        }

        $seats = $validated['seats'] ?? null;
        $start = CarbonImmutable::parse($startsAt)->utc();
        $end = CarbonImmutable::parse($endsAt)->utc();
        $private = (bool) ($validated['private'] ?? false);
        $seatCount = is_numeric($seats) ? (int) $seats : null;
        $payload = [
            'service_id' => $service->id,
            'starts_at' => $start->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $end->format('Y-m-d\TH:i:s\Z'),
            'private' => $private,
            'seats' => $seatCount,
        ];

        return $this->mutations->once($request, $tenant, null, 'schedule', $payload, function () use ($request, $tenant, $service, $start, $end, $private, $seatCount): JsonResponse {
            $departure = Departure::query()->create([
                'tenant_id' => $tenant->id,
                'service_id' => $service->id,
                'location_id' => $service->location_id,
                'starts_at' => $start,
                'ends_at' => $end,
                'private' => $private,
                'seats' => $seatCount,
                'status' => DepartureStatus::Scheduled,
            ]);
            $departure->setRelation('service', $service);

            $this->audit->log('departure.scheduled', $departure, [
                'service_id' => $service->id,
                'starts_at' => $departure->starts_at->toIso8601String(),
            ], request: $request);

            return response()->json(['data' => $this->payload($departure)], 201);
        });
    }

    public function show(Request $request, Departure $departure): JsonResponse
    {
        $this->authorize('view', $departure);

        return response()->json(['data' => $this->payload($departure->load('service'))]);
    }

    public function cancel(Request $request, Departure $departure): JsonResponse
    {
        $this->authorize('update', $departure);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $this->mutations->once($request, $tenant, $departure, 'cancel', [], function () use ($request, $departure): JsonResponse {
            if ($departure->status === DepartureStatus::Cancelled) {
                return response()->json([
                    'message' => 'That departure is already cancelled.',
                ], 409);
            }

            if ($departure->headcount() > 0) {
                return response()->json([
                    'message' => 'Cancel the bookings on this departure first.',
                ], 409);
            }

            $departure->status = DepartureStatus::Cancelled;
            $departure->save();
            $this->audit->log('departure.cancelled', $departure, [], request: $request);

            return response()->json(['data' => $this->payload($departure->load('service'))]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Departure $departure): array
    {
        $minimum = $departure->service?->min_participants;
        $participants = $departure->headcount();

        return [
            'id' => $departure->id,
            'service_id' => $departure->service_id,
            'location_id' => $departure->location_id,
            'starts_at' => $departure->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $departure->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'private' => $departure->private,
            'seats' => $departure->seats,
            'status' => $departure->status->value,
            'participants' => $participants,
            'below_minimum' => $minimum !== null && $participants > 0 && $participants < $minimum,
        ];
    }
}
