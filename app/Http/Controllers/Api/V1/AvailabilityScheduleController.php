<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ScheduleOwnerType;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AvailabilitySchedule;
use App\Models\Location;
use App\Models\Pool;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AvailabilityScheduleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AvailabilitySchedule::class);

        $rows = AvailabilitySchedule::query()->orderBy('weekday')->orderBy('opens_local')->get();

        return response()->json(['data' => $rows->map(fn (AvailabilitySchedule $row): array => $this->payload($row))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AvailabilitySchedule::class);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $validated = $request->validate([
            'owner_type' => ['required', Rule::enum(ScheduleOwnerType::class)],
            'owner_id' => ['required', 'string'],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'opens_local' => ['required', 'date_format:H:i'],
            'closes_local' => ['required', 'date_format:H:i'],
        ]);

        $ownerType = ScheduleOwnerType::from($validated['owner_type']);
        $this->assertOwner($tenant, $ownerType, $validated['owner_id']);

        $schedule = AvailabilitySchedule::query()->create([
            'tenant_id' => $tenant->id,
            'owner_type' => $ownerType,
            'owner_id' => $validated['owner_id'],
            'weekday' => $validated['weekday'],
            'opens_local' => $validated['opens_local'].':00',
            'closes_local' => $validated['closes_local'].':00',
        ]);

        $this->audit->log('schedule.created', $schedule, [
            'owner_type' => $ownerType->value,
            'weekday' => $schedule->weekday,
        ], request: $request);

        return response()->json(['data' => $this->payload($schedule)], 201);
    }

    public function destroy(Request $request, AvailabilitySchedule $availabilitySchedule): JsonResponse
    {
        $this->authorize('delete', $availabilitySchedule);
        $availabilitySchedule->delete();
        $this->audit->log('schedule.deleted', $availabilitySchedule, [], request: $request);

        return response()->json(status: 204);
    }

    private function assertOwner(Tenant $tenant, ScheduleOwnerType $type, string $id): void
    {
        $exists = match ($type) {
            ScheduleOwnerType::Location => Location::query()->whereKey($id)->where('tenant_id', $tenant->id)->exists(),
            ScheduleOwnerType::Asset => Asset::query()->whereKey($id)->where('tenant_id', $tenant->id)->exists(),
            ScheduleOwnerType::Pool => Pool::query()->whereKey($id)->where('tenant_id', $tenant->id)->exists(),
        };

        if (! $exists) {
            throw ValidationException::withMessages([
                'owner_id' => 'That location or resource is not in this workspace.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AvailabilitySchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'owner_type' => $schedule->owner_type->value,
            'owner_id' => $schedule->owner_id,
            'weekday' => $schedule->weekday,
            'opens_local' => substr($this->clock($schedule->opens_local), 0, 5),
            'closes_local' => substr($this->clock($schedule->closes_local), 0, 5),
        ];
    }

    private function clock(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        return (string) $value;
    }
}
