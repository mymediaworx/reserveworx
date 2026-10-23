<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Pool;
use App\Models\Tenant;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\Operations\AffectedBookings;
use App\Services\Operations\WaiverPdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class OperationsController extends Controller
{
    public function __construct(private readonly AffectedBookings $affected) {}

    public function storeMaintenance(Request $request): JsonResponse
    {
        $this->authorize('create', Asset::class);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'asset_id' => ['nullable', 'string'],
            'pool_id' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:160'],
        ]);
        $assetId = $this->ownedId(Asset::class, $validated['asset_id'] ?? null);
        $poolId = $this->ownedId(Pool::class, $validated['pool_id'] ?? null);

        if (($assetId === null && $poolId === null) || ($assetId !== null && $poolId !== null)) {
            throw ValidationException::withMessages(['asset_id' => 'Choose one asset or one pool.']);
        }

        $starts = CarbonImmutable::parse($validated['starts_at'])->utc();
        $ends = CarbonImmutable::parse($validated['ends_at'])->utc();
        $window = MaintenanceWindow::query()->create([
            'tenant_id' => $tenant->id,
            'asset_id' => $assetId,
            'pool_id' => $poolId,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'reason' => $validated['reason'],
        ]);
        $flagged = $this->affected->flag($assetId, $poolId, $starts, $ends, $validated['reason']);

        return response()->json(['data' => [
            'id' => $window->id,
            'flagged_bookings' => $flagged,
        ]], 201);
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
        $incident = Incident::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $this->ownedId(Asset::class, $validated['asset_id'] ?? null),
            'summary' => $validated['summary'],
            'occurred_at' => CarbonImmutable::parse($validated['occurred_at'])->utc(),
        ]);

        return response()->json(['data' => ['id' => $incident->id]], 201);
    }

    public function storeWaiver(Request $request): JsonResponse
    {
        $this->authorize('create', Asset::class);
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:20000'],
        ]);
        $hash = hash('sha256', $validated['body']);
        $latest = WaiverVersion::query()->where('name', $validated['name'])->orderByDesc('version')->first();
        $version = $latest instanceof WaiverVersion ? $latest->version + 1 : 1;
        $row = WaiverVersion::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'version' => $version,
            'body' => $validated['body'],
            'content_hash' => $hash,
        ]);

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
            'participant_name' => ['required', 'string', 'max:120'],
            'is_minor' => ['required', 'boolean'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
        ]);
        $waiver = WaiverVersion::query()->find($validated['waiver_version_id']);

        if (! $waiver instanceof WaiverVersion) {
            throw ValidationException::withMessages(['waiver_version_id' => 'That waiver version was not found.']);
        }

        $minor = (bool) $validated['is_minor'];
        $guardian = $validated['guardian_name'] ?? null;

        if ($minor && (! is_string($guardian) || trim($guardian) === '')) {
            throw ValidationException::withMessages(['guardian_name' => 'A minor needs a parent or guardian signature.']);
        }

        $signature = WaiverSignature::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'waiver_version_id' => $waiver->id,
            'participant_name' => $validated['participant_name'],
            'is_minor' => $minor,
            'guardian_name' => $minor ? $guardian : null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'content_hash' => $waiver->content_hash,
            'signed_at' => now(),
        ]);

        return response()->json(['data' => [
            'id' => $signature->id,
            'content_hash' => $signature->content_hash,
            'guardian_name' => $signature->guardian_name,
        ]], 201);
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
