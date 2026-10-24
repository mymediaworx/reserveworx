<?php

namespace App\Services\Allocation;

use App\Domain\Catalog\AddOnCharge;
use App\Enums\AddOnPricing;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\ServiceKind;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\BookingAddOn;
use App\Models\BookingLineItem;
use App\Models\CapacityLedger;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Pool;
use App\Models\RentalCheckout;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Booking\SlotFinder;
use App\Services\Catalog\BookingOperatorFeeLines;
use App\Services\Inventory\OpeningHours;
use App\Services\Notifications\BookingEvents;
use App\Services\Operations\ReturnConditions;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @phpstan-type Demand array{type: ResourceType, id: string, quantity: int, buffer_start: CarbonImmutable, buffer_end: CarbonImmutable, name: string, buckets: list<int>}
 * @phpstan-type Plan array{demands: list<Demand>, bucket_minutes: int, location: Location, starts_at: CarbonImmutable, ends_at: CarbonImmutable, guide_user_id: ?string, departure_id: ?string}
 */
class Allocator
{
    public function __construct(
        private readonly OpeningHours $hours,
        private readonly BucketPlanner $buckets,
        private readonly TenantManager $tenants,
        private readonly BookingEvents $events,
        private readonly ReturnConditions $returnConditions,
        private readonly BookingOperatorFeeLines $operatorFees,
    ) {}

    public function hold(AllocationIntent $intent): Booking
    {
        try {
            return $this->placeHold($intent);
        } catch (QueryException $exception) {
            return $this->replayIdempotentHold($intent, $exception);
        } catch (AllocationUnavailableException $exception) {
            if ($exception->offers !== [] || ! $this->availabilityRefusal($exception)) {
                throw $exception;
            }

            try {
                $offers = $this->holdOffers($intent);
            } catch (\Throwable) {
                throw $exception;
            }

            if ($offers['times'] === [] && $offers['other_asset_ids'] === []) {
                throw $exception;
            }

            throw new AllocationUnavailableException($exception->getMessage(), $offers);
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

            $this->claimDeparture($intent);

            if ($this->joiningOccupiedDeparture($intent)) {
                $this->lockGuide($intent->guideUserId);
                $this->assertGuideFree(
                    $intent->guideUserId,
                    $intent->startsAt,
                    $intent->endsAt,
                    $intent->departureId,
                    null,
                );

                return $this->createSeatBooking($intent)->load(['holds', 'lineItems']);
            }

            $this->assertDuration($intent);
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
        $confirmed = $this->retry(fn (): Booking => $this->transaction(function () use ($booking): Booking {
            $this->enterTenant($booking->tenant_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Held) {
                throw new AllocationUnavailableException('Only a held booking can be confirmed.');
            }

            $intent = $this->intentFromBooking($booking);
            $holds = Hold::query()->where('booking_id', $booking->id)->get();
            $unexpired = $holds->isNotEmpty() && $holds->every(fn (Hold $hold): bool => $hold->expires_at->greaterThan(now()));
            $seatOnly = $booking->departure_id !== null
                && $holds->isNotEmpty()
                && $holds->every(fn (Hold $hold): bool => $hold->asset_id === null && $hold->pool_id === null);

            if ($unexpired && $seatOnly) {
                Hold::query()->where('booking_id', $booking->id)->delete();
                $booking->status = BookingStatus::Confirmed;
                $booking->save();

                return $booking->load(['allocations', 'lineItems']);
            }

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

        $this->events->confirmed($confirmed);

        return $confirmed;
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

                if ($booking->departure_id !== null) {
                    throw new AllocationUnavailableException('A departure booking keeps the departure time.');
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
                    $booking->departure_id,
                    $booking->guide_user_id,
                    variantId: $booking->service_variant_id,
                );
                $this->assertDuration($intent);
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

    public function cancel(Booking $booking, bool $recordHook = true): Booking
    {
        $cancelled = $this->retry(fn (): Booking => $this->transaction(function () use ($booking): Booking {
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

        if ($recordHook) {
            $this->events->cancelled($cancelled);
        }

        return $cancelled;
    }

    public function noShow(Booking $booking): Booking
    {
        return $this->retry(fn (): Booking => $this->transaction(function () use ($booking): Booking {
            $this->enterTenant($booking->tenant_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! in_array($booking->status, [BookingStatus::Held, BookingStatus::Confirmed], true)) {
                throw new AllocationUnavailableException('Only a held or confirmed booking can be marked as a no-show.');
            }

            $intent = $this->intentFromBooking($booking);
            $plan = $this->plan($intent);
            $this->lock($plan);
            Hold::query()->where('booking_id', $booking->id)->delete();
            Allocation::query()->where('booking_id', $booking->id)->delete();
            $booking->status = BookingStatus::NoShow;
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

            if ($booking->departure_id !== null) {
                throw new AllocationUnavailableException('Choose another departure to move a tour booking.');
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
                null,
                $booking->guide_user_id,
                variantId: $booking->service_variant_id,
            );
            $this->assertDuration($intent);
            $plan = $this->plan($intent);
            $this->lock($plan);

            try {
                $this->recheck($plan, $booking->id);
            } catch (AllocationUnavailableException $unavailable) {
                throw new AllocationUnavailableException($unavailable->getMessage(), [
                    'authoritative' => false,
                    'other_asset_ids' => $this->otherAssets($booking->id, $plan),
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
            $plan = $this->plan($intent, $fromAssetId);
            $plan = $this->swapAsset($plan, $booking, $fromAssetId, $toAssetId);
            $this->lock($plan);

            try {
                $this->recheck($plan, $booking->id);
            } catch (AllocationUnavailableException $unavailable) {
                throw new AllocationUnavailableException($unavailable->getMessage(), [
                    'authoritative' => false,
                    'other_asset_ids' => $this->otherAssets($booking->id, $plan),
                ]);
            }

            Allocation::query()->where('booking_id', $booking->id)->delete();
            BookingLineItem::query()->where('booking_id', $booking->id)->delete();
            $this->writeRows($booking, $plan, hold: false);
            $this->refreshCounters($plan);

            return $booking->load(['allocations', 'lineItems', 'service']);
        }));
    }

    public function changePartySize(Booking $booking, int $participants): Booking
    {
        return $this->retry(fn (): Booking => $this->transaction(function () use ($booking, $participants): Booking {
            $this->enterTenant($booking->tenant_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! in_array($booking->status, [BookingStatus::Held, BookingStatus::Confirmed], true)) {
                throw new AllocationUnavailableException('Only a held or confirmed booking can change party size.');
            }

            if ($participants < 1) {
                throw new AllocationUnavailableException('Choose a valid interval and party size.');
            }

            if ($participants === $booking->participants) {
                return $booking->load(['allocations', 'holds', 'lineItems', 'service', 'namedParticipants', 'addOns']);
            }

            $named = $booking->namedParticipants()->count();

            if ($participants < $named) {
                throw new AllocationUnavailableException('Party size cannot drop below the named participants already added.');
            }

            $service = Service::query()->find($booking->service_id);

            if ($service instanceof Service && $service->max_participants !== null && $participants > $service->max_participants) {
                throw new AllocationUnavailableException('That party is larger than this service allows.');
            }

            try {
                $this->assertDepartureParty($booking, $participants);
            } catch (AllocationUnavailableException $exception) {
                if ($exception->getMessage() !== 'That departure is full.') {
                    throw $exception;
                }

                throw new AllocationUnavailableException($exception->getMessage(), $this->partySizeOffers($booking, $participants, false));
            }

            if ($this->seatOnly($booking)) {
                $booking->participants = $participants;
                $booking->save();
                $this->repricePerPersonAddOns($booking, $participants);
                $this->operatorFees->repriceForParty($booking);
                Hold::query()
                    ->where('booking_id', $booking->id)
                    ->whereNull('asset_id')
                    ->whereNull('pool_id')
                    ->update(['quantity' => $participants]);

                return $booking->load(['allocations', 'holds', 'lineItems', 'service', 'namedParticipants', 'addOns']);
            }

            $intent = $this->intentFor($booking, $participants);
            $this->assertDuration($intent);
            $lockedResources = false;

            try {
                $plan = $this->plan($intent);
                $this->lock($plan);
                $lockedResources = true;
                $this->recheck($plan, $booking->id);
            } catch (AllocationUnavailableException $unavailable) {
                throw new AllocationUnavailableException(
                    $unavailable->getMessage(),
                    $this->partySizeOffers($booking, $participants, $lockedResources),
                );
            }

            $booking->participants = $participants;
            $booking->save();
            $this->repricePerPersonAddOns($booking, $participants);
            $this->operatorFees->repriceForParty($booking);
            $held = $booking->status === BookingStatus::Held;

            if ($held) {
                Hold::query()->where('booking_id', $booking->id)->delete();
            } else {
                Allocation::query()->where('booking_id', $booking->id)->delete();
            }

            BookingLineItem::query()->where('booking_id', $booking->id)->delete();
            $this->writeRows($booking, $plan, $held);
            $this->refreshCounters($plan);

            return $booking->load(['allocations', 'holds', 'lineItems', 'service', 'namedParticipants', 'addOns']);
        }));
    }

    public function checkout(Booking $booking): Booking
    {
        $checkedOut = $this->retry(fn (): Booking => $this->transaction(function () use ($booking): Booking {
            $this->enterTenant($booking->tenant_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new AllocationUnavailableException('Only a confirmed booking can be checked out.');
            }

            $existing = RentalCheckout::query()->where('booking_id', $booking->id)->exists();

            if ($existing) {
                return $booking->load('checkouts');
            }

            $allocations = Allocation::query()->where('booking_id', $booking->id)->get();

            if ($allocations->isEmpty()) {
                throw new AllocationUnavailableException('This booking has nothing to check out.');
            }

            foreach ($allocations as $allocation) {
                RentalCheckout::query()->create([
                    'tenant_id' => $booking->tenant_id,
                    'booking_id' => $booking->id,
                    'asset_id' => $allocation->asset_id,
                    'pool_id' => $allocation->pool_id,
                    'quantity' => $allocation->quantity,
                    'checked_out_at' => now(),
                    'due_at' => $booking->ends_at,
                ]);
            }

            return $booking->load('checkouts');
        }));

        $this->events->checkedOut($checkedOut);

        return $checkedOut;
    }

    /**
     * @param  list<array{asset_id: string, condition: string, notes: ?string, recorded_by: string}>|null  $conditions
     * @return array{booking: Booking, released_start: ?CarbonImmutable, released_end: ?CarbonImmutable, conditions: list<array{asset_id: string, condition: string, notes: ?string, recorded_by: string}>, maintained_asset_ids: list<string>}
     */
    public function returnRental(Booking $booking, ?array $conditions = null): array
    {
        $returned = $this->retry(fn (): array => $this->transaction(function () use ($booking, $conditions): array {
            $this->enterTenant($booking->tenant_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $open = RentalCheckout::query()
                ->where('booking_id', $booking->id)
                ->whereNull('returned_at')
                ->get();

            if ($open->isEmpty()) {
                throw new AllocationUnavailableException('Check this booking out before returning it.');
            }

            $intent = $this->intentFromBooking($booking);
            $plan = $this->plan($intent);
            $this->lock($plan);
            $now = CarbonImmutable::now()->utc();
            $releasedStart = $now;
            $releasedEnd = $now;
            $released = false;

            foreach (Allocation::query()->where('booking_id', $booking->id)->get() as $allocation) {
                $previousEnd = CarbonImmutable::instance($allocation->ends_at)->utc();
                $updatedEnd = $now->addMinutes($this->turnaroundFor($allocation->asset_id, $allocation->pool_id));
                $allocation->ends_at = Carbon::instance($updatedEnd);
                $allocation->save();

                if ($updatedEnd->lessThan($previousEnd)) {
                    $releasedStart = ! $released || $updatedEnd->lessThan($releasedStart) ? $updatedEnd : $releasedStart;
                    $releasedEnd = ! $released || $previousEnd->greaterThan($releasedEnd) ? $previousEnd : $releasedEnd;
                    $released = true;
                }

                BookingLineItem::query()
                    ->where('booking_id', $booking->id)
                    ->where('resource_id', $allocation->asset_id ?? $allocation->pool_id)
                    ->update(['buffered_ends_at' => $updatedEnd]);
            }

            RentalCheckout::query()
                ->where('booking_id', $booking->id)
                ->whereNull('returned_at')
                ->update(['returned_at' => $now]);

            foreach ($plan['demands'] as $index => $demand) {
                if ($demand['type'] !== ResourceType::Pool) {
                    continue;
                }

                $allocation = Allocation::query()
                    ->where('booking_id', $booking->id)
                    ->where('pool_id', $demand['id'])
                    ->first();

                if ($allocation === null) {
                    continue;
                }

                $bufferEnd = CarbonImmutable::instance($allocation->ends_at)->utc();
                $plan['demands'][$index]['buffer_end'] = $bufferEnd;
                $plan['demands'][$index]['buckets'] = $this->buckets->covering(
                    $demand['buffer_start'],
                    $bufferEnd,
                    $plan['bucket_minutes'],
                );
            }

            $this->refreshCounters($plan);
            $recorded = $this->returnConditions->record($booking, $open, $conditions);

            return [
                'booking' => $booking->load(['allocations', 'checkouts']),
                'released_start' => $released ? $releasedStart : null,
                'released_end' => $released ? $releasedEnd : null,
                'conditions' => $recorded['conditions'],
                'maintained_asset_ids' => $recorded['maintained_asset_ids'],
            ];
        }));

        $this->events->returned($returned['booking'], $returned['conditions']);

        return $returned;
    }

    /**
     * @return array{duration_ok: bool, offered_durations: list<array{name: string, quantity: int, unit: string, duration_minutes: int}>, available: bool}
     */
    public function hint(AllocationIntent $intent): array
    {
        $service = Service::query()->with('durations')->find($intent->serviceId);
        $offered = [];
        $variant = $service instanceof Service ? $this->findVariant($intent, $service) : null;

        if ($this->presentId($intent->variantId) !== null && $variant instanceof ServiceVariant) {
            $offered[] = [
                'name' => $variant->name,
                'quantity' => $variant->duration_minutes,
                'unit' => 'minutes',
                'duration_minutes' => $variant->duration_minutes,
            ];
        } elseif ($service instanceof Service) {
            foreach ($service->durations as $duration) {
                $offered[] = [
                    'name' => $duration->name,
                    'quantity' => $duration->quantity,
                    'unit' => $duration->unit->value,
                    'duration_minutes' => $duration->duration_minutes,
                ];
            }
        }

        $durationOk = $this->durationMatches($intent, $service);

        try {
            $plan = $this->plan($intent);
            $this->recheck($plan, null);
            $free = true;
        } catch (AllocationUnavailableException) {
            $free = false;
        }

        return [
            'duration_ok' => $durationOk,
            'offered_durations' => $offered,
            'available' => $free && $durationOk,
        ];
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
            $plan['demands'][$index]['buffer_end'] = $plan['ends_at']->addMinutes($this->tailMinutes($to));
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
     * A retired asset can be planned only as the source of a reassignment,
     * so an existing booking can move off it. A new hold still refuses it.
     *
     * @return Plan
     */
    private function plan(AllocationIntent $intent, ?string $reassignFromAssetId = null): array
    {
        $service = Service::query()->with(['location', 'requirements', 'durations'])->find($intent->serviceId);

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

        $guideUserId = $this->presentId($intent->guideUserId);
        $departureId = $this->presentId($intent->departureId);
        $requirements = $this->requirementsFor($service, $intent);

        if ($requirements === [] && $guideUserId === null) {
            throw new AllocationUnavailableException('This service has no resources.');
        }

        /** @var array<string, array{type: ResourceType, id: string, quantity: int, buffer_start: CarbonImmutable, buffer_end: CarbonImmutable, name: string, buckets: list<int>}> $merged */
        $merged = [];

        foreach ($requirements as $requirement) {
            $units = $requirement->unitsFor($intent->participants);
            $resource = $this->resource($requirement);
            $movingOffRetired = $resource instanceof Asset
                && $reassignFromAssetId !== null
                && $resource->id === $reassignFromAssetId
                && $resource->status === ResourceStatus::Retired;

            if ($resource === null || $resource->location_id !== $service->location_id || ($resource->status !== ResourceStatus::Active && ! $movingOffRetired)) {
                throw new AllocationUnavailableException('A required resource is not available.');
            }

            if ($requirement->resourceType() === ResourceType::Asset && $units !== 1) {
                throw new AllocationUnavailableException('An individual asset can only be held once.');
            }

            $bufferStart = $intent->startsAt->subMinutes($resource->prep_buffer_minutes);
            $bufferEnd = $intent->endsAt->addMinutes($this->tailMinutes($resource));
            $resourceOpen = $this->hours->assess(
                $service->location,
                $intent->startsAt,
                $intent->endsAt,
                $requirement->resourceType()->value,
                $resource->id,
            );

            if (! $resourceOpen['open']) {
                throw new AllocationUnavailableException('That resource is outside its hours or a blackout.');
            }

            $key = $requirement->resourceType()->value.':'.$resource->id;
            $buckets = $requirement->resourceType() === ResourceType::Pool
                ? $this->buckets->covering($bufferStart, $bufferEnd, $bucketMinutes)
                : [];

            if (isset($merged[$key])) {
                $merged[$key]['quantity'] += $units;

                continue;
            }

            $merged[$key] = [
                'type' => $requirement->resourceType(),
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
            'guide_user_id' => $guideUserId,
            'departure_id' => $departureId,
        ];
    }

    private function joiningOccupiedDeparture(AllocationIntent $intent): bool
    {
        if ($intent->departureId === null || $intent->departureId === '') {
            return false;
        }

        $departure = Departure::query()->find($intent->departureId);

        return $departure instanceof Departure && $departure->headcount() > 0;
    }

    private function createSeatBooking(AllocationIntent $intent): Booking
    {
        $service = Service::query()->findOrFail($intent->serviceId);
        $booking = Booking::query()->create([
            'tenant_id' => $intent->tenantId,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'service_variant_id' => $this->presentId($intent->variantId),
            'departure_id' => $intent->departureId,
            'guide_user_id' => $this->presentId($intent->guideUserId),
            'customer_id' => $this->customerId($intent),
            'status' => BookingStatus::Held,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $intent->startsAt->utc(),
            'ends_at' => $intent->endsAt->utc(),
            'participants' => $intent->participants,
            'idempotency_key' => $intent->idempotencyKey !== '' ? $intent->idempotencyKey : null,
            'payload_hash' => $intent->payloadHash(),
        ]);
        $this->writeAddOns($booking, $intent);
        $this->operatorFees->attach($booking);

        Hold::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'quantity' => $intent->participants,
            'starts_at' => $intent->startsAt->utc(),
            'ends_at' => $intent->endsAt->utc(),
            'expires_at' => now()->addMinutes($this->holdMinutes($booking->tenant_id)),
        ]);

        return $booking;
    }

    private function claimDeparture(AllocationIntent $intent): void
    {
        if ($intent->departureId === null || $intent->departureId === '') {
            return;
        }

        $locked = DB::select('select id from departures where id = ? for update', [$intent->departureId]);

        if ($locked === []) {
            throw new AllocationUnavailableException('That departure is not available.');
        }

        $departure = Departure::query()->with('service')->find($intent->departureId);

        if (! $departure instanceof Departure || $departure->service === null) {
            throw new AllocationUnavailableException('That departure is not available.');
        }

        if ($departure->status !== DepartureStatus::Scheduled) {
            throw new AllocationUnavailableException('That departure is cancelled.');
        }

        if ($departure->service->kind !== ServiceKind::Tour || $departure->service_id !== $intent->serviceId) {
            throw new AllocationUnavailableException('That departure is for a different service.');
        }

        $starts = CarbonImmutable::instance($departure->starts_at)->utc();
        $ends = CarbonImmutable::instance($departure->ends_at)->utc();

        if (! $starts->equalTo($intent->startsAt->utc()) || ! $ends->equalTo($intent->endsAt->utc())) {
            throw new AllocationUnavailableException('That departure has a different time.');
        }

        $headcount = $departure->headcount();

        if ($departure->private && $headcount > 0) {
            throw new AllocationUnavailableException('That private departure already has a booking.');
        }

        $cap = $departure->seats ?? $departure->service->max_participants;

        if ($cap !== null && $headcount + $intent->participants > $cap) {
            throw new AllocationUnavailableException('That departure is full.');
        }
    }

    private function resource(ConsumesInventory $requirement): Asset|Pool|null
    {
        if ($requirement->resourceType() === ResourceType::Asset) {
            return Asset::query()->find($requirement->resourceId());
        }

        return Pool::query()->find($requirement->resourceId());
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

        $this->lockGuide($plan['guide_user_id']);
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
                    $asset = Asset::query()->find($demand['id']);

                    throw new AllocationUnavailableException(
                        $asset instanceof Asset && $asset->is_guide ? 'That guide is already booked.' : 'That time is no longer available.',
                    );
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

        [$guideStart, $guideEnd] = $this->guideWindow($plan);
        $this->assertGuideFree($plan['guide_user_id'], $guideStart, $guideEnd, $plan['departure_id'], $ignoreBookingId);
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

        return $allocations || $holds || $this->checkoutOverlapsAsset($assetId, $start, $end, $ignoreBookingId);
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

        return $allocated + $held + $this->checkoutUnits($poolId, $start, $end, $ignoreBookingId);
    }

    /**
     * @param  Plan  $plan
     */
    private function createBooking(AllocationIntent $intent, array $plan, BookingStatus $status): Booking
    {
        $service = Service::query()->findOrFail($intent->serviceId);
        $customerId = $this->customerId($intent);

        $booking = Booking::query()->create([
            'tenant_id' => $intent->tenantId,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'service_variant_id' => $this->presentId($intent->variantId),
            'departure_id' => $this->presentId($intent->departureId),
            'guide_user_id' => $this->presentId($intent->guideUserId),
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
        $this->writeAddOns($booking, $intent);
        $this->operatorFees->attach($booking);

        return $booking;
    }

    private function repricePerPersonAddOns(Booking $booking, int $participants): void
    {
        $lines = BookingAddOn::query()->where('booking_id', $booking->id)->lockForUpdate()->get();

        foreach ($lines as $line) {
            if ($line->pricing !== AddOnPricing::PerPerson) {
                continue;
            }

            try {
                $total = AddOnCharge::total($line->pricing, $line->unit_amount_cents, $line->quantity, $participants);
            } catch (InvalidArgumentException $exception) {
                throw new AllocationUnavailableException($exception->getMessage());
            }

            if ($total > 2_000_000_000) {
                throw new AllocationUnavailableException('That add-on total is too large.');
            }

            if ($line->total_cents !== $total) {
                $line->total_cents = $total;
                $line->save();
            }
        }
    }

    private function writeAddOns(Booking $booking, AllocationIntent $intent): void
    {
        foreach ($intent->addOns as $line) {
            BookingAddOn::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'add_on_id' => $line['add_on_id'],
                'name' => $line['name'],
                'kind' => $line['kind'],
                'pricing' => $line['pricing'],
                'unit_amount_cents' => $line['unit_amount_cents'],
                'quantity' => $line['quantity'],
                'total_cents' => $line['total_cents'],
            ]);
        }
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

        if ($plan['demands'] === [] && $hold && $plan['guide_user_id'] !== null) {
            Hold::query()->create([
                'tenant_id' => $booking->tenant_id,
                'booking_id' => $booking->id,
                'quantity' => $booking->participants,
                'starts_at' => $plan['starts_at'],
                'ends_at' => $plan['ends_at'],
                'expires_at' => $expires,
            ]);
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

        $phone = $intent->customerPhone !== null && $intent->customerPhone !== '' ? $intent->customerPhone : null;

        if ($intent->customerEmail !== null && $intent->customerEmail !== '') {
            $customer = Customer::query()->firstOrCreate(
                ['tenant_id' => $intent->tenantId, 'email' => strtolower($intent->customerEmail)],
                [
                    'name' => $intent->customerName !== null && $intent->customerName !== '' ? $intent->customerName : $intent->customerEmail,
                    'phone' => $phone,
                ],
            );

            if ($phone !== null && ($customer->phone === null || $customer->phone === '')) {
                $customer->phone = $phone;
                $customer->save();
            }

            return $customer->id;
        }

        return Customer::query()->create([
            'tenant_id' => $intent->tenantId,
            'name' => (string) $intent->customerName,
            'phone' => $phone,
        ])->id;
    }

    private function intentFromBooking(Booking $booking): AllocationIntent
    {
        return $this->intentFor($booking, $booking->participants);
    }

    private function intentFor(Booking $booking, int $participants): AllocationIntent
    {
        return new AllocationIntent(
            $booking->tenant_id,
            $booking->service_id,
            CarbonImmutable::instance($booking->starts_at)->utc(),
            CarbonImmutable::instance($booking->ends_at)->utc(),
            $participants,
            null,
            null,
            null,
            $booking->departure_id,
            $booking->guide_user_id,
            variantId: $booking->service_variant_id,
        );
    }

    private function assertDepartureParty(Booking $booking, int $participants): void
    {
        if ($booking->departure_id === null || $booking->departure_id === '') {
            return;
        }

        $locked = DB::select('select id from departures where id = ? for update', [$booking->departure_id]);

        if ($locked === []) {
            throw new AllocationUnavailableException('That departure is not available.');
        }

        $departure = Departure::query()->with('service')->find($booking->departure_id);

        if (! $departure instanceof Departure || $departure->service === null) {
            throw new AllocationUnavailableException('That departure is not available.');
        }

        if ($departure->status !== DepartureStatus::Scheduled) {
            throw new AllocationUnavailableException('That departure is cancelled.');
        }

        $others = (int) $departure->bookings()
            ->where('id', '!=', $booking->id)
            ->whereIn('status', [
                BookingStatus::Held->value,
                BookingStatus::Confirmed->value,
                BookingStatus::PendingPayment->value,
            ])
            ->where(function ($query): void {
                $query->whereHas('allocations')
                    ->orWhereHas('holds', fn ($holds) => $holds->where('expires_at', '>', now()));
            })
            ->sum('participants');

        if ($departure->private && $others > 0) {
            throw new AllocationUnavailableException('That private departure already has a booking.');
        }

        $cap = $departure->seats ?? $departure->service->max_participants;

        if ($cap !== null && $others + $participants > $cap && $participants >= $booking->participants) {
            throw new AllocationUnavailableException('That departure is full.');
        }
    }

    private function seatOnly(Booking $booking): bool
    {
        if ($booking->departure_id === null || $booking->departure_id === '') {
            return false;
        }

        $allocated = Allocation::query()
            ->where('booking_id', $booking->id)
            ->where(function ($query): void {
                $query->whereNotNull('asset_id')->orWhereNotNull('pool_id');
            })
            ->exists();

        if ($allocated) {
            return false;
        }

        return ! Hold::query()
            ->where('booking_id', $booking->id)
            ->where(function ($query): void {
                $query->whereNotNull('asset_id')->orWhereNotNull('pool_id');
            })
            ->exists();
    }

    /**
     * @return array{authoritative: false, smaller_participants: int|null, other_asset_ids: list<string>}
     */
    private function partySizeOffers(Booking $booking, int $requested, bool $resourcesLocked): array
    {
        $smaller = null;

        for ($size = $requested - 1; $size > $booking->participants; $size--) {
            try {
                if (! $resourcesLocked) {
                    $this->assertDepartureParty($booking, $size);
                }

                if ($this->seatOnly($booking)) {
                    $smaller = $size;
                    break;
                }

                $plan = $this->plan($this->intentFor($booking, $size));

                if (! $resourcesLocked) {
                    $this->lock($plan);
                    $resourcesLocked = true;
                }

                $this->recheck($plan, $booking->id);
                $smaller = $size;
                break;
            } catch (AllocationUnavailableException) {
                continue;
            }
        }

        $other = [];

        if (! $this->seatOnly($booking)) {
            try {
                $other = $this->otherAssets($booking->id, $this->plan($this->intentFor($booking, $requested)));
            } catch (AllocationUnavailableException) {
                $other = [];
            }
        }

        return [
            'authoritative' => false,
            'smaller_participants' => $smaller,
            'other_asset_ids' => $other,
        ];
    }

    /**
     * Hints for a refused hold. Not a lock, and not a promise the next hold will succeed.
     *
     * @return array{authoritative: false, times: list<array{starts_at: string, ends_at: string}>, other_asset_ids: list<string>}
     */
    private function holdOffers(AllocationIntent $intent): array
    {
        $this->enterTenant($intent->tenantId);

        return [
            'authoritative' => false,
            'times' => $this->nearbyTimes($intent),
            'other_asset_ids' => $this->holdAssetOffers($intent),
        ];
    }

    /**
     * @return list<string>
     */
    private function holdAssetOffers(AllocationIntent $intent): array
    {
        try {
            return $this->otherAssets(null, $this->plan($intent));
        } catch (AllocationUnavailableException) {
            return [];
        }
    }

    /**
     * @return list<array{starts_at: string, ends_at: string}>
     */
    private function nearbyTimes(AllocationIntent $intent): array
    {
        $service = Service::query()->with('location')->find($intent->serviceId);
        $location = $service?->location;

        if (! $service instanceof Service || ! $location instanceof Location) {
            return [];
        }

        if ($service->kind === ServiceKind::Tour) {
            return $this->nearbyDepartures($service, $location, $intent);
        }

        $seconds = $intent->endsAt->utc()->getTimestamp() - $intent->startsAt->utc()->getTimestamp();

        if ($seconds < 60 || $seconds % 60 !== 0) {
            return [];
        }

        return $this->nearbyRentalTimes($location, $intent, intdiv($seconds, 60));
    }

    /**
     * Same service, within two hours of the requested clock time, on that local day and the next two.
     *
     * @return list<array{starts_at: string, ends_at: string}>
     */
    private function nearbyRentalTimes(Location $location, AllocationIntent $intent, int $durationMinutes): array
    {
        $requestedLocal = $intent->startsAt->utc()->timezone($location->timezone);
        $now = CarbonImmutable::now()->utc();
        $step = SlotFinder::STEP_MINUTES;
        $offsets = [0];

        for ($minute = $step; $minute <= $step * 4; $minute += $step) {
            $offsets[] = $minute;
            $offsets[] = -$minute;
        }

        $picked = [];

        for ($day = 0; $day <= 2; $day++) {
            $kept = [];
            $limit = $day === 0 ? 3 : 2;
            $base = $requestedLocal->addDays($day);

            foreach ($offsets as $offset) {
                if (count($kept) >= $limit) {
                    break;
                }

                if ($day === 0 && $offset === 0) {
                    continue;
                }

                $startLocal = $base->addMinutes($offset);

                if (! $startLocal->isSameDay($base)) {
                    continue;
                }

                $start = $startLocal->utc();

                if ($start->lessThan($now)) {
                    continue;
                }

                $end = $start->addMinutes($durationMinutes);

                if (! $this->slotFree($intent, $start, $end)) {
                    continue;
                }

                $kept[] = [
                    'starts_at' => $start->format('Y-m-d\TH:i:s\Z'),
                    'ends_at' => $end->format('Y-m-d\TH:i:s\Z'),
                ];
            }

            $picked = array_merge($picked, $kept);
        }

        usort($picked, fn (array $left, array $right): int => $left['starts_at'] <=> $right['starts_at']);

        return $picked;
    }

    /**
     * @return list<array{starts_at: string, ends_at: string}>
     */
    private function nearbyDepartures(Service $service, Location $location, AllocationIntent $intent): array
    {
        $requestedLocal = $intent->startsAt->utc()->timezone($location->timezone);
        $now = CarbonImmutable::now()->utc();
        $from = $requestedLocal->startOfDay()->utc();
        $until = $requestedLocal->startOfDay()->addDays(3)->utc();
        $clock = ($requestedLocal->hour * 60) + $requestedLocal->minute;
        $requestedStart = $intent->startsAt->utc();
        $requestedEnd = $intent->endsAt->utc();
        $times = [];

        $departures = Departure::query()
            ->where('service_id', $service->id)
            ->where('status', DepartureStatus::Scheduled->value)
            ->where('starts_at', '>=', $from->max($now))
            ->where('starts_at', '<', $until)
            ->orderBy('starts_at')
            ->get();

        foreach ($departures as $departure) {
            if (count($times) >= 6) {
                break;
            }

            $starts = CarbonImmutable::instance($departure->starts_at)->utc();
            $ends = CarbonImmutable::instance($departure->ends_at)->utc();

            if ($starts->equalTo($requestedStart) && $ends->equalTo($requestedEnd)) {
                continue;
            }

            $localStart = $starts->timezone($location->timezone);
            $minutes = ($localStart->hour * 60) + $localStart->minute;

            if (abs($minutes - $clock) > SlotFinder::STEP_MINUTES * 4) {
                continue;
            }

            $headcount = $departure->headcount();

            if ($departure->private && $headcount > 0) {
                continue;
            }

            $cap = $departure->seats ?? $service->max_participants;

            if ($cap !== null && $headcount + $intent->participants > $cap) {
                continue;
            }

            if (! $this->slotFree($intent, $starts, $ends, $departure->id)) {
                continue;
            }

            $times[] = [
                'starts_at' => $starts->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $ends->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        return $times;
    }

    private function slotFree(AllocationIntent $intent, CarbonImmutable $start, CarbonImmutable $end, ?string $departureId = null): bool
    {
        return $this->hint(new AllocationIntent(
            $intent->tenantId,
            $intent->serviceId,
            $start,
            $end,
            $intent->participants,
            null,
            null,
            null,
            $departureId,
            $intent->guideUserId,
            variantId: $intent->variantId,
        ))['available'];
    }

    private function availabilityRefusal(AllocationUnavailableException $exception): bool
    {
        return in_array($exception->getMessage(), [
            'That time is no longer available.',
            'That guide is already booked.',
            'That departure is full.',
            'That private departure already has a booking.',
        ], true);
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
                $buffer = $resource === null ? 0 : $this->tailMinutes($resource);
                $shorterPlan['demands'][$index]['buffer_end'] = $shorter->addMinutes($buffer);
                if ($demand['type'] === ResourceType::Pool) {
                    $shorterPlan['demands'][$index]['buckets'] = $this->buckets->covering(
                        $demand['buffer_start'],
                        $shorterPlan['demands'][$index]['buffer_end'],
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
            'other_asset_ids' => $this->otherAssets($booking->id, $plan),
        ];
    }

    /**
     * @param  Plan  $plan
     * @return list<string>
     */
    private function otherAssets(?string $ignoreBookingId, array $plan): array
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
                if (! $this->assetTaken($candidate->id, $demand['buffer_start'], $demand['buffer_end'], $ignoreBookingId)) {
                    $ids[] = $candidate->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function presentId(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $id;
    }

    private function tailMinutes(Asset|Pool $resource): int
    {
        return $resource->return_buffer_minutes + $resource->turnaround_minutes;
    }

    private function turnaroundFor(?string $assetId, ?string $poolId): int
    {
        if ($assetId !== null) {
            $asset = Asset::query()->find($assetId);

            return $asset instanceof Asset ? $asset->turnaround_minutes : 0;
        }

        if ($poolId !== null) {
            $pool = Pool::query()->find($poolId);

            return $pool instanceof Pool ? $pool->turnaround_minutes : 0;
        }

        return 0;
    }

    /**
     * @return list<ConsumesInventory>
     */
    private function requirementsFor(Service $service, AllocationIntent $intent): array
    {
        $variant = $this->chosenVariant($intent, $service);

        if ($variant instanceof ServiceVariant) {
            $mapped = [];

            foreach ($variant->resources()->orderBy('id')->get() as $row) {
                $mapped[] = $row;
            }

            if ($mapped !== []) {
                return $mapped;
            }
        }

        $requirements = [];

        foreach ($service->requirements as $requirement) {
            $requirements[] = $requirement;
        }

        return $requirements;
    }

    private function chosenVariant(AllocationIntent $intent, Service $service): ?ServiceVariant
    {
        if ($this->presentId($intent->variantId) === null) {
            return null;
        }

        $variant = $this->findVariant($intent, $service);

        if (! $variant instanceof ServiceVariant) {
            throw new AllocationUnavailableException('That variant is not available.');
        }

        return $variant;
    }

    private function findVariant(AllocationIntent $intent, Service $service): ?ServiceVariant
    {
        $id = $this->presentId($intent->variantId);

        if ($id === null) {
            return null;
        }

        $variant = ServiceVariant::query()->where('service_id', $service->id)->find($id);

        return $variant instanceof ServiceVariant ? $variant : null;
    }

    private function assertDuration(AllocationIntent $intent): void
    {
        $service = Service::query()->with('durations')->find($intent->serviceId);
        $variant = $service instanceof Service ? $this->chosenVariant($intent, $service) : null;

        if (! $this->durationMatches($intent, $service instanceof Service ? $service : null)) {
            throw new AllocationUnavailableException($variant instanceof ServiceVariant
                ? 'Choose the length offered for this variant.'
                : 'Choose a rental length offered for this service.');
        }
    }

    private function durationMatches(AllocationIntent $intent, ?Service $service): bool
    {
        if (! $service instanceof Service || $this->presentId($intent->departureId) !== null) {
            return true;
        }

        if ($this->presentId($intent->variantId) !== null) {
            $variant = $this->findVariant($intent, $service);

            if (! $variant instanceof ServiceVariant) {
                return false;
            }

            $elapsed = $intent->endsAt->utc()->getTimestamp() - $intent->startsAt->utc()->getTimestamp();

            return $elapsed > 0 && $elapsed % 60 === 0 && intdiv($elapsed, 60) === $variant->duration_minutes;
        }

        if ($service->durations->isEmpty()) {
            return true;
        }

        $elapsed = $intent->endsAt->utc()->getTimestamp() - $intent->startsAt->utc()->getTimestamp();

        if ($elapsed <= 0 || $elapsed % 60 !== 0) {
            return false;
        }

        $minutes = intdiv($elapsed, 60);

        return $service->durations->contains(
            fn (ServiceDuration $duration): bool => $duration->duration_minutes === $minutes,
        );
    }

    private function lockGuide(?string $guideUserId): void
    {
        if ($guideUserId === null || $guideUserId === '') {
            return;
        }

        $rows = DB::select('select id from users where id = ? for update', [$guideUserId]);

        if ($rows === []) {
            throw new AllocationUnavailableException('That guide is already booked.');
        }
    }

    private function assertGuideFree(
        ?string $guideUserId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $departureId,
        ?string $ignoreBookingId,
    ): void {
        if ($guideUserId === null || $guideUserId === '') {
            return;
        }

        $conflict = Booking::query()
            ->where('guide_user_id', $guideUserId)
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->when($departureId !== null && $departureId !== '', function ($query) use ($departureId): void {
                $query->where(function ($inner) use ($departureId): void {
                    $inner->whereNull('departure_id')->orWhere('departure_id', '!=', $departureId);
                });
            })
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($customer) use ($start, $end): void {
                    $customer->where('starts_at', '<', $end)->where('ends_at', '>', $start);
                })->orWhereHas('allocations', function ($rows) use ($start, $end): void {
                    $rows->where('starts_at', '<', $end)->where('ends_at', '>', $start);
                })->orWhereHas('holds', function ($rows) use ($start, $end): void {
                    $rows->where('expires_at', '>', now())
                        ->where('starts_at', '<', $end)
                        ->where('ends_at', '>', $start);
                });
            })
            ->where(function ($query): void {
                $query->where('status', BookingStatus::Confirmed)
                    ->orWhere(function ($held): void {
                        $held->where('status', BookingStatus::Held)
                            ->whereHas('holds', fn ($holds) => $holds->where('expires_at', '>', now()));
                    });
            })
            ->exists();

        if ($conflict) {
            throw new AllocationUnavailableException('That guide is already booked.');
        }
    }

    /**
     * @param  Plan  $plan
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function guideWindow(array $plan): array
    {
        if ($plan['demands'] === []) {
            return [$plan['starts_at'], $plan['ends_at']];
        }

        $start = $plan['demands'][0]['buffer_start'];
        $end = $plan['demands'][0]['buffer_end'];

        foreach ($plan['demands'] as $demand) {
            if ($demand['buffer_start']->lessThan($start)) {
                $start = $demand['buffer_start'];
            }

            if ($demand['buffer_end']->greaterThan($end)) {
                $end = $demand['buffer_end'];
            }
        }

        return [$start, $end];
    }

    private function checkoutOverlapsAsset(string $assetId, CarbonImmutable $start, CarbonImmutable $end, ?string $ignoreBookingId): bool
    {
        $checkouts = RentalCheckout::query()
            ->where('asset_id', $assetId)
            ->whereNull('returned_at')
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('booking_id', '!=', $ignoreBookingId))
            ->get();

        foreach ($checkouts as $checkout) {
            $occupiedStart = CarbonImmutable::parse($checkout->checked_out_at)->utc();
            $occupiedEnd = $this->activeCheckoutEnd($checkout);

            if ($occupiedStart->lessThan($end) && $occupiedEnd->greaterThan($start)) {
                return true;
            }
        }

        return false;
    }

    private function checkoutUnits(string $poolId, CarbonImmutable $start, CarbonImmutable $end, ?string $ignoreBookingId): int
    {
        $extra = 0;
        $checkouts = RentalCheckout::query()
            ->where('pool_id', $poolId)
            ->whereNull('returned_at')
            ->when($ignoreBookingId !== null, fn ($query) => $query->where('booking_id', '!=', $ignoreBookingId))
            ->get();

        foreach ($checkouts as $checkout) {
            $occupiedStart = CarbonImmutable::parse($checkout->checked_out_at)->utc();
            $occupiedEnd = $this->activeCheckoutEnd($checkout);

            if ($occupiedStart->greaterThanOrEqualTo($end) || $occupiedEnd->lessThanOrEqualTo($start)) {
                continue;
            }

            $covered = Allocation::query()
                ->where('booking_id', $checkout->booking_id)
                ->where('pool_id', $poolId)
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->exists();

            if (! $covered) {
                $extra += $checkout->quantity;
            }
        }

        return $extra;
    }

    private function activeCheckoutEnd(RentalCheckout $checkout): CarbonImmutable
    {
        $due = CarbonImmutable::parse($checkout->due_at)->utc();
        $now = CarbonImmutable::now()->utc();
        $until = $due->greaterThan($now) ? $due : $now;

        return $until->addMinutes($this->turnaroundFor($checkout->asset_id, $checkout->pool_id));
    }
}
