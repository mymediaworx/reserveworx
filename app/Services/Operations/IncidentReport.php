<?php

namespace App\Services\Operations;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MaintenanceKind;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Hold;
use App\Models\Incident;
use App\Models\RentalCheckout;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Files one staff incident on a booking. An optional damage amount is added
 * to the customer total. A serious report can schedule maintenance, which
 * flags future bookings and does not cancel them.
 */
final class IncidentReport
{
    public function __construct(
        private readonly DamageCharge $charges,
        private readonly IncidentPhotos $photos,
        private readonly MaintenancePlanner $planner,
    ) {}

    /**
     * @param  list<UploadedFile>  $uploads
     * @param  array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, reason: string}|null  $maintenance
     * @return array{incident: Incident, flagged: int, photo_count: int}
     */
    public function file(
        Booking $booking,
        Asset $asset,
        IncidentSeverity $severity,
        string $notes,
        ?int $damageCents,
        array $uploads,
        User $reporter,
        ?array $maintenance,
    ): array {
        $notes = trim($notes);
        $this->assertAsset($booking, $asset);

        if (count($uploads) > IncidentPhotos::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => 'You can attach up to 6 photos.',
            ]);
        }

        if ($maintenance !== null && $severity !== IncidentSeverity::Serious) {
            throw ValidationException::withMessages([
                'blockAsset' => 'Only a serious incident can take the asset out for maintenance.',
            ]);
        }

        $charging = is_int($damageCents) && $damageCents > 0;
        $paths = [];

        try {
            return DB::transaction(function () use ($booking, $asset, $severity, $notes, $damageCents, $uploads, $reporter, $maintenance, $charging, &$paths): array {
                $incident = Incident::query()->create([
                    'tenant_id' => $booking->tenant_id,
                    'booking_id' => $booking->id,
                    'asset_id' => $asset->id,
                    'severity' => $severity,
                    'summary' => mb_substr($notes, 0, 500),
                    'notes' => $notes,
                    'status' => IncidentStatus::Open,
                    'reported_by' => $reporter->id,
                    'occurred_at' => CarbonImmutable::now('UTC'),
                ]);

                foreach ($uploads as $upload) {
                    $photo = $this->photos->store($incident, $upload);
                    $paths[] = $photo->path;
                }

                if ($charging) {
                    $this->charges->apply($booking, $incident->id, $damageCents);
                    $incident->damage_cents = $damageCents;
                    $incident->save();
                }

                $flagged = 0;

                if ($maintenance !== null) {
                    $scheduled = $this->planner->schedule(
                        $asset,
                        MaintenanceKind::Maintenance,
                        $maintenance['starts_at'],
                        $maintenance['ends_at'],
                        $maintenance['reason'],
                    );
                    $incident->maintenance_window_id = $scheduled['window']->id;
                    $incident->save();
                    $flagged = $scheduled['flagged'];
                }

                return [
                    'incident' => $incident->refresh(),
                    'flagged' => $flagged,
                    'photo_count' => count($uploads),
                ];
            });
        } catch (Throwable $throwable) {
            $this->photos->deletePaths($paths);

            throw $throwable;
        }
    }

    private function assertAsset(Booking $booking, Asset $asset): void
    {
        if ($asset->tenant_id !== $booking->tenant_id || $asset->location_id !== $booking->location_id) {
            throw ValidationException::withMessages([
                'assetId' => 'Choose a boat on this booking.',
            ]);
        }

        $onBooking = Allocation::query()->where('booking_id', $booking->id)->where('asset_id', $asset->id)->exists()
            || Hold::query()->where('booking_id', $booking->id)->where('asset_id', $asset->id)->exists()
            || RentalCheckout::query()->where('booking_id', $booking->id)->where('asset_id', $asset->id)->exists();

        if (! $onBooking) {
            throw ValidationException::withMessages([
                'assetId' => 'Choose a boat on this booking.',
            ]);
        }
    }
}
