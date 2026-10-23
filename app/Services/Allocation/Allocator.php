<?php

namespace App\Services\Allocation;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\BookingLineItem;
use App\Models\CapacityLedger;
use App\Models\Customer;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Pool;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Inventory\OpeningHours;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @phpstan-type Demand array{type: ResourceType, id: string, quantity: int, buffer_start: CarbonImmutable, buffer_end: CarbonImmutable, name: string, buckets: list<int>}
 * @phpstan-type Plan array{demands: list<Demand>, bucket_minutes: int, location: Location, starts_at: CarbonImmutable, ends_at: CarbonImmutable}
 */
class Allocator
{
    public function __construct(
        private readonly OpeningHours $hours,
        private readonly BucketPlanner $buckets,
        private readonly TenantManager $tenants,
    ) {}

    public function hold(AllocationIntent $intent): Booking
    {
        try {
            return $this->placeHold($intent);
        } catch (QueryException $exception) {
            return $this->replayIdempotentHold($intent, $exception);
        }
    }

    private function placeHold(AllocationIntent $intent): Booking
    {
        return $this->retry(fn (): Booking => $this->transaction(function () use ($intent): Booking {
            $this->enterTenant($intent->tenantId);
            $existing = $this->existingBooking($intent);

            if ($existing instanceof Booking) {
                return $existing;
            }

            $plan = $this->plan($intent);
            $this->lock($plan);
            $this->recheck($plan, null);
            $booking = $this->createBooking($intent, $plan, BookingStatus::Held);
            $this->writeRows($booking, $plan, hold: true);
            $this->refreshCounters($plan);

            return $booking->load(['holds', 'lineItems']);
        }));
    }

    public function confirm(Booking $booking): Booking
    {
        return $this->retry(fn (): Booking => $this->transaction(function () use ($booking): Booking {
            $this->enterTenant($booking->tenant_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Held) {
                throw new AllocationUnavailableException('Only a held booking can be confirmed.');
            }

            $intent = $this->intentFromBooking($booking);
            $holds = Hold::query()->where('booking_id', $booking->id)->get();
            $unexpired = $holds->isNotEmpty() && $holds->every(fn (Hold $hold): bool => $hold->expires_at->greaterThan(now()));

            if ($unexpired) {
                $plan = $this->plan($intent);
                $this->lock($plan);
                $this->recheck($plan, $booking->id);
                Hold::query()->where('booking_id', $booking->id)->delete();
                BookingLineItem::query()->where('booking_id', $booking->id)->delete();
                $this->writeRows($booking, $plan, hold: false);
                $booking->status = BookingStatus::Confirmed;
                $booking->save();
                $this->refreshCounters($plan);

                return $booking->load(['allocations', 'lineItems']);
            }

            Hold::query()->where('booking_id', $booking->id)->delete();

            try {
                $plan = $this->plan($intent);
                $this->lock($plan);
                $this->recheck($plan, $booking->id);
            } catch (AllocationUnavailableException) {
                $booking->status = BookingStatus::PaymentException;
                $booking->save();

                return $booking;
            }

            BookingLineItem::query()->where('booking_id', $booking->id)->delete();
            $this->writeRows($booking, $plan, hold: false);
            $booking->status = BookingStatus::Confirmed;
            $booking->save();
            $this->refreshCounters($plan);

            return $booking->load(['allocations', 'lineItems']);
        }));
    }

    /**
     * @return array{booking: Booking, offers: array<string, mixed>}
     */
    public function extend(Booking $booking, CarbonImmutable $newEnd): array
    {
        try {
            $saved = $this->retry(fn (): Booking => $this->transaction(function () use ($booking, $newEnd): Booking {
                $this->enterTenant($booking->tenant_id);
                $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

                if ($booking->status !== BookingStatus::Confirmed) {
                    throw new AllocationUnavailableException('Only a confirmed booking can be extended.');
                }

                $newEnd = $newEnd->utc();

                if ($newEnd->lessThanOrEqualTo($booking->ends_at)) {
                    throw new AllocationUnavailableException('The new end must be after the current end.');
                }

                $intent = new AllocationIntent(
                    $booking->tenant_id,
                    $booking->service_id,
                    CarbonImmutable::instance($booking->starts_at)->utc(),
                    $newEnd,
                    $booking->participants,
                    null,
                    null,
                    null,
                );
                $plan = $this->plan($intent);
                $this->lock($plan);

                try {
                    $this->recheck($plan, $booking->id);
                } catch (AllocationUnavailableException $unavailable) {
                    throw new AllocationUnavailableException(
                        $unavailable->getMessage(),
                        $this->offers($booking, $plan, $newEnd),
                    );
                }

                $booking->ends_at = Carbon::instance($newEnd);
                $booking->save();
                Allocation::query()->where('booking_id', $booking->id)->delete();
                BookingLineItem::query()->where('booking_id', $booking->id)->delete();
                $this->writeRows($booking, $plan, hold: false);
                $this->refreshCounters($plan);

                return $booking->load(['allocations', 'lineItems']);
            }));
        } catch (AllocationUnavailableException $exception) {
            if ($exception->offers === []) {
                throw $exception;
            }

            return ['booking' => $booking->fresh() ?? $booking, 'offers' => $exception->offers];
        }

        return ['booking' => $saved, 'offers' => []];
    }

    public function cancel(Booking $booking): Booking
    {
        return $this->retry(fn (): Booking => $this->transaction(function () use ($booking): Booking {
            $this->enterTenant($booking->tenant_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $intent = $this->intentFromBooking($booking);
            $plan = $this->plan($intent);
            $this->lock($plan);
            Hold::query()->where('booking_id', $booking->id)->delete();
            Allocation::query()->where('booking_id', $booking->id)->delete();
            $booking->status = BookingStatus::Cancelled;
            $booking->save();
            $this->refreshCounters($plan);

            return $booking;
        }));
    }

    public function reschedule(Booking $booking, CarbonImmutable $newStart, CarbonImmutable $newEnd): Booking
    {
        return $this->retry(fn (): Booking => $this->transaction(function () use ($booking, $newStart, $newEnd): Booking {
            $this->enterTenant($booking->tenant_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new AllocationUnavailableException('Only a confirmed booking can be rescheduled.');
            }

            $newStart = $newStart->utc();
            $newEnd = $newEnd->utc();

            if ($newEnd->lessThanOrEqualTo($newStart)) {
                throw new AllocationUnavailableException('Choose a valid interval.');
            }

            if (CarbonImmutable::instance($booking->starts_at)->utc()->equalTo($newStart)
                && CarbonImmutable::instance($booking->ends_at)->utc()->equalTo($newEnd)) {
                return $booking->load(['allocations', 'lineItems', 'service']);
            }

            $intent = new AllocationIntent(
                $booking->tenant_id,
                $booking->service_id,
                $newStart,
                $newEnd,
                $booking->participants,
                null,
                null,
                null,
            );
            $plan = $this->plan($intent);
            $this->lock($plan);

            try {
                $this->recheck($plan, $booking->id);
            } catch (AllocationUnavailableException $unavailable) {
                throw new AllocationUnavailableException($unavailable->getMessage(), [
                    'authoritative' => false,
                    'other_asset_ids' => $this->otherAssets($booking, $plan),
                ]);
            }

            $booking->starts_at = Carbon::instance($newStart);
            $booking->ends_at = Carbon::instance($newEnd);
            $booking->save();
            Allocation::query()->where('booking_id', $booking->id)->delete();
            BookingLineItem::query()->where('booking_id', $booking->id)->delete();
            $this->writeRows($booking, $plan, hold: false);
            $this->refreshCounters($plan);

            return $booking->load(['allocations', 'lineItems', 'service']);
        }));
    }

    public function reassignAsset(Booking $booking, string $fromAssetId, string $toAssetId): Booking
    {
        return $this->retry(fn (): Booking => $this->transaction(function () use ($booking, $fromAssetId, $toAssetId): Booking {
            $this->enterTenant($booking->tenant_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new AllocationUnavailableException('Only a confirmed booking can be reassigned.');
            }

            if ($fromAssetId === $toAssetId) {
                throw new AllocationUnavailableException('Choose a different asset.');
            }

            $intent = $this->intentFromBooking($booking);
            $plan = $this->plan($intent);
            $plan = $this->swapAsset($plan, $booking, $fromAssetId, $toAssetId);
            $this->lock($plan);

            try {
                $this->recheck($plan, $booking->id);
            } catch (AllocationUnavailableException $unavailable) {
                throw new AllocationUnavailableException($unavailable->getMessage(), [
                    'authoritative' => false,
                    'other_asset_ids' => $this->otherAssets($booking, $plan),
                ]);
            }

            Allocation::query()->where('booking_id', $booking->id)->delete();
            BookingLineItem::query()->where('booking_id', $booking->id)->delete();
            $this->writeRows($booking, $plan, hold: false);
            $this->refreshCounters($plan);

            return $booking->load(['allocations', 'lineItems', 'service']);
        }));
    }

    /**
     * @param  Plan  $plan
     * @return Plan
     */
    private function swapAsset(array $plan, Booking $booking, string $fromAssetId, string $toAssetId): array
    {
        $from = Asset::query()->find($fromAssetId);
        $to = Asset::query()->find($toAssetId);

        if (! $to instanceof Asset || $to->status !== ResourceStatus::Active || $to->location_id !== $booking->location_id) {
            throw new AllocationUnavailableException('That asset is not available.');
        }

        if (! $from instanceof Asset || $from->resource_category_id === null || $from->resource_category_id !== $to->resource_category_id) {
            throw new AllocationUnavailableException('Choose another asset in the same category.');
        }

        $replaced = false;

        foreach ($plan['demands'] as $index => $demand) {
            if ($demand['type'] !== ResourceType::Asset || $demand['id'] !== $fromAssetId) {
                continue;
            }

            $open = $this->hours->assess($plan['location'], $plan['starts_at'], $plan['ends_at'], ResourceType::Asset->value, $to->id);

            if (! $open['open']) {
                throw new AllocationUnavailableException('That resource is outside its hours or a blackout.');
            }

            $plan['demands'][$index]['id'] = $to->id;
            $plan['demands'][$index]['name'] = $to->name;
            $plan['demands'][$index]['buffer_start'] = $plan['starts_at']->subMinutes($to->prep_buffer_minutes);
            $plan['demands'][$index]['buffer_end'] = $plan['ends_at']->addMinutes($to->return_buffer_minutes);
            $replaced = true;
        }

        if (! $replaced) {
            throw new AllocationUnavailableException('That asset is not on this booking.');
        }

        $demands = $plan['demands'];
        usort($demands, function (array $left, array $right): int {
            $type = [$left['type'] === ResourceType::Pool ? 0 : 1, $right['type'] === ResourceType::Pool ? 0 : 1];

            if ($type[0] !== $type[1]) {
                return $type[0] <=> $type[1];
            }

            return $left['id'] <=> $right['id'];
        });
        $plan['demands'] = $demands;

        return $plan;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function retry(callable $callback): mixed
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        try {
            $last = null;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    return $callback();
                } catch (QueryException $exception) {
                    $last = $exception;

                    if (! $this->retryable($exception) || $attempt === 3) {
                        break;
                    }

                    usleep(random_int(20000, 80000));
                }
            }

            if ($this->retryable($last)) {
                throw new AllocationUnavailableException; // lock-wait-exhausted
            }

            throw $last;
        } finally {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            DB::statement('SET innodb_lock_wait_timeout = 3');

            return $callback();
        });
    }

    private function retryable(QueryException $exception): bool
    {
        $code = (int) ($exception->errorInfo[1] ?? 0);

        return $code === 1205 || $code === 1213;
    }

    private function enterTenant(string $tenantId): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant instanceof Tenant) {
            $this->tenants->set($tenant);
        }
    }

    private function replayIdempotentHold(AllocationIntent $intent, QueryException $exception): Booking
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062 || $intent->idempotencyKey === null || $intent->idempotencyKey === '') {
            throw $exception;
        }

        $this->enterTenant($intent->tenantId);
        $existing = Booking::query()->where('idempotency_key', $intent->idempotencyKey)->first();

        if (! $existing instanceof Booking) {
            throw $exception;
        }

        if (! hash_equals($existing->payload_hash, $intent->payloadHash())) {
            throw new IdempotencyConflictException;
        }

        return $existing->load(['holds', 'allocations', 'lineItems']);
    }

    private function existingBooking(AllocationIntent $intent): ?Booking
    {
        if ($intent->idempotencyKey === null || $intent->idempotencyKey === '') {
            return null;
        }

        $existing = Booking::query()
            ->where('idempotency_key', $intent->idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            return null;
        }

        if (! hash_equals($existing->payload_hash, $intent->payloadHash())) {
            throw new IdempotencyConflictException;
        }

        return $existing->load(['holds', 'allocations', 'lineItems']);
    }

    /**
     * @return Plan
     */
    private function plan(AllocationIntent $intent): array
    {
        $service = Service::query()->with(['location', 'requirements'])->find($intent->serviceId);

        if ($service === null || $service->location === null) {
            throw new AllocationUnavailableException('That service is not available.');
        }

        if ($intent->endsAt->lessThanOrEqualTo($intent->startsAt) || $intent->participants < 1) {
            throw new AllocationUnavailableException('Choose a valid interval and party size.');
        }

        if ($service->max_participants !== null && $intent->participants > $service->max_participants) {
            throw new AllocationUnavailableException('That party is larger than this service allows.');
        }

        $settings = TenantSetting::query()->where('tenant_id', $intent->tenantId)->first();
        $bucketMinutes = $settings instanceof TenantSetting ? $settings->ledger_bucket_minutes : 15;
        $open = $this->hours->assess($service->location, $intent->startsAt, $intent->endsAt);

        if (! $open['open']) {
            throw new AllocationUnavailableException($open['blackout']
                ? 'That date is closed.'
                : 'That time is outside opening hours.');
        }

        if ($service->requirements->isEmpty()) {
            throw new AllocationUnavailableException('This service has no resources.');
        }

        /** @var array<string, array{type: ResourceType, id: string, quantity: int, buffer_start: CarbonImmutable, buffer_end: CarbonImmutable, name: string, buckets: list<int>}> $merged */
        $merged = [];

        foreach ($service->requirements as $requirement) {
            $units = $requirement->unitsFor($intent->participants);
            $resource = $this->resource($requirement);

            if ($resource === null || $resource->location_id !== $service->location_id || $resource->status !== ResourceStatus::Active) {
                throw new AllocationUnavailableException('A required resource is not available.');
            }

            if ($requirement->resource_type === ResourceType::Asset && $units !== 1) {
                throw new AllocationUnavailableException('An individual asset can only be held once.');
            }

            $bufferStart = $intent->startsAt->subMinutes($resource->prep_buffer_minutes);
            $bufferEnd = $intent->endsAt->addMinutes($resource->return_buffer_minutes);
            $resourceOpen = $this->hours->assess(
                $service->location,
                $intent->startsAt,
                $intent->endsAt,
                $requirement->resource_type->value,
                $resource->id,
            );

            if (! $resourceOpen['open']) {
                throw new AllocationUnavailableException('That resource is outside its hours or a blackout.');
            }

            $key = $requirement->resource_type->value.':'.$resource->id;
            $buckets = $requirement->resource_type === ResourceType::Pool
                ? $this->buckets->covering($bufferStart, $bufferEnd, $bucketMinutes)
                : [];

            if (isset($merged[$key])) {
                $merged[$key]['quantity'] += $units;

                continue;
            }

            $merged[$key] = [
                'type' => $requirement->resource_type,
                'id' => $resource->id,
                'quantity' => $units,
                'buffer_start' => $bufferStart,
                'buffer_end' => $bufferEnd,
                'name' => $resource->name,
                'buckets' => $buckets,
            ];
        }

        $demands = array_values($merged);
        usort($demands, function (array $left, array $right): int {
            $type = [$left['type'] === ResourceType::Pool ? 0 : 1, $right['type'] === ResourceType::Pool ? 0 : 1];

            if ($type[0] !== $type[1]) {
                return $type[0] <=> $type[1];
            }

            return $left['id'] <=> $right['id'];
        });

        return [
            'demands' => $demands,
            'bucket_minutes' => $bucketMinutes,
            'location' => $service->location,
            'starts_at' => $intent->startsAt,
            'ends_at' => $intent->endsAt,
        ];
    }

    private function resource(ResourceRequirement $requirement): Asset|Pool|null
    {
        if ($requirement->resource_type === ResourceType::Asset) {
            return Asset::query()->find($requirement->resource_id);
        }

        return Pool::query()->find($requirement->resource_id);
    }

    /**
     * @param  Plan  $plan
     */
    private function lock(array $plan): void
    {
        foreach ($plan['demands'] as $demand) {
            if ($demand['type'] !== ResourceType::Pool) {
                continue;
            }

            foreach ($demand['buckets'] as $bucket) {
                DB::table('capacity_ledger')->insertOrIgnore([
                    'id' => (string) Str::ulid(),
                    'tenant_id' => $this->tenants->id(),
                    'pool_id' => $demand['id'],
                    'bucket_start_utc' => CarbonImmutable::parse('@'.$bucket)->utc()->format('Y-m-d H:i:s'),
                    'allocated_units' => 0,
                    'held_units' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        foreach ($plan['demands'] as $demand) {
            if ($demand['type'] !== ResourceType::Pool) {
                continue;
            }

            DB::select('select id from pools where id = ? for update', [$demand['id']]);

            foreach ($demand['buckets'] as $bucket) {
                $rows = DB::select(
                    'select id from capacity_ledger where pool_id = ? and bucket_start_utc = ? for update',
                    [$demand['id'], CarbonImmutable::parse('@'.$bucket)->utc()->format('Y-m-d H:i:s')],
                );

                if ($rows === []) {
                    throw new AllocationUnavailableException;
                }
            }
        }

        foreach ($plan['demands'] as $demand) {
            if ($demand['type'] !== ResourceType::Asset) {
                continue;
            }

            $rows = DB::select('select id from assets where id = ? for update', [$demand['id']]);

            if ($rows === []) {
                throw new AllocationUnavailableException;
            }
        }
    }

    /**
     * @param  Plan  $plan
     */
    private function recheck(array $plan, ?string $ignoreBookingId): void
    {
        $open = $this->hours->assess($plan['location'], $plan['starts_at'], $plan['ends_at']);

        if (! $open['open']) {
            throw new AllocationUnavailableException($open['blackout']
                ? 'That date is closed.'
                : 'That time is outside opening hours.');
        }

        foreach ($plan['demands'] as $demand) {
            if ($demand['type'] === ResourceType::Asset) {
                if ($this->assetTaken($demand['id'], $demand['buffer_start'], $demand['buffer_end'], $ignoreBookingId)) {
                    throw new AllocationUnavailableException;
                }

                continue;
            }

            $pool = Pool::query()->find($demand['id']);

            if ($pool === null || $pool->status !== ResourceStatus::Active) {
                throw new AllocationUnavailableException;
            }

            foreach ($demand['buckets'] as $bucket) {
                $start = CarbonImmutable::parse('@'.$bucket)->utc();
                $end = $start->addSeconds($plan['bucket_minutes'] * 60);
                $used = $this->poolUsed($demand['id'], $start, $end, $ignoreBookingId);

                if ($used + $demand['quantity'] > $pool->quantity) {
                    throw new AllocationUnavailableException;
                }
            }
        }
    }

    private function assetTaken(string $assetId, CarbonImmutable $start, CarbonImmutable $end, ?string $ignoreBookingId): bool
    {
        $allocations = Allocation::query()
            ->where('asset_id', $assetId)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('booking_id', '!=', $ignoreBookingId))
            ->exists();

        $holds = Hold::query()
            ->where('asset_id', $assetId)
            ->where('expires_at', '>', now())
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('booking_id', '!=', $ignoreBookingId))
            ->exists();

        return $allocations || $holds;
    }

    private function poolUsed(string $poolId, CarbonImmutable $start, CarbonImmutable $end, ?string $ignoreBookingId): int
    {
        $allocated = (int) Allocation::query()
            ->where('pool_id', $poolId)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('booking_id', '!=', $ignoreBookingId))
            ->sum('quantity');

        $held = (int) Hold::query()
            ->where('pool_id', $poolId)
            ->where('expires_at', '>', now())
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('booking_id', '!=', $ignoreBookingId))
            ->sum('quantity');

        return $allocated + $held;
    }

    /**
     * @param  Plan  $plan
     */
    private function createBooking(AllocationIntent $intent, array $plan, BookingStatus $status): Booking
    {
        $service = Service::query()->findOrFail($intent->serviceId);
        $customerId = $this->customerId($intent);

        return Booking::query()->create([
            'tenant_id' => $intent->tenantId,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'customer_id' => $customerId,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $intent->startsAt->utc(),
            'ends_at' => $intent->endsAt->utc(),
            'participants' => $intent->participants,
            'idempotency_key' => $intent->idempotencyKey !== '' ? $intent->idempotencyKey : null,
            'payload_hash' => $intent->payloadHash(),
        ]);
    }

    /**
     * @param  Plan  $plan
     */
    private function writeRows(Booking $booking, array $plan, bool $hold): void
    {
        $expires = now()->addMinutes($this->holdMinutes($booking->tenant_id));

        foreach ($plan['demands'] as $demand) {
            BookingLineItem::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'resource_type' => $demand['type'],
                'resource_id' => $demand['id'],
                'quantity' => $demand['quantity'],
                'buffered_starts_at' => $demand['buffer_start'],
                'buffered_ends_at' => $demand['buffer_end'],
                'description' => $demand['name'].' x '.$demand['quantity'],
            ]);

            $attributes = [
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'asset_id' => $demand['type'] === ResourceType::Asset ? $demand['id'] : null,
                'pool_id' => $demand['type'] === ResourceType::Pool ? $demand['id'] : null,
                'quantity' => $demand['quantity'],
                'starts_at' => $demand['buffer_start'],
                'ends_at' => $demand['buffer_end'],
            ];

            if ($hold) {
                Hold::query()->create($attributes + ['expires_at' => $expires]);
            } else {
                Allocation::query()->create($attributes);
            }
        }
    }

    /**
     * @param  Plan  $plan
     */
    private function refreshCounters(array $plan): void
    {
        foreach ($plan['demands'] as $demand) {
            if ($demand['type'] !== ResourceType::Pool) {
                continue;
            }

            foreach ($demand['buckets'] as $bucket) {
                $start = CarbonImmutable::parse('@'.$bucket)->utc();
                $end = $start->addSeconds($plan['bucket_minutes'] * 60);
                CapacityLedger::query()
                    ->where('pool_id', $demand['id'])
                    ->where('bucket_start_utc', $start->format('Y-m-d H:i:s'))
                    ->update([
                        'allocated_units' => $this->sumOnly(Allocation::class, $demand['id'], $start, $end),
                        'held_units' => $this->sumOnly(Hold::class, $demand['id'], $start, $end, unexpired: true),
                    ]);
            }
        }
    }

    /**
     * @param  class-string<Allocation|Hold>  $model
     */
    private function sumOnly(string $model, string $poolId, CarbonImmutable $start, CarbonImmutable $end, bool $unexpired = false): int
    {
        return (int) $model::query()
            ->where('pool_id', $poolId)
            ->when($unexpired, fn ($query) => $query->where('expires_at', '>', now()))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->sum('quantity');
    }

    private function holdMinutes(string $tenantId): int
    {
        $settings = TenantSetting::query()->where('tenant_id', $tenantId)->first();
        $minutes = $settings instanceof TenantSetting ? $settings->hold_minutes : 15;

        return max(5, min(120, $minutes));
    }

    private function customerId(AllocationIntent $intent): ?string
    {
        if (($intent->customerName === null || $intent->customerName === '') && ($intent->customerEmail === null || $intent->customerEmail === '')) {
            return null;
        }

        if ($intent->customerEmail !== null && $intent->customerEmail !== '') {
            $customer = Customer::query()->firstOrCreate(
                ['tenant_id' => $intent->tenantId, 'email' => strtolower($intent->customerEmail)],
                ['name' => $intent->customerName !== null && $intent->customerName !== '' ? $intent->customerName : $intent->customerEmail],
            );

            return $customer->id;
        }

        return Customer::query()->create([
            'tenant_id' => $intent->tenantId,
            'name' => (string) $intent->customerName,
        ])->id;
    }

    private function intentFromBooking(Booking $booking): AllocationIntent
    {
        return new AllocationIntent(
            $booking->tenant_id,
            $booking->service_id,
            CarbonImmutable::instance($booking->starts_at)->utc(),
            CarbonImmutable::instance($booking->ends_at)->utc(),
            $booking->participants,
            null,
            null,
            null,
        );
    }

    /**
     * @param  Plan  $plan
     * @return array<string, mixed>
     */
    private function offers(Booking $booking, array $plan, CarbonImmutable $requestedEnd): array
    {
        $currentEnd = CarbonImmutable::instance($booking->ends_at)->utc();
        $halfSeconds = (int) floor($currentEnd->diffInSeconds($requestedEnd) / 2);
        $shorter = $halfSeconds > 0 ? $currentEnd->addSeconds($halfSeconds) : null;
        $shorterEndsAt = null;

        if ($shorter instanceof CarbonImmutable) {
            $shorterPlan = $plan;
            foreach ($shorterPlan['demands'] as $index => $demand) {
                $resource = $demand['type'] === ResourceType::Asset
                    ? Asset::query()->find($demand['id'])
                    : Pool::query()->find($demand['id']);
                $buffer = $resource === null ? 0 : $resource->return_buffer_minutes;
                $shorterPlan['demands'][$index]['buffer_end'] = $shorter->addMinutes($buffer);
                if ($demand['type'] === ResourceType::Pool) {
                    $shorterPlan['demands'][$index]['buckets'] = $this->buckets->covering(
                        $demand['buffer_start'],
                        $shorter->addMinutes($buffer),
                        $plan['bucket_minutes'],
                    );
                }
            }

            try {
                $this->recheck($shorterPlan, $booking->id);
                $shorterEndsAt = $shorter->format('Y-m-d\TH:i:s\Z');
            } catch (AllocationUnavailableException) {
                $shorterEndsAt = null;
            }
        }

        return [
            'authoritative' => false,
            'shorter_ends_at' => $shorterEndsAt,
            'other_asset_ids' => $this->otherAssets($booking, $plan),
        ];
    }

    /**
     * @param  Plan  $plan
     * @return list<string>
     */
    private function otherAssets(Booking $booking, array $plan): array
    {
        $ids = [];

        foreach ($plan['demands'] as $demand) {
            if ($demand['type'] !== ResourceType::Asset) {
                continue;
            }

            $current = Asset::query()->find($demand['id']);

            if ($current === null || $current->resource_category_id === null) {
                continue;
            }

            $candidates = Asset::query()
                ->where('location_id', $current->location_id)
                ->where('resource_category_id', $current->resource_category_id)
                ->where('status', ResourceStatus::Active)
                ->whereKeyNot($current->id)
                ->limit(5)
                ->get();

            foreach ($candidates as $candidate) {
                if (! $this->assetTaken($candidate->id, $demand['buffer_start'], $demand['buffer_end'], $booking->id)) {
                    $ids[] = $candidate->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
