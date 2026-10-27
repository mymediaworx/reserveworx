<?php

namespace App\Services\Operations;

use App\Enums\BookingStatus;
use App\Enums\MaintenanceKind;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Hold;
use App\Models\InspectionSchedule;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceWindow;
use App\Models\Pool;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Schedules maintenance windows and temporary blocks, lists the bookings a
 * window touches, and runs recurring inspections. Nothing here cancels,
 * moves, or reassigns a booking (Spec Section 9).
 */
final class MaintenancePlanner
{
    public function __construct(private readonly AffectedBookings $affected) {}

    /**
     * The resource row is locked first, the same row the allocator locks, so
     * a hold that races this window either commits first and is flagged, or
     * waits and then sees the window.
     *
     * @return array{window: MaintenanceWindow, flagged: int}
     */
    public function schedule(Asset|Pool $resource, MaintenanceKind $kind, CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $reason): array
    {
        return DB::transaction(function () use ($resource, $kind, $startsAt, $endsAt, $reason): array {
            $isAsset = $resource instanceof Asset;
            DB::select('select id from '.($isAsset ? 'assets' : 'pools').' where id = ? for update', [$resource->id]);

            $window = MaintenanceWindow::query()->create([
                'tenant_id' => $resource->tenant_id,
                'asset_id' => $isAsset ? $resource->id : null,
                'pool_id' => $isAsset ? null : $resource->id,
                'kind' => $kind,
                'starts_at' => $startsAt->utc(),
                'ends_at' => $endsAt->utc(),
                'reason' => $reason,
            ]);

            $flagged = $this->affected->flag(
                $window->asset_id,
                $window->pool_id,
                $startsAt->utc(),
                $endsAt->utc(),
                $reason,
            );

            return ['window' => $window, 'flagged' => $flagged];
        });
    }

    /**
     * Stops a current or upcoming window at now. The planned ends_at stays,
     * so an incident can still point at the row. A future booking flagged
     * with this window's reason is cleared only when no window on the same
     * resource still overlaps its allocation or unexpired hold. Nothing is
     * cancelled or moved.
     *
     * @return array{window: MaintenanceWindow, cleared: int}
     */
    public function end(MaintenanceWindow $window): array
    {
        return DB::transaction(function () use ($window): array {
            $resourceId = $window->asset_id ?? $window->pool_id;

            if ($resourceId === null) {
                throw new MaintenanceWindowAlreadyEndedException('That window has already ended.');
            }

            $table = $window->asset_id !== null ? 'assets' : 'pools';
            $lockedResource = DB::select('select id from '.$table.' where id = ? for update', [$resourceId]);

            if ($lockedResource === []) {
                throw new MaintenanceWindowAlreadyEndedException('That window has already ended.');
            }

            $locked = MaintenanceWindow::query()->lockForUpdate()->find($window->id);

            if (! $locked instanceof MaintenanceWindow || $locked->blocksUntil()->lessThanOrEqualTo(CarbonImmutable::now()->utc())) {
                throw new MaintenanceWindowAlreadyEndedException('That window has already ended.');
            }

            $locked->ended_at = CarbonImmutable::now()->utc()->toMutable();
            $locked->save();
            $cleared = $this->clearReleasedFlags($locked);
            $fresh = $locked->fresh();

            return ['window' => $fresh instanceof MaintenanceWindow ? $fresh : $locked, 'cleared' => $cleared];
        });
    }

    /**
     * Held, pending-payment, and confirmed bookings whose buffered time on this resource
     * overlaps the window and that have not started yet.
     *
     * @return Collection<int, Booking>
     */
    public function affectedBookings(MaintenanceWindow $window): Collection
    {
        $column = $window->asset_id !== null ? 'asset_id' : 'pool_id';
        $resourceId = $window->asset_id ?? $window->pool_id;
        $overlaps = fn ($query) => $query
            ->where($column, $resourceId)
            ->where('starts_at', '<', $window->ends_at)
            ->where('ends_at', '>', $window->starts_at);

        return Booking::query()
            ->with('customer')
            ->whereIn('status', [BookingStatus::Held->value, BookingStatus::PendingPayment->value, BookingStatus::Confirmed->value])
            ->where('starts_at', '>', now())
            ->where(function ($query) use ($overlaps): void {
                $query->whereIn('id', Allocation::query()->where($overlaps)->select('booking_id'))
                    ->orWhereIn('id', Hold::query()->where($overlaps)->where('expires_at', '>', now())->select('booking_id'));
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Records the inspection on the same boat or pool and moves the next due
     * date on. A use-based schedule starts counting again from now. The
     * booking, its price, and its payments are left as they are.
     */
    public function markInspectionDone(InspectionSchedule $schedule, ?string $note = null, ?CarbonImmutable $doneAt = null): MaintenanceRecord
    {
        $at = ($doneAt ?? CarbonImmutable::now())->utc();
        $assetId = $schedule->asset_id;
        $poolId = $schedule->pool_id;

        if (($assetId === null) === ($poolId === null)) {
            throw new InvalidArgumentException('An inspection belongs to one boat or one pool.');
        }

        return DB::transaction(function () use ($schedule, $note, $at, $assetId, $poolId): MaintenanceRecord {
            $record = MaintenanceRecord::query()->create([
                'tenant_id' => $schedule->tenant_id,
                'asset_id' => $assetId,
                'pool_id' => $poolId,
                'inspected_at' => $at,
                'summary' => mb_substr($schedule->name.(is_string($note) && trim($note) !== '' ? ': '.trim($note) : ''), 0, 500),
            ]);

            $schedule->last_done_at = $at->toMutable();
            $schedule->next_due_at = $schedule->interval_days !== null ? $at->addDays($schedule->interval_days)->toMutable() : null;
            $schedule->save();

            return $record;
        });
    }

    /**
     * Finished rentals on this boat or pool since the inspection was last
     * done, or since the schedule was set up. A rental on another resource
     * does not count.
     */
    public function usesSince(InspectionSchedule $schedule): int
    {
        $resourceId = $schedule->asset_id ?? $schedule->pool_id;

        if ($resourceId === null) {
            return 0;
        }

        $column = $schedule->asset_id !== null ? 'asset_id' : 'pool_id';
        $since = $schedule->last_done_at ?? $schedule->created_at ?? now();

        return Allocation::query()
            ->where($column, $resourceId)
            ->where('ends_at', '>', $since)
            ->where('ends_at', '<=', now())
            ->whereIn('booking_id', Booking::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $schedule->tenant_id)
                ->where('status', BookingStatus::Confirmed->value)
                ->select('id'))
            ->distinct()
            ->count('booking_id');
    }

    public function isDue(InspectionSchedule $schedule): bool
    {
        if ($schedule->interval_days !== null && $schedule->next_due_at !== null && $schedule->next_due_at->lessThanOrEqualTo(now())) {
            return true;
        }

        return $schedule->interval_uses !== null && $this->usesSince($schedule) >= $schedule->interval_uses;
    }

    /**
     * Clears this window's flag when the booking no longer overlaps any
     * blocking interval on the same resource. The comparison is the exact
     * reason text, so a later flag from something else is left alone.
     */
    private function clearReleasedFlags(MaintenanceWindow $window): int
    {
        $column = $window->asset_id !== null ? 'asset_id' : 'pool_id';
        $resourceId = $window->asset_id ?? $window->pool_id;

        if ($resourceId === null) {
            return 0;
        }

        $originalStart = CarbonImmutable::instance($window->starts_at)->utc();
        $originalEnd = CarbonImmutable::instance($window->ends_at)->utc();
        $overlaps = fn ($query) => $query
            ->where($column, $resourceId)
            ->where('starts_at', '<', $originalEnd)
            ->where('ends_at', '>', $originalStart);

        $bookings = Booking::query()
            ->where('attention_reason', $window->reason)
            ->whereIn('status', [BookingStatus::Held->value, BookingStatus::PendingPayment->value, BookingStatus::Confirmed->value])
            ->where('starts_at', '>', CarbonImmutable::now()->utc())
            ->where(function ($query) use ($overlaps): void {
                $query->whereIn('id', Allocation::query()->where($overlaps)->select('booking_id'))
                    ->orWhereIn('id', Hold::query()->where($overlaps)->where('expires_at', '>', now())->select('booking_id'));
            })
            ->get();
        $cleared = 0;

        foreach ($bookings as $booking) {
            if ($this->resourceStillBlocksBooking($column, $resourceId, $booking)) {
                continue;
            }

            $booking->attention_reason = null;
            $booking->save();
            $cleared++;
        }

        return $cleared;
    }

    private function resourceStillBlocksBooking(string $column, string $resourceId, Booking $booking): bool
    {
        $allocations = Allocation::query()
            ->where('booking_id', $booking->id)
            ->where($column, $resourceId)
            ->get();

        foreach ($allocations as $allocation) {
            if ($this->windowBlocksInterval($column, $resourceId, $allocation->starts_at, $allocation->ends_at)) {
                return true;
            }
        }

        $holds = Hold::query()
            ->where('booking_id', $booking->id)
            ->where($column, $resourceId)
            ->where('expires_at', '>', now())
            ->get();

        foreach ($holds as $hold) {
            if ($this->windowBlocksInterval($column, $resourceId, $hold->starts_at, $hold->ends_at)) {
                return true;
            }
        }

        return false;
    }

    private function windowBlocksInterval(string $column, string $resourceId, DateTimeInterface $start, DateTimeInterface $end): bool
    {
        return MaintenanceWindow::query()
            ->where($column, $resourceId)
            ->blocking(CarbonImmutable::instance($start)->utc(), CarbonImmutable::instance($end)->utc())
            ->exists();
    }
}
