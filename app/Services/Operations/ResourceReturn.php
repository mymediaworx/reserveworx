<?php

namespace App\Services\Operations;

use App\Enums\OperationalStatus;
use App\Enums\ResourceFlag;
use App\Enums\ResourceStatus;
use App\Enums\ReturnCondition;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\BookingLineItem;
use App\Models\CapacityLedger;
use App\Models\Hold;
use App\Models\Pool;
use App\Models\RentalCheckout;
use App\Models\RentalReturn;
use App\Models\TenantSetting;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\BucketPlanner;
use App\Services\Notifications\BookingEvents;
use App\Support\Lateness;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records one checked-out resource as back.
 * Stores whole minutes late. Does not write a late fee and does not move money.
 */
final class ResourceReturn
{
    public function __construct(
        private readonly AffectedBookings $affected,
        private readonly BookingEvents $events,
        private readonly BucketPlanner $buckets,
    ) {}

    /**
     * @return array{
     *     checkout: RentalCheckout,
     *     return: RentalReturn,
     *     booking: Booking,
     *     minutes_late: int,
     *     released_start: ?CarbonImmutable,
     *     released_end: ?CarbonImmutable,
     *     maintained_asset_id: ?string,
     *     fully_returned: bool,
     *     conditions: list<array{asset_id: string|null, pool_id: string|null, condition: string, notes: ?string, recorded_by: string|null, minutes_late: int}>
     * }
     */
    public function record(
        RentalCheckout $checkout,
        CarbonImmutable $returnedAt,
        ReturnCondition $condition,
        ?string $notes,
        string $recordedBy,
    ): array {
        if (! in_array($condition, [ReturnCondition::Good, ReturnCondition::NeedsAttention, ReturnCondition::Damaged], true)) {
            throw ValidationException::withMessages([
                'condition' => 'Condition must be good, needs attention, or damaged.',
            ]);
        }

        $notes = $this->note($notes);
        $returnedAt = $returnedAt->utc();

        $result = DB::transaction(function () use ($checkout, $returnedAt, $condition, $notes, $recordedBy): array {
            DB::statement('SET innodb_lock_wait_timeout = 3');

            $booking = Booking::query()->lockForUpdate()->find($checkout->booking_id);
            $locked = RentalCheckout::query()->lockForUpdate()->find($checkout->id);

            if (! $booking instanceof Booking || ! $locked instanceof RentalCheckout) {
                throw new AllocationUnavailableException('This resource is not checked out.');
            }

            if ($locked->returned_at !== null) {
                throw new AllocationUnavailableException('This resource is already back.');
            }

            $this->guardReturnTime($locked, $returnedAt);
            $minutesLate = Lateness::minutes($locked->due_at, $returnedAt);
            $locked->returned_at = Carbon::instance($returnedAt);
            $locked->save();

            $row = RentalReturn::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'rental_checkout_id' => $locked->id,
                'asset_id' => $locked->asset_id,
                'pool_id' => $this->poolId($locked),
                'condition' => $condition,
                'notes' => $notes,
                'minutes_late' => $minutesLate,
                'recorded_by' => $recordedBy,
            ]);

            $maintained = $this->flagResource($locked, $condition);
            $release = $this->shortenAllocation($booking, $locked, $returnedAt);
            $stillOut = RentalCheckout::query()
                ->where('booking_id', $booking->id)
                ->whereNull('returned_at')
                ->exists();

            if (! $stillOut && $booking->operational_status !== OperationalStatus::Completed) {
                $booking->operational_status = OperationalStatus::Returned;
                $booking->save();
            }

            $conditions = $stillOut ? [] : $this->conditions($booking);

            return [
                'checkout' => $locked,
                'return' => $row,
                'booking' => $booking,
                'minutes_late' => $minutesLate,
                'released_start' => $release['start'],
                'released_end' => $release['end'],
                'maintained_asset_id' => $maintained,
                'fully_returned' => ! $stillOut,
                'conditions' => $conditions,
            ];
        });

        if ($result['fully_returned']) {
            $this->events->returned($result['booking'], $result['conditions']);
        }

        return $result;
    }

    private function note(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $notes = trim($notes);

        if ($notes === '') {
            return null;
        }

        if (mb_strlen($notes) > 500) {
            throw ValidationException::withMessages([
                'note' => 'The note may not be greater than 500 characters.',
            ]);
        }

        return $notes;
    }

    private function guardReturnTime(RentalCheckout $checkout, CarbonImmutable $returnedAt): void
    {
        $checkedOut = CarbonImmutable::parse($checkout->checked_out_at)->utc()->startOfMinute();

        if ($returnedAt->startOfMinute()->lessThan($checkedOut)) {
            throw ValidationException::withMessages([
                'returned_at' => 'The return time has to be after it went out.',
            ]);
        }

        if ($returnedAt->greaterThan(CarbonImmutable::now()->utc()->addSeconds(90))) {
            throw ValidationException::withMessages([
                'returned_at' => 'The return time cannot be in the future.',
            ]);
        }
    }

    private function poolId(RentalCheckout $checkout): ?string
    {
        if (is_string($checkout->asset_id) && $checkout->asset_id !== '') {
            return null;
        }

        return is_string($checkout->pool_id) && $checkout->pool_id !== '' ? $checkout->pool_id : null;
    }

    private function flagResource(RentalCheckout $checkout, ReturnCondition $condition): ?string
    {
        if (is_string($checkout->asset_id) && $checkout->asset_id !== '') {
            $asset = Asset::query()->lockForUpdate()->find($checkout->asset_id);

            if (! $asset instanceof Asset) {
                return null;
            }

            $this->applyFlag($asset, $condition);

            if ($condition !== ReturnCondition::Damaged || $asset->status === ResourceStatus::Maintenance) {
                return null;
            }

            $previous = $asset->status;
            $asset->status = ResourceStatus::Maintenance;
            $asset->save();
            $this->affected->flagAssetStatus($asset, $previous);

            return $asset->id;
        }

        if (is_string($checkout->pool_id) && $checkout->pool_id !== '') {
            $pool = Pool::query()->lockForUpdate()->find($checkout->pool_id);

            if ($pool instanceof Pool) {
                $this->applyFlag($pool, $condition);
            }
        }

        return null;
    }

    private function applyFlag(Asset|Pool $resource, ReturnCondition $condition): void
    {
        $resource->condition_flag = match ($condition) {
            ReturnCondition::NeedsAttention => ResourceFlag::NeedsAttention,
            ReturnCondition::Damaged => ResourceFlag::Damaged,
            default => null,
        };
        $resource->save();
    }

    /**
     * @return array{start: ?CarbonImmutable, end: ?CarbonImmutable}
     */
    private function shortenAllocation(Booking $booking, RentalCheckout $checkout, CarbonImmutable $returnedAt): array
    {
        $empty = ['start' => null, 'end' => null];
        $query = Allocation::query()->where('booking_id', $booking->id)->lockForUpdate();

        if (is_string($checkout->asset_id) && $checkout->asset_id !== '') {
            $query->where('asset_id', $checkout->asset_id);
        } elseif (is_string($checkout->pool_id) && $checkout->pool_id !== '') {
            $query->where('pool_id', $checkout->pool_id);
        } else {
            return $empty;
        }

        $allocation = $query->first();

        if (! $allocation instanceof Allocation) {
            return $empty;
        }

        $previousEnd = CarbonImmutable::parse($allocation->ends_at)->utc();
        $updatedEnd = $returnedAt->addMinutes($this->turnaround($checkout));

        if ($updatedEnd->greaterThanOrEqualTo($previousEnd)) {
            return $empty;
        }

        $allocation->ends_at = Carbon::instance($updatedEnd);
        $allocation->save();
        $resourceId = $allocation->asset_id ?? $allocation->pool_id;

        if (is_string($resourceId) && $resourceId !== '') {
            BookingLineItem::query()
                ->where('booking_id', $booking->id)
                ->where('resource_id', $resourceId)
                ->update(['buffered_ends_at' => $updatedEnd]);
        }

        if (is_string($allocation->pool_id) && $allocation->pool_id !== '') {
            $this->refreshPool(
                $booking->tenant_id,
                $allocation->pool_id,
                CarbonImmutable::parse($allocation->starts_at)->utc(),
                $previousEnd,
            );
        }

        return ['start' => $updatedEnd, 'end' => $previousEnd];
    }

    private function turnaround(RentalCheckout $checkout): int
    {
        if (is_string($checkout->asset_id) && $checkout->asset_id !== '') {
            $asset = Asset::query()->find($checkout->asset_id);

            return $asset instanceof Asset ? $asset->turnaround_minutes : 0;
        }

        if (is_string($checkout->pool_id) && $checkout->pool_id !== '') {
            $pool = Pool::query()->find($checkout->pool_id);

            return $pool instanceof Pool ? $pool->turnaround_minutes : 0;
        }

        return 0;
    }

    private function refreshPool(string $tenantId, string $poolId, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $setting = TenantSetting::query()->where('tenant_id', $tenantId)->first();
        $bucketMinutes = $setting instanceof TenantSetting ? $setting->ledger_bucket_minutes : 15;

        if ($bucketMinutes < 1) {
            $bucketMinutes = 15;
        }

        foreach ($this->buckets->covering($from, $to, $bucketMinutes) as $bucket) {
            $start = CarbonImmutable::createFromTimestampUTC($bucket);
            $end = $start->addMinutes($bucketMinutes);
            CapacityLedger::query()
                ->where('pool_id', $poolId)
                ->where('bucket_start_utc', $start->format('Y-m-d H:i:s'))
                ->update([
                    'allocated_units' => $this->units(Allocation::class, $poolId, $start, $end),
                    'held_units' => $this->units(Hold::class, $poolId, $start, $end, true),
                ]);
        }
    }

    /**
     * @param  class-string<Allocation|Hold>  $model
     */
    private function units(string $model, string $poolId, CarbonImmutable $start, CarbonImmutable $end, bool $unexpired = false): int
    {
        return (int) $model::query()
            ->where('pool_id', $poolId)
            ->when($unexpired, fn ($query) => $query->where('expires_at', '>', now()))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->sum('quantity');
    }

    /**
     * @return list<array{asset_id: string|null, pool_id: string|null, condition: string, notes: ?string, recorded_by: string|null, minutes_late: int}>
     */
    private function conditions(Booking $booking): array
    {
        $rows = [];

        foreach (RentalReturn::query()->where('booking_id', $booking->id)->orderBy('id')->get() as $stored) {
            $rows[] = [
                'asset_id' => $stored->asset_id,
                'pool_id' => $stored->pool_id,
                'condition' => $stored->condition->value,
                'notes' => $stored->notes,
                'recorded_by' => $stored->recorded_by,
                'minutes_late' => (int) ($stored->minutes_late ?? 0),
            ];
        }

        return $rows;
    }
}
