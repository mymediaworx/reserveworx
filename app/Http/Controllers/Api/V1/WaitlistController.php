<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WaitlistStatus;
use App\Http\Controllers\Controller;
use App\Models\Departure;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Services\AuditLogger;
use App\Services\Booking\Waitlist;
use App\Services\Booking\WaitlistMutationIdempotency;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WaitlistController extends Controller
{
    public function __construct(
        private readonly Waitlist $waitlist,
        private readonly AuditLogger $audit,
        private readonly WaitlistMutationIdempotency $mutations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WaitlistEntry::class);

        $entries = WaitlistEntry::query()->orderBy('starts_at')->limit(100)->get();

        return response()->json([
            'data' => $entries->map(fn (WaitlistEntry $entry): array => $this->payload($entry))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', WaitlistEntry::class);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $validated = $request->validate([
            'service_id' => ['required', 'string'],
            'variant_id' => ['nullable', 'string'],
            'departure_id' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'participants' => ['required', 'integer', 'min:1'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        $service = Service::query()->find($validated['service_id']);

        if (! $service instanceof Service || ! $service->waitlist_enabled) {
            throw ValidationException::withMessages([
                'service_id' => 'This service does not take a waitlist.',
            ]);
        }

        $startsAt = CarbonImmutable::parse($validated['starts_at'])->utc();
        $endsAt = CarbonImmutable::parse($validated['ends_at'])->utc();
        $departureId = $validated['departure_id'] ?? null;

        if (is_string($departureId) && $departureId !== '') {
            $departure = Departure::query()->find($departureId);

            if (! $departure instanceof Departure || $departure->service_id !== $service->id) {
                throw ValidationException::withMessages([
                    'departure_id' => 'That departure is not on this service.',
                ]);
            }

            $startsAt = CarbonImmutable::instance($departure->starts_at)->utc();
            $endsAt = CarbonImmutable::instance($departure->ends_at)->utc();
        } else {
            $departureId = null;
        }

        $entry = $this->waitlist->join(
            $tenant->id,
            $service->id,
            $departureId,
            $service->location_id,
            $startsAt,
            $endsAt,
            (int) $validated['participants'],
            $validated['customer_name'] ?? null,
            $validated['customer_email'] ?? null,
            $request->user()?->id,
            $validated['idempotency_key'] ?? null,
            $this->variantId($service, $validated['variant_id'] ?? null),
        );

        $this->audit->log('waitlist.joined', $entry, ['service_id' => $service->id], request: $request);

        return response()->json(['data' => $this->payload($entry)], 202);
    }

    public function cancel(Request $request, WaitlistEntry $waitlistEntry): JsonResponse
    {
        $this->authorize('update', $waitlistEntry);

        return $this->mutations->once($request, $waitlistEntry, 'cancel', [], function () use ($request, $waitlistEntry): JsonResponse {
            $waitlistEntry->status = WaitlistStatus::Cancelled;
            $waitlistEntry->save();
            $this->audit->log('waitlist.cancelled', $waitlistEntry, [], request: $request);

            return response()->json(['data' => $this->payload($waitlistEntry)]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(WaitlistEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'service_id' => $entry->service_id,
            'variant_id' => $entry->service_variant_id,
            'departure_id' => $entry->departure_id,
            'location_id' => $entry->location_id,
            'starts_at' => $entry->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $entry->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'participants' => $entry->participants,
            'status' => $entry->status->value,
        ];
    }

    private function variantId(Service $service, mixed $variantId): ?string
    {
        if (! is_string($variantId) || $variantId === '') {
            return null;
        }

        $variant = ServiceVariant::query()->where('service_id', $service->id)->find($variantId);

        if (! $variant instanceof ServiceVariant) {
            throw ValidationException::withMessages([
                'variant_id' => 'That variant is not on this service.',
            ]);
        }

        return $variant->id;
    }
}
