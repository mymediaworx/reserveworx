<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MaintenanceKind;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Incident;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceWindow;
use App\Models\Participant;
use App\Models\Pool;
use App\Models\Tenant;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\AuditLogger;
use App\Services\Booking\BookingMutationIdempotency;
use App\Services\Operations\MaintenancePlanner;
use App\Services\Operations\MaintenanceWindowAlreadyEndedException;
use App\Services\Operations\WaiverPdf;
use App\Services\Waivers\WaiverSigner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsController extends Controller
{
    public function __construct(
        private readonly MaintenancePlanner $maintenance,
        private readonly WaiverSigner $waivers,
        private readonly AuditLogger $audit,
        private readonly BookingMutationIdempotency $mutations,
    ) {}

    public function indexMaintenance(): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);
        $rows = [];

        foreach (MaintenanceWindow::query()->orderBy('starts_at')->orderBy('id')->get() as $window) {
            $rows[] = [
                'id' => $window->id,
                'asset_id' => $window->asset_id,
                'pool_id' => $window->pool_id,
                'kind' => $window->kind->value,
                'starts_at' => $window->starts_at->toIso8601String(),
                'ends_at' => $window->ends_at->toIso8601String(),
                'ended_at' => $window->ended_at?->toIso8601String(),
                'reason' => $window->reason,
            ];
        }

        return response()->json(['data' => $rows]);
    }

    public function storeMaintenance(Request $request): JsonResponse
    {
        $this->authorize('create', Asset::class);
        $this->tenant($request);
        $validated = $request->validate([
            'asset_id' => ['nullable', 'string'],
            'pool_id' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:160'],
            'kind' => ['nullable', Rule::enum(MaintenanceKind::class)],
        ]);
        $assetId = $this->ownedId(Asset::class, $validated['asset_id'] ?? null);
        $poolId = $this->ownedId(Pool::class, $validated['pool_id'] ?? null);

        if (($assetId === null && $poolId === null) || ($assetId !== null && $poolId !== null)) {
            throw ValidationException::withMessages(['asset_id' => 'Choose one asset or one pool.']);
        }

        $resource = $assetId !== null ? Asset::query()->findOrFail($assetId) : Pool::query()->findOrFail($poolId);
        $this->authorize('update', $resource);
        $scheduled = $this->maintenance->schedule(
            $resource,
            MaintenanceKind::tryFrom((string) ($validated['kind'] ?? '')) ?? MaintenanceKind::Maintenance,
            CarbonImmutable::parse($validated['starts_at'])->utc(),
            CarbonImmutable::parse($validated['ends_at'])->utc(),
            $validated['reason'],
        );

        return response()->json(['data' => [
            'id' => $scheduled['window']->id,
            'kind' => $scheduled['window']->kind->value,
            'flagged_bookings' => $scheduled['flagged'],
        ]], 201);
    }

    public function endMaintenance(Request $request, MaintenanceWindow $maintenance_window): JsonResponse
    {
        $resource = $this->maintenanceResource($maintenance_window);
        $this->authorize('update', $resource);

        try {
            $ended = $this->maintenance->end($maintenance_window);
        } catch (MaintenanceWindowAlreadyEndedException $exception) {
            throw ValidationException::withMessages(['window' => $exception->getMessage()]);
        }

        $window = $ended['window'];
        $this->audit->log('maintenance.ended', $window, [
            'kind' => $window->kind->value,
            'asset_id' => $window->asset_id,
            'pool_id' => $window->pool_id,
            'ends_at' => $window->ends_at->toIso8601String(),
            'ended_at' => $window->ended_at?->toIso8601String(),
            'cleared_bookings' => $ended['cleared'],
        ], request: $request);

        return response()->json(['data' => [
            'id' => $window->id,
            'ends_at' => $window->ends_at->toIso8601String(),
            'ended_at' => $window->ended_at?->toIso8601String(),
            'cleared_bookings' => $ended['cleared'],
        ]]);
    }

    public function indexMaintenanceRecords(Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);
        $rows = [];

        foreach (MaintenanceRecord::query()->where('asset_id', $asset->id)->orderBy('inspected_at')->orderBy('id')->get() as $record) {
            $rows[] = $this->maintenanceRecordPayload($record);
        }

        return response()->json(['data' => $rows]);
    }

    public function storeMaintenanceRecord(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('create', Asset::class);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'inspected_at' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:500'],
        ]);
        $record = MaintenanceRecord::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $asset->id,
            'pool_id' => null,
            'inspected_at' => CarbonImmutable::parse($validated['inspected_at'])->utc(),
            'summary' => $validated['summary'],
        ]);

        return response()->json(['data' => $this->maintenanceRecordPayload($record)], 201);
    }

    public function indexPoolMaintenanceRecords(Pool $pool): JsonResponse
    {
        $this->authorize('view', $pool);
        $rows = [];

        foreach (MaintenanceRecord::query()->where('pool_id', $pool->id)->orderBy('inspected_at')->orderBy('id')->get() as $record) {
            $rows[] = $this->maintenanceRecordPayload($record);
        }

        return response()->json(['data' => $rows]);
    }

    public function storePoolMaintenanceRecord(Request $request, Pool $pool): JsonResponse
    {
        $this->authorize('create', Pool::class);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'inspected_at' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:500'],
        ]);
        $record = MaintenanceRecord::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => null,
            'pool_id' => $pool->id,
            'inspected_at' => CarbonImmutable::parse($validated['inspected_at'])->utc(),
            'summary' => $validated['summary'],
        ]);

        return response()->json(['data' => $this->maintenanceRecordPayload($record)], 201);
    }

    public function indexIncidents(Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);
        $rows = [];

        foreach (Incident::query()->where('booking_id', $booking->id)->orderBy('occurred_at')->orderBy('id')->get() as $incident) {
            $rows[] = [
                'id' => $incident->id,
                'booking_id' => $incident->booking_id,
                'asset_id' => $incident->asset_id,
                'summary' => $incident->summary,
                'occurred_at' => $incident->occurred_at->toIso8601String(),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    public function storeIncident(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'summary' => ['required', 'string', 'max:500'],
            'occurred_at' => ['required', 'date'],
            'asset_id' => ['nullable', 'string'],
        ]);
        $assetId = $this->ownedId(Asset::class, $validated['asset_id'] ?? null);
        $occurredAt = CarbonImmutable::parse($validated['occurred_at'])->utc();

        return $this->mutations->once($request, $booking, 'incident', [
            'summary' => $validated['summary'],
            'occurred_at' => $occurredAt->toIso8601String(),
            'asset_id' => $assetId,
        ], function () use ($tenant, $booking, $validated, $assetId, $occurredAt): JsonResponse {
            $incident = Incident::query()->create([
                'tenant_id' => $tenant->id,
                'booking_id' => $booking->id,
                'asset_id' => $assetId,
                'summary' => $validated['summary'],
                'occurred_at' => $occurredAt,
            ]);

            return response()->json(['data' => ['id' => $incident->id]], 201);
        });
    }

    public function storeWaiver(Request $request): JsonResponse
    {
        $this->authorize('create', Asset::class);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:20000'],
        ]);
        $row = $this->waivers->publish($tenant->id, $validated['name'], $validated['body']);

        return response()->json(['data' => [
            'id' => $row->id,
            'version' => $row->version,
            'content_hash' => $row->content_hash,
        ]], 201);
    }

    public function signWaiver(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('update', $booking);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'waiver_version_id' => ['required', 'string'],
            'participant_id' => ['nullable', 'string'],
            'participant_name' => ['required_without:participant_id', 'nullable', 'string', 'max:120'],
            'is_minor' => ['required_without:participant_id', 'boolean'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
        ]);
        $waiver = WaiverVersion::query()->find($validated['waiver_version_id']);

        if (! $waiver instanceof WaiverVersion) {
            throw ValidationException::withMessages(['waiver_version_id' => 'That waiver version was not found.']);
        }

        $participant = $this->participantOnBooking($booking, $validated['participant_id'] ?? null);

        if ($participant instanceof Participant) {
            $name = $participant->name;
            $minor = $participant->is_minor;
            $guardian = $participant->guardian_name;
        } else {
            $name = $validated['participant_name'] ?? null;
            $minor = (bool) ($validated['is_minor'] ?? false);
            $guardian = $validated['guardian_name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                throw ValidationException::withMessages(['participant_name' => 'A participant needs a name.']);
            }

            $name = trim($name);

            if ($minor) {
                if (! is_string($guardian) || trim($guardian) === '') {
                    throw ValidationException::withMessages(['guardian_name' => 'A minor needs a parent or guardian signature.']);
                }

                $guardian = trim($guardian);
            } else {
                $guardian = null;
            }
        }

        return $this->mutations->once($request, $booking, 'sign_waiver', [
            'waiver_version_id' => $waiver->id,
            'participant_id' => $participant?->id,
            'participant_name' => $name,
            'is_minor' => $minor,
            'guardian_name' => $guardian,
        ], function () use ($request, $booking, $waiver, $participant, $name, $minor, $guardian): JsonResponse {
            $signature = $this->waivers->sign(
                $booking,
                $waiver,
                $participant,
                $name,
                $minor,
                $guardian,
                $request->ip(),
                $request->userAgent(),
            );

            return response()->json(
                ['data' => $this->signaturePayload($signature)],
                $signature->wasRecentlyCreated ? 201 : 200,
            );
        });
    }

    public function waiverPdf(Booking $booking, string $signature, WaiverPdf $pdf): Response
    {
        $this->authorize('view', $booking);
        $row = WaiverSignature::query()->where('booking_id', $booking->id)->whereKey($signature)->first();

        if (! $row instanceof WaiverSignature) {
            abort(404);
        }

        $version = WaiverVersion::query()->find($row->waiver_version_id);

        if (! $version instanceof WaiverVersion) {
            abort(404);
        }

        return response($pdf->render($row, $version), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="waiver-'.$row->id.'.pdf"',
        ]);
    }

    private function participantOnBooking(Booking $booking, mixed $participantId): ?Participant
    {
        if (! is_string($participantId) || $participantId === '') {
            return null;
        }

        $participant = Participant::query()->where('booking_id', $booking->id)->whereKey($participantId)->first();

        if (! $participant instanceof Participant) {
            throw ValidationException::withMessages(['participant_id' => 'That participant was not found on this booking.']);
        }

        return $participant;
    }

    /**
     * @return array{id: string, participant_id: string|null, content_hash: string, guardian_name: string|null, participant_name: string}
     */
    private function signaturePayload(WaiverSignature $signature): array
    {
        $participantId = $signature->getAttribute('participant_id');
        $guardian = $signature->getAttribute('guardian_name');

        return [
            'id' => $signature->id,
            'participant_id' => is_string($participantId) ? $participantId : null,
            'participant_name' => (string) $signature->participant_name,
            'content_hash' => (string) $signature->content_hash,
            'guardian_name' => is_string($guardian) ? $guardian : null,
        ];
    }

    /**
     * @return array{id: string, asset_id: string|null, pool_id: string|null, inspected_at: string, summary: string}
     */
    private function maintenanceRecordPayload(MaintenanceRecord $record): array
    {
        return [
            'id' => $record->id,
            'asset_id' => $record->asset_id,
            'pool_id' => $record->pool_id,
            'inspected_at' => $record->inspected_at->toIso8601String(),
            'summary' => $record->summary,
        ];
    }

    private function maintenanceResource(MaintenanceWindow $window): Asset|Pool
    {
        if ($window->asset_id !== null) {
            $asset = Asset::query()->find($window->asset_id);
            abort_unless($asset instanceof Asset, 404);

            return $asset;
        }

        if ($window->pool_id !== null) {
            $pool = Pool::query()->find($window->pool_id);
            abort_unless($pool instanceof Pool, 404);

            return $pool;
        }

        abort(404);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    /**
     * @param  class-string<Asset|Pool>  $model
     */
    private function ownedId(string $model, mixed $id): ?string
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        $row = $model::query()->find($id);

        if ($row === null) {
            throw ValidationException::withMessages(['asset_id' => 'That resource was not found.']);
        }

        return (string) $row->getKey();
    }
}
