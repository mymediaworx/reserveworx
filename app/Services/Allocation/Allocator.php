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
use App\Models\CorporateAccount;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\FleetItem;
use App\Models\Hold;
use App\Models\Location;
use App\Models\MaintenanceWindow;
use App\Models\Pool;
use App\Models\RentalCheckout;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Booking\GuideRoster;
use App\Services\Booking\SlotFinder;
use App\Services\Catalog\BookingOperatorFeeLines;
use App\Services\Customers\CustomerAccountLinker;
use App\Services\Fleet\FleetCapacity;
use App\Services\Inventory\OpeningHours;
use App\Services\Notifications\BookingEvents;
use App\Services\Operations\ReturnConditions;
use App\Services\Pricing\PromoCodes;
use App\Services\Webhooks\WebhookPublisher;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\StaffLocationScope;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @phpstan-type Demand array{type: ResourceType, id: string, quantity: int, buffer_start: CarbonImmutable, buffer_end: CarbonImmutable, name: string, buckets: list<int>}
 * @phpstan-type Plan array{demands: list<Demand>, bucket_minutes: int, location: Location, starts_at: CarbonImmutable, ends_at: CarbonImmutable, guide_user_id: ?string, departure_id: ?string, counter_sale: bool}
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
        private readonly WebhookPublisher $webhooks,
        private readonly CustomerAccountLinker $customerAccounts,
        private readonly PromoCodes $promoCodes,
        private readonly GuideRoster $guideRoster,
        private readonly FleetCapacity $fleetCapacity,
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
                && $holds->every(fn (Hold $hold): bool => $hold->asset_id === null && $hold->pool_id === null && $hold->fleet_item_id === null);

            if ($unexpired && $seatOnly) {
                Hold::query()->where('booking_id', $booking->id)->delete();
                $this->saveConfirmed($booking);
                $this->webhooks->bookingChanged($booking);

                return $booking->load(['allocations', 'lineItems']);
            }

            if ($unexpired) {
                $plan = $this->plan($intent);
                $this->lock($plan);
                $this->recheck($plan, $booking->id, honorMaintenance: false);
                Hold::query()->where('booking_id', $booking->id)->delete();
                BookingLineItem::query()->where('booking_id', $booking->id)->delete();
                $this->writeRows($booking, $plan, hold: false);
                $this->saveConfirmed($booking);
                $this->refreshCounters($plan);
                $this->webhooks->bookingChanged($booking);

                return $booking->load(['allocations', 'lineItems']);
            }

            Hold::query()->where('booking_id', $booking->id)->delete();

            try {
                $plan = $this->plan($intent);
                $this->lock($plan);
                $this->recheck($plan, $booking->id, honorMaintenance: false);
            } catch (AllocationUnavailableException) {
                $booking->status = BookingStatus::PaymentException;
                $booking->save();
                $this->webhooks->bookingChanged($booking);

                return $booking;
            }

            BookingLineItem::query()->where('booking_id', $booking->id)->delete();
            $this->writeRows($booking, $plan, hold: false);
            $this->saveConfirmed($booking);
            $this->refreshCounters($plan);
            $this->webhooks->bookingChanged($booking);

            return $booking->load(['allocations', 'lineItems']);
        }));

        $this->events->confirmed($confirmed);

        return $confirmed;
    }

    private function saveConfirmed(Booking $booking): void
    {
        $booking->status = BookingStatus::Confirmed;
        $booking->save();
        $this->promoCodes->record($booking);
    }

    /**
     * Lengthen a confirmed rental and, when asked, move it onto another asset
     * of the same type. One transaction takes the allocation locks for the
     * extra interval. A conflict leaves the booking where it was and offers
     * the longest shorter end that fits, plus any other free asset of the same type.
     *
     * @return array{booking: Booking, offers: array<string, mixed>}
     */
    public function extend(Booking $booking, CarbonImmutable $newEnd, ?string $toAssetId = null): array
    {
        try {
            $saved = $this->retry(fn (): Booking => $this->transaction(function () use ($booking, $newEnd, $toAssetId): Booking {
                $this->enterTenant($booking->tenant_id);
                $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

                if ($booking->status !== BookingStatus::Confirmed) {
                    throw new AllocationUnavailableException('Only a confirmed booking can be extended.');
                }

                if (in_array($booking->operational_status, [OperationalStatus::Returned, OperationalStatus::Completed], true)) {
                    throw new AllocationUnavailableException('This rental is already finished.');
                }

                $service = Service::query()->find($booking->service_id);

                if (! $service instanceof Service || $service->kind !== ServiceKind::Rental) {
                    throw new AllocationUnavailableException('Only a rental can be extended.');
                }

                if ($booking->departure_id !== null) {
                    throw new AllocationUnavailableException('A departure booking keeps the departure time.');
                }

                $newEnd = $newEnd->utc();

                if ($newEnd->lessThanOrEqualTo($booking->ends_at)) {
                    throw new AllocationUnavailableException('The new end must be after the current end.');
                }

                $fromAssetId = $this->allocatedAssetId($booking, null);
                $target = $this->presentId($toAssetId);

                if ($target !== null && $fromAssetId === null) {
                    throw new AllocationUnavailableException('That asset is not on this booking.');
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
                $plan = $this->plannedMove($booking, $intent, $fromAssetId, $target ?? $fromAssetId);
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
                $this->webhooks->bookingChanged($booking);

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

            if ($booking->wasChanged('status')) {
                $this->webhooks->bookingCancelled($booking);
            }

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
            $this->webhooks->bookingChanged($booking);

            return $booking->load(['allocations', 'lineItems', 'service']);
        }));
    }

    /**
     * Move a confirmed tour booking onto another scheduled departure of the same
     * service. The party size and the pricing snapshot stay. A conflict rolls
     * the whole move back, including any boat handed to the party that stays.
     */
    public function moveToDeparture(Booking $booking, string $departureId): Booking
    {
        return $this->retry(fn (): Booking => $this->transaction(function () use ($booking, $departureId): Booking {
            $this->enterTenant($booking->tenant_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new AllocationUnavailableException('Only a confirmed booking can be rescheduled.');
            }

            if ($this->presentId($booking->departure_id) === null) {
                throw new AllocationUnavailableException('Choose a time to move a rental.');
            }

            $target = Departure::query()->with('service')->find($departureId);

            if (! $target instanceof Departure) {
                throw new AllocationUnavailableException('That departure is not available.');
            }

            if ($target->id === $booking->departure_id) {
                return $booking->load(['allocations', 'lineItems', 'service']);
            }

            $this->assertSameTour($target, $booking);
            $this->lockDepartures([(string) $booking->departure_id, $target->id]);
            $target = Departure::query()->with('service')->find($target->id);

            if (! $target instanceof Departure) {
                throw new AllocationUnavailableException('That departure is not available.');
            }

            $this->assertSameTour($target, $booking);
            $this->assertTargetSeats($target, $booking);

            $starts = CarbonImmutable::instance($target->starts_at)->utc();
            $ends = CarbonImmutable::instance($target->ends_at)->utc();
            $guide = $this->guideForMovedDeparture($booking, $target);
            $occupied = $this->departureHasResourceHolder($target, $booking->id);

            if (! $this->seatOnly($booking)) {
                $inheritor = $this->resourceInheritor($booking);

                if ($inheritor instanceof Booking) {
                    $this->handResources($booking, $inheritor);
                } else {
                    $this->releaseResources($booking);
                }
            }

            $booking->departure_id = $target->id;
            $booking->starts_at = Carbon::instance($starts);
            $booking->ends_at = Carbon::instance($ends);
            $booking->guide_user_id = $guide;
            $booking->save();

            if (! $occupied) {
                $intent = new AllocationIntent(
                    $booking->tenant_id,
                    $booking->service_id,
                    $starts,
                    $ends,
                    $booking->participants,
                    null,
                    null,
                    null,
                    $target->id,
                    $guide,
                    variantId: $booking->service_variant_id,
                );
                $this->assertDuration($intent);
                $plan = $this->plan($intent);
                $this->lock($plan);
                $this->recheck($plan, $booking->id);
                $this->writeRows($booking, $plan, hold: false);
                $this->refreshCounters($plan);
            } else {
                $this->lockGuide($guide);
                $this->assertGuideFree($guide, $starts, $ends, $target->id, $booking->id);
            }

            $this->webhooks->bookingChanged($booking);

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
            $this->webhooks->bookingChanged($booking);

            return $booking->load(['allocations', 'lineItems', 'service']);
        }));
    }

    /**
     * Name or clear the staff guide on a held or confirmed booking.
     * Live parties on the same departure share that guide. The price,
     * allocations, and payments stay as they are.
     *
     * @return list<Booking>
     */
    public function assignGuide(Booking $booking, ?string $guideUserId): array
    {
        return $this->retry(fn (): array => $this->transaction(function () use ($booking, $guideUserId): array {
            $this->enterTenant($booking->tenant_id);
            $guideUserId = $this->presentId($guideUserId);
            $seed = Booking::query()->withoutGlobalScope(StaffLocationScope::class)->find($booking->id);

            if (! $seed instanceof Booking) {
                throw new AllocationUnavailableException('Only a held or confirmed booking can be assigned a guide.');
            }

            $departureId = $this->presentId($seed->departure_id);
            $locked = Booking::query()
                ->withoutGlobalScope(StaffLocationScope::class)
                ->when(
                    $departureId !== null,
                    fn (Builder $query): Builder => $query->where('departure_id', $departureId),
                    fn (Builder $query): Builder => $query->whereKey($seed->id),
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $parties = $locked->filter(fn (Booking $row): bool => $this->canAssignGuide($row))->values();
            $target = $parties->firstWhere('id', $seed->id);

            if (! $target instanceof Booking) {
                $current = $locked->firstWhere('id', $seed->id);

                throw new AllocationUnavailableException(
                    $current instanceof Booking && $current->status === BookingStatus::Held
                        ? 'That hold has expired.'
                        : 'Only a held or confirmed booking can be assigned a guide.',
                );
            }

            if ($guideUserId !== null) {
                $this->assertActiveGuide($guideUserId);
                $this->lockGuide($guideUserId);
                [$start, $end] = $this->sharedGuideWindow($parties);
                $this->assertGuideFree($guideUserId, $start, $end, $departureId, $target->id);
            }

            $changed = [];

            foreach ($parties as $party) {
                $saved = $this->storeGuide($party, $guideUserId);

                if ($saved instanceof Booking) {
                    $changed[] = $saved;
                }
            }

            if ($changed === []) {
                return [$target];
            }

            return $changed;
        }));
    }

    /**
     * Move a confirmed booking to a new start, another asset in the same category
     * and location, or both. The duration stays the same. One transaction takes
     * the same locks as a new allocation, keeps this booking out of the recheck,
     * and leaves the original rows when that recheck fails.
     *
     * A refusal because the time or asset is taken adds the nearby times and
     * same-category assets a refused hold already offers. Those offers are hints.
     */
    public function move(Booking $booking, CarbonImmutable $newStart, CarbonImmutable $newEnd, ?string $toAssetId = null, ?string $fromAssetId = null): Booking
    {
        try {
            return $this->retry(fn (): Booking => $this->transaction(function () use ($booking, $newStart, $newEnd, $toAssetId, $fromAssetId): Booking {
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

                $fromAssetId = $this->allocatedAssetId($booking, $fromAssetId);
                $targetAssetId = $this->presentId($toAssetId) ?? $fromAssetId;

                if ($targetAssetId !== null && $fromAssetId === null) {
                    throw new AllocationUnavailableException('That asset is not on this booking.');
                }

                $timeChanges = ! CarbonImmutable::instance($booking->starts_at)->utc()->equalTo($newStart)
                    || ! CarbonImmutable::instance($booking->ends_at)->utc()->equalTo($newEnd);
                $assetChanges = $fromAssetId !== null && $targetAssetId !== null && $targetAssetId !== $fromAssetId;

                if (! $timeChanges && ! $assetChanges) {
                    return $booking->load(['allocations', 'lineItems', 'service']);
                }

                if ($timeChanges && $this->presentId($booking->departure_id) !== null) {
                    throw new AllocationUnavailableException('Choose another departure to move a tour booking.');
                }

                $intent = $this->intentAt($booking, $newStart, $newEnd);
                $plan = $this->plannedMove($booking, $intent, $fromAssetId, $assetChanges ? $targetAssetId : $fromAssetId);
                $this->lock($plan);
                $this->recheck($plan, $booking->id);

                if ($timeChanges) {
                    $booking->starts_at = Carbon::instance($newStart);
                    $booking->ends_at = Carbon::instance($newEnd);
                    $booking->save();
                }

                Allocation::query()->where('booking_id', $booking->id)->delete();
                BookingLineItem::query()->where('booking_id', $booking->id)->delete();
                $this->writeRows($booking, $plan, hold: false);
                $this->refreshCounters($plan);
                $this->webhooks->bookingChanged($booking);

                return $booking->load(['allocations', 'lineItems', 'service']);
            }));
        } catch (AllocationUnavailableException $exception) {
            if ($exception->offers !== [] || ! $this->availabilityRefusal($exception)) {
                throw $exception;
            }

            try {
                $offers = $this->moveOffers($booking, $newStart, $newEnd, $fromAssetId, $toAssetId);
            } catch (\Throwable) {
                throw $exception;
            }

            if ($offers['times'] === [] && $offers['other_asset_ids'] === []) {
                throw $exception;
            }

            throw new AllocationUnavailableException($exception->getMessage(), $offers);
        }
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
                    ->whereNull('fleet_item_id')
                    ->update(['quantity' => $participants]);
                $this->webhooks->bookingChanged($booking);

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
            $this->webhooks->bookingChanged($booking);

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

            if (in_array($booking->operational_status, [OperationalStatus::NotArrived, OperationalStatus::CheckedIn], true)) {
                $this->markOperational($booking, OperationalStatus::InProgress);
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

                $resourceId = $allocation->asset_id ?? $allocation->pool_id ?? $allocation->fleet_item_id;

                if (is_string($resourceId) && $resourceId !== '') {
                    BookingLineItem::query()
                        ->where('booking_id', $booking->id)
                        ->where('resource_id', $resourceId)
                        ->update(['buffered_ends_at' => $updatedEnd]);
                }
            }

            RentalCheckout::query()
                ->where('booking_id', $booking->id)
                ->whereNull('returned_at')
                ->update(['returned_at' => $now]);

            if ($booking->operational_status !== OperationalStatus::Completed) {
                $this->markOperational($booking, OperationalStatus::Returned);
            }

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
    private function swapAsset(array $plan, Booking $booking, string $fromAssetId, string $toAssetId, bool $keepCurrent = false): array
    {
        $from = Asset::query()->find($fromAssetId);
        $to = Asset::query()->find($toAssetId);
        $keepsCurrentAssignment = $keepCurrent && $to instanceof Asset && $to->location_id === $booking->location_id;

        if (! $to instanceof Asset || $to->location_id !== $booking->location_id || ($to->status !== ResourceStatus::Active && ! $keepsCurrentAssignment)) {
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
            $this->refuseClosed($open, true);

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
        usort($demands, $this->compareDemands(...));
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

    private function markOperational(Booking $booking, OperationalStatus $status): void
    {
        if ($booking->operational_status === $status) {
            return;
        }

        $booking->operational_status = $status;
        $booking->save();
    }

    private function replayIdempotentHold(AllocationIntent $intent, QueryException $exception): Booking
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062 || $intent->idempotencyKey === null || $intent->idempotencyKey === '') {
            throw $exception;
        }

        $this->enterTenant($intent->tenantId);
        $existing = $this->bookingForIdempotency($intent->idempotencyKey);

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
            ->withoutGlobalScope(StaffLocationScope::class)
            ->where('idempotency_key', $intent->idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof Booking && ! app(StaffLocationAccess::class)->allows($existing->location_id)) {
            throw new IdempotencyConflictException;
        }

        if ($existing === null) {
            return null;
        }

        if (! hash_equals($existing->payload_hash, $intent->payloadHash())) {
            throw new IdempotencyConflictException;
        }

        return $existing->load(['holds', 'allocations', 'lineItems']);
    }

    private function bookingForIdempotency(string $key): ?Booking
    {
        $existing = Booking::query()
            ->withoutGlobalScope(StaffLocationScope::class)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing instanceof Booking && ! app(StaffLocationAccess::class)->allows($existing->location_id)) {
            throw new IdempotencyConflictException;
        }

        return $existing;
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
        $this->refuseClosed($open, false);

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

            if ($requirement->resourceType() === ResourceType::Fleet) {
                if (! $resource instanceof FleetItem || $resource->status !== ResourceStatus::Active || ! $service->location->delivered_from_fleet) {
                    throw new AllocationUnavailableException('A required resource is not available.');
                }

                $key = ResourceType::Fleet->value.':'.$resource->id;

                if (isset($merged[$key])) {
                    $merged[$key]['quantity'] += $units;

                    continue;
                }

                $merged[$key] = [
                    'type' => ResourceType::Fleet,
                    'id' => $resource->id,
                    'quantity' => $units,
                    'buffer_start' => $intent->startsAt,
                    'buffer_end' => $intent->endsAt,
                    'name' => $resource->name,
                    'buckets' => [],
                ];

                continue;
            }

            if (! $resource instanceof Asset && ! $resource instanceof Pool) {
                throw new AllocationUnavailableException('A required resource is not available.');
            }

            $movingOffRetired = $resource instanceof Asset
                && $reassignFromAssetId !== null
                && $resource->id === $reassignFromAssetId
                && $resource->status === ResourceStatus::Retired;

            if ($resource->location_id !== $service->location_id || ($resource->status !== ResourceStatus::Active && ! $movingOffRetired)) {
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

            $this->refuseClosed($resourceOpen, true);

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
        usort($demands, $this->compareDemands(...));

        return [
            'demands' => $demands,
            'bucket_minutes' => $bucketMinutes,
            'location' => $service->location,
            'starts_at' => $intent->startsAt,
            'ends_at' => $intent->endsAt,
            'guide_user_id' => $guideUserId,
            'departure_id' => $departureId,
            'counter_sale' => $intent->counterSale,
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
            'corporate_account_id' => $this->corporateAccountId($intent),
            'status' => BookingStatus::Held,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $intent->startsAt->utc(),
            'ends_at' => $intent->endsAt->utc(),
            'participants' => $intent->participants,
            'counter_sale' => $intent->counterSale,
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
        $this->webhooks->bookingCreated($booking);

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

    private function resource(ConsumesInventory $requirement): Asset|Pool|FleetItem|null
    {
        return match ($requirement->resourceType()) {
            ResourceType::Asset => Asset::query()->find($requirement->resourceId()),
            ResourceType::Pool => Pool::query()->find($requirement->resourceId()),
            ResourceType::Fleet => FleetItem::query()->find($requirement->resourceId()),
        };
    }

    /**
     * Fleet rows lock before pools, and pools lock before assets.
     *
     * @param  Demand  $left
     * @param  Demand  $right
     */
    private function compareDemands(array $left, array $right): int
    {
        $rank = $this->demandRank($left['type']) <=> $this->demandRank($right['type']);

        if ($rank !== 0) {
            return $rank;
        }

        return $left['id'] <=> $right['id'];
    }

    private function demandRank(ResourceType $type): int
    {
        return match ($type) {
            ResourceType::Fleet => 0,
            ResourceType::Pool => 1,
            ResourceType::Asset => 2,
        };
    }

    /**
     * @param  Plan  $plan
     */
    private function lock(array $plan): void
    {
        foreach ($plan['demands'] as $demand) {
            if ($demand['type'] !== ResourceType::Fleet) {
                continue;
            }

            $locked = DB::select('select id from fleet_items where id = ? for update', [$demand['id']]);

            if ($locked === []) {
                throw new AllocationUnavailableException;
            }
        }

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
     * @param  array{open: bool, blackout: bool, closed: bool}  $open
     */
    private function refuseClosed(array $open, bool $resource): void
    {
        if ($open['open']) {
            return;
        }

        if ($resource) {
            throw new AllocationUnavailableException($open['closed']
                ? 'That time is closed.'
                : 'That resource is outside its hours or a blackout.');
        }

        throw new AllocationUnavailableException(match (true) {
            $open['blackout'] => 'That date is closed.',
            $open['closed'] => 'That time is closed.',
            default => 'That time is outside opening hours.',
        });
    }

    /**
     * Confirming a hold that already exists passes false for $honorMaintenance.
     * A maintenance window or block scheduled after that hold flags the booking
     * for staff instead of failing the payment (Spec Section 9).
     *
     * @param  Plan  $plan
     */
    private function recheck(array $plan, ?string $ignoreBookingId, bool $honorMaintenance = true): void
    {
        $open = $this->hours->assess($plan['location'], $plan['starts_at'], $plan['ends_at']);
        $this->refuseClosed($open, false);

        foreach ($plan['demands'] as $demand) {
            if ($demand['type'] === ResourceType::Fleet) {
                $item = FleetItem::query()->find($demand['id']);

                if (! $item instanceof FleetItem || $item->status !== ResourceStatus::Active || ! $plan['location']->delivered_from_fleet) {
                    throw new AllocationUnavailableException('A required resource is not available.');
                }

                $this->fleetCapacity->assertFits(
                    $item,
                    $plan['location'],
                    $plan['starts_at'],
                    $plan['ends_at'],
                    $demand['quantity'],
                    $plan['counter_sale'],
                    $ignoreBookingId,
                );

                continue;
            }

            if ($honorMaintenance && $this->underMaintenance($demand['type'], $demand['id'], $demand['buffer_start'], $demand['buffer_end'])) {
                throw new AllocationUnavailableException('That resource is out of service for maintenance at that time.');
            }

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

    private function underMaintenance(ResourceType $type, string $resourceId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if ($type === ResourceType::Fleet) {
            return false;
        }

        return MaintenanceWindow::query()
            ->where($type === ResourceType::Asset ? 'asset_id' : 'pool_id', $resourceId)
            ->blocking($start, $end)
            ->exists();
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
            'corporate_account_id' => $this->corporateAccountId($intent),
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $intent->startsAt->utc(),
            'ends_at' => $intent->endsAt->utc(),
            'participants' => $intent->participants,
            'counter_sale' => $intent->counterSale,
            'idempotency_key' => $intent->idempotencyKey !== '' ? $intent->idempotencyKey : null,
            'payload_hash' => $intent->payloadHash(),
        ]);
        $this->writeAddOns($booking, $intent);
        $this->operatorFees->attach($booking);
        $this->webhooks->bookingCreated($booking);

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
                'fleet_item_id' => $demand['type'] === ResourceType::Fleet ? $demand['id'] : null,
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

    private function ledgerMinutes(string $tenantId): int
    {
        $settings = TenantSetting::query()->where('tenant_id', $tenantId)->first();

        return $settings instanceof TenantSetting ? $settings->ledger_bucket_minutes : 15;
    }

    private function corporateAccountId(AllocationIntent $intent): ?string
    {
        $id = $intent->corporateAccountId;

        if ($id === null || $id === '') {
            return null;
        }

        $account = CorporateAccount::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $intent->tenantId)
            ->where('active', true)
            ->find($id);

        if (! $account instanceof CorporateAccount) {
            throw new AllocationUnavailableException('That corporate account is not available.');
        }

        return $account->id;
    }

    private function customerId(AllocationIntent $intent): ?string
    {
        $chosen = $this->presentId($intent->customerId);

        if ($chosen !== null) {
            $customer = Customer::query()->whereNull('anonymized_at')->find($chosen);

            if (! $customer instanceof Customer) {
                throw new AllocationUnavailableException('That customer is not in this workspace.');
            }

            return $customer->id;
        }

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

            $this->customerAccounts->attachGuestCustomer($customer);

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
            counterSale: (bool) $booking->counter_sale,
        );
    }

    private function intentAt(Booking $booking, CarbonImmutable $start, CarbonImmutable $end): AllocationIntent
    {
        return new AllocationIntent(
            $booking->tenant_id,
            $booking->service_id,
            $start->utc(),
            $end->utc(),
            $booking->participants,
            null,
            null,
            null,
            $booking->departure_id,
            $booking->guide_user_id,
            variantId: $booking->service_variant_id,
            counterSale: (bool) $booking->counter_sale,
        );
    }

    /**
     * The asset this move starts from. A preferred id must already be allocated
     * to the booking. With no preference, the first allocated asset is used.
     */
    private function allocatedAssetId(Booking $booking, ?string $preferred): ?string
    {
        $ids = [];

        foreach (Allocation::query()->where('booking_id', $booking->id)->whereNotNull('asset_id')->orderBy('asset_id')->pluck('asset_id') as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        $preferred = $this->presentId($preferred);

        if ($preferred !== null) {
            if (! in_array($preferred, $ids, true)) {
                throw new AllocationUnavailableException('That asset is not on this booking.');
            }

            return $preferred;
        }

        return $ids[0] ?? null;
    }

    /**
     * Plan the new interval, keep assets the booking was already moved onto,
     * then apply a requested swap. Lock and recheck happen after this returns.
     *
     * @return Plan
     */
    private function plannedMove(Booking $booking, AllocationIntent $intent, ?string $fromAssetId, ?string $toAssetId): array
    {
        $this->assertDuration($intent);
        $plan = $this->plan($intent, $this->retiredRequirementId($booking) ?? $fromAssetId);
        $plan = $this->keepAssignedAssets($plan, $booking);

        if ($fromAssetId !== null && $toAssetId !== null && $toAssetId !== $fromAssetId) {
            $plan = $this->swapAsset($plan, $booking, $fromAssetId, $toAssetId);
        }

        return $plan;
    }

    /**
     * A service can still name a retired asset after the booking has moved off it.
     * Planning that id lets the existing retired-source exception run.
     */
    private function retiredRequirementId(Booking $booking): ?string
    {
        $requirements = ResourceRequirement::query()->where('service_id', $booking->service_id)->get();

        foreach ($requirements as $requirement) {
            if ($requirement->resourceType() !== ResourceType::Asset) {
                continue;
            }

            $asset = Asset::query()->find($requirement->resourceId());

            if ($asset instanceof Asset && $asset->status === ResourceStatus::Retired) {
                return $asset->id;
            }
        }

        return null;
    }

    /**
     * Reschedule plans the service's required asset. A booking that was already
     * reassigned stays on that asset unless this move picks a different one.
     *
     * @param  Plan  $plan
     * @return Plan
     */
    private function keepAssignedAssets(array $plan, Booking $booking): array
    {
        $allocatedIds = [];

        foreach (Allocation::query()->where('booking_id', $booking->id)->whereNotNull('asset_id')->orderBy('asset_id')->pluck('asset_id') as $id) {
            if (is_string($id) && $id !== '') {
                $allocatedIds[] = $id;
            }
        }

        foreach ($allocatedIds as $allocatedId) {
            $plannedIds = [];

            foreach ($plan['demands'] as $demand) {
                if ($demand['type'] === ResourceType::Asset) {
                    $plannedIds[] = $demand['id'];
                }
            }

            if (in_array($allocatedId, $plannedIds, true)) {
                continue;
            }

            $allocated = Asset::query()->find($allocatedId);

            if (! $allocated instanceof Asset || $allocated->resource_category_id === null) {
                continue;
            }

            foreach ($plan['demands'] as $demand) {
                if ($demand['type'] !== ResourceType::Asset || in_array($demand['id'], $allocatedIds, true)) {
                    continue;
                }

                $planned = Asset::query()->find($demand['id']);

                if (! $planned instanceof Asset || $planned->resource_category_id !== $allocated->resource_category_id) {
                    continue;
                }

                $plan = $this->swapAsset($plan, $booking, $demand['id'], $allocatedId, true);

                break;
            }
        }

        return $plan;
    }

    /**
     * Hints for a refused move. Not a lock.
     *
     * @return array{authoritative: false, times: list<array{starts_at: string, ends_at: string}>, other_asset_ids: list<string>}
     */
    private function moveOffers(Booking $booking, CarbonImmutable $newStart, CarbonImmutable $newEnd, ?string $fromAssetId, ?string $toAssetId): array
    {
        $this->enterTenant($booking->tenant_id);
        $booking = Booking::query()->find($booking->id);
        $empty = ['authoritative' => false, 'times' => [], 'other_asset_ids' => []];

        if (! $booking instanceof Booking) {
            return $empty;
        }

        $intent = $this->intentAt($booking, $newStart->utc(), $newEnd->utc());
        $assets = [];

        try {
            $from = $this->allocatedAssetId($booking, $fromAssetId);
            $target = $this->presentId($toAssetId) ?? $from;
            $plan = $this->plannedMove($booking, $intent, $from, $target);
            $assets = $this->otherAssets($booking->id, $plan);
        } catch (AllocationUnavailableException) {
            $assets = [];
        }

        return [
            'authoritative' => false,
            'times' => $this->nearbyTimes($intent),
            'other_asset_ids' => $assets,
        ];
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
            ->occupyingSeats()
            ->sum('participants');

        if ($departure->private && $others > 0) {
            throw new AllocationUnavailableException('That private departure already has a booking.');
        }

        $cap = $departure->seats ?? $departure->service->max_participants;

        if ($cap !== null && $others + $participants > $cap && $participants >= $booking->participants) {
            throw new AllocationUnavailableException('That departure is full.');
        }
    }

    private function assertSameTour(Departure $departure, Booking $booking): void
    {
        if ($departure->service === null || $departure->service_id !== $booking->service_id) {
            throw new AllocationUnavailableException('That departure is for a different service.');
        }

        if ($departure->status !== DepartureStatus::Scheduled) {
            throw new AllocationUnavailableException('That departure is cancelled.');
        }
    }

    private function assertTargetSeats(Departure $departure, Booking $booking): void
    {
        $others = (int) $departure->bookings()
            ->where('id', '!=', $booking->id)
            ->occupyingSeats()
            ->sum('participants');

        if ($departure->private && $others > 0) {
            throw new AllocationUnavailableException('That private departure already has a booking.');
        }

        $cap = $departure->seats ?? $departure->service?->max_participants;

        if ($cap !== null && $others + $booking->participants > $cap) {
            throw new AllocationUnavailableException('That departure is full.');
        }
    }

    /**
     * @param  list<string>  $ids
     */
    private function lockDepartures(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(
            $ids,
            fn (string $id): bool => $id !== '',
        )));
        sort($ids);

        foreach ($ids as $id) {
            $locked = DB::select('select id from departures where id = ? for update', [$id]);

            if ($locked === []) {
                throw new AllocationUnavailableException('That departure is not available.');
            }
        }
    }

    private function departureHasResourceHolder(Departure $departure, string $ignoreBookingId): bool
    {
        $ids = $departure->bookings()
            ->where('id', '!=', $ignoreBookingId)
            ->occupyingSeats()
            ->pluck('id');

        if ($ids->isEmpty()) {
            return false;
        }

        $allocated = Allocation::query()
            ->whereIn('booking_id', $ids)
            ->where(function ($query): void {
                $query->whereNotNull('asset_id')->orWhereNotNull('pool_id')->orWhereNotNull('fleet_item_id');
            })
            ->exists();

        if ($allocated) {
            return true;
        }

        return Hold::query()
            ->whereIn('booking_id', $ids)
            ->where('expires_at', '>', now())
            ->where(function ($query): void {
                $query->whereNotNull('asset_id')->orWhereNotNull('pool_id')->orWhereNotNull('fleet_item_id');
            })
            ->exists();
    }

    /**
     * The oldest confirmed party still on this departure, or the oldest held
     * party when nobody confirmed is left. A pending payment does not inherit
     * the boat.
     */
    private function resourceInheritor(Booking $booking): ?Booking
    {
        $confirmed = Booking::query()
            ->where('departure_id', $booking->departure_id)
            ->where('id', '!=', $booking->id)
            ->where('status', BookingStatus::Confirmed)
            ->occupyingSeats()
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($confirmed instanceof Booking) {
            return $confirmed;
        }

        $held = Booking::query()
            ->where('departure_id', $booking->departure_id)
            ->where('id', '!=', $booking->id)
            ->where('status', BookingStatus::Held)
            ->occupyingSeats()
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return $held instanceof Booking ? $held : null;
    }

    /**
     * Leave the source interval's boat with the party that stays. Times on the
     * rows do not change. A held party receives holds instead of allocations.
     */
    private function handResources(Booking $from, Booking $to): void
    {
        if ($this->presentId($from->guide_user_id) !== null && $this->presentId($to->guide_user_id) === null) {
            $to->guide_user_id = $from->guide_user_id;
            $to->save();
        }

        $spans = $this->poolSpans($from->id);

        if ($to->status !== BookingStatus::Confirmed) {
            $existing = Hold::query()->where('booking_id', $to->id)->max('expires_at');
            $expiresAt = is_string($existing) || $existing instanceof \DateTimeInterface
                ? CarbonImmutable::parse($existing)
                : now()->addMinutes($this->holdMinutes($to->tenant_id));

            foreach ($this->resourceAllocations($from->id)->get() as $allocation) {
                Hold::query()->create([
                    'tenant_id' => $allocation->tenant_id,
                    'booking_id' => $to->id,
                    'asset_id' => $allocation->asset_id,
                    'pool_id' => $allocation->pool_id,
                    'fleet_item_id' => $allocation->fleet_item_id,
                    'quantity' => $allocation->quantity,
                    'starts_at' => $allocation->starts_at,
                    'ends_at' => $allocation->ends_at,
                    'expires_at' => $expiresAt,
                ]);
                $allocation->delete();
            }
        } else {
            $this->resourceAllocations($from->id)->update(['booking_id' => $to->id]);
        }

        Hold::query()
            ->where('booking_id', $from->id)
            ->where(function ($query): void {
                $query->whereNotNull('asset_id')->orWhereNotNull('pool_id')->orWhereNotNull('fleet_item_id');
            })
            ->update(['booking_id' => $to->id]);
        BookingLineItem::query()
            ->where('booking_id', $from->id)
            ->whereNotNull('resource_id')
            ->update(['booking_id' => $to->id]);
        $this->refreshPoolSpans($from->tenant_id, $spans);
    }

    private function releaseResources(Booking $booking): void
    {
        $spans = $this->poolSpans($booking->id);
        $this->resourceAllocations($booking->id)->delete();
        Hold::query()
            ->where('booking_id', $booking->id)
            ->where(function ($query): void {
                $query->whereNotNull('asset_id')->orWhereNotNull('pool_id')->orWhereNotNull('fleet_item_id');
            })
            ->delete();
        BookingLineItem::query()
            ->where('booking_id', $booking->id)
            ->whereNotNull('resource_id')
            ->delete();
        $this->refreshPoolSpans($booking->tenant_id, $spans);
    }

    /**
     * @return Builder<Allocation>
     */
    private function resourceAllocations(string $bookingId): Builder
    {
        return Allocation::query()
            ->where('booking_id', $bookingId)
            ->where(function ($query): void {
                $query->whereNotNull('asset_id')->orWhereNotNull('pool_id')->orWhereNotNull('fleet_item_id');
            });
    }

    /**
     * @return list<array{pool_id: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    private function poolSpans(string $bookingId): array
    {
        $spans = [];

        foreach (Allocation::query()->where('booking_id', $bookingId)->whereNotNull('pool_id')->get() as $row) {
            if (! is_string($row->pool_id) || $row->pool_id === '') {
                continue;
            }

            $spans[] = [
                'pool_id' => $row->pool_id,
                'starts_at' => CarbonImmutable::instance($row->starts_at)->utc(),
                'ends_at' => CarbonImmutable::instance($row->ends_at)->utc(),
            ];
        }

        foreach (Hold::query()->where('booking_id', $bookingId)->whereNotNull('pool_id')->where('expires_at', '>', now())->get() as $row) {
            if (! is_string($row->pool_id) || $row->pool_id === '') {
                continue;
            }

            $spans[] = [
                'pool_id' => $row->pool_id,
                'starts_at' => CarbonImmutable::instance($row->starts_at)->utc(),
                'ends_at' => CarbonImmutable::instance($row->ends_at)->utc(),
            ];
        }

        return $spans;
    }

    /**
     * @param  list<array{pool_id: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $spans
     */
    private function refreshPoolSpans(string $tenantId, array $spans): void
    {
        $minutes = $this->ledgerMinutes($tenantId);

        foreach ($spans as $span) {
            foreach ($this->buckets->covering($span['starts_at'], $span['ends_at'], $minutes) as $bucket) {
                $start = CarbonImmutable::parse('@'.$bucket)->utc();
                $end = $start->addSeconds($minutes * 60);
                CapacityLedger::query()
                    ->where('pool_id', $span['pool_id'])
                    ->where('bucket_start_utc', $start->format('Y-m-d H:i:s'))
                    ->update([
                        'allocated_units' => $this->sumOnly(Allocation::class, $span['pool_id'], $start, $end),
                        'held_units' => $this->sumOnly(Hold::class, $span['pool_id'], $start, $end, unexpired: true),
                    ]);
            }
        }
    }

    private function guideForMovedDeparture(Booking $booking, Departure $target): ?string
    {
        $shared = Booking::query()
            ->where('departure_id', $target->id)
            ->where('id', '!=', $booking->id)
            ->occupyingSeats()
            ->whereNotNull('guide_user_id')
            ->where('guide_user_id', '!=', '')
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('guide_user_id');

        if (is_string($shared) && $shared !== '') {
            return $shared;
        }

        return $this->presentId($booking->guide_user_id);
    }

    private function seatOnly(Booking $booking): bool
    {
        if ($booking->departure_id === null || $booking->departure_id === '') {
            return false;
        }

        $allocated = Allocation::query()
            ->where('booking_id', $booking->id)
            ->where(function ($query): void {
                $query->whereNotNull('asset_id')->orWhereNotNull('pool_id')->orWhereNotNull('fleet_item_id');
            })
            ->exists();

        if ($allocated) {
            return false;
        }

        return ! Hold::query()
            ->where('booking_id', $booking->id)
            ->where(function ($query): void {
                $query->whereNotNull('asset_id')->orWhereNotNull('pool_id')->orWhereNotNull('fleet_item_id');
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
            counterSale: $intent->counterSale,
        ))['available'];
    }

    private function availabilityRefusal(AllocationUnavailableException $exception): bool
    {
        return in_array($exception->getMessage(), [
            'That time is no longer available.',
            'That day is fully booked.',
            'That guide is already booked.',
            'That departure is full.',
            'That private departure already has a booking.',
        ], true);
    }

    /**
     * The longest whole minute strictly before the requested end that still
     * passes the same recheck, and any other asset of the same type that is
     * free for the full requested interval. Both are hints.
     *
     * @param  Plan  $plan
     * @return array<string, mixed>
     */
    private function offers(Booking $booking, array $plan, CarbonImmutable $requestedEnd): array
    {
        $currentEnd = CarbonImmutable::instance($booking->ends_at)->utc();
        $shorter = $this->longestShorterEnd($booking, $plan, $currentEnd, $requestedEnd);

        return [
            'authoritative' => false,
            'shorter_ends_at' => $shorter?->format('Y-m-d\TH:i:s\Z'),
            'other_asset_ids' => $this->otherAssets($booking->id, $plan),
        ];
    }

    /**
     * @param  Plan  $plan
     */
    private function longestShorterEnd(Booking $booking, array $plan, CarbonImmutable $currentEnd, CarbonImmutable $requestedEnd): ?CarbonImmutable
    {
        $span = $requestedEnd->getTimestamp() - $currentEnd->getTimestamp();
        $wholeMinutes = intdiv($span, 60);
        $high = ($span % 60 === 0) ? $wholeMinutes - 1 : $wholeMinutes;
        $low = 1;
        $best = null;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $candidate = $currentEnd->addMinutes($mid);

            if ($this->endFits($booking, $plan, $candidate)) {
                $best = $candidate;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $best;
    }

    /**
     * @param  Plan  $plan
     */
    private function endFits(Booking $booking, array $plan, CarbonImmutable $end): bool
    {
        $candidate = $plan;
        $candidate['ends_at'] = $end;

        foreach ($candidate['demands'] as $index => $demand) {
            $resource = $demand['type'] === ResourceType::Asset
                ? Asset::query()->find($demand['id'])
                : Pool::query()->find($demand['id']);
            $buffer = $resource === null ? 0 : $this->tailMinutes($resource);
            $candidate['demands'][$index]['buffer_end'] = $end->addMinutes($buffer);

            if ($demand['type'] === ResourceType::Pool) {
                $candidate['demands'][$index]['buckets'] = $this->buckets->covering(
                    $demand['buffer_start'],
                    $candidate['demands'][$index]['buffer_end'],
                    $plan['bucket_minutes'],
                );
            }
        }

        try {
            $this->recheck($candidate, $booking->id);
        } catch (AllocationUnavailableException) {
            return false;
        }

        return true;
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

    private function storeGuide(Booking $party, ?string $guideUserId): ?Booking
    {
        if ($this->presentId($party->guide_user_id) === $guideUserId) {
            return null;
        }

        $party->guide_user_id = $guideUserId;
        $party->save();
        $this->webhooks->bookingChanged($party);

        return $party;
    }

    private function canAssignGuide(Booking $booking): bool
    {
        if ($booking->status === BookingStatus::Confirmed) {
            return true;
        }

        if ($booking->status !== BookingStatus::Held) {
            return false;
        }

        return Hold::query()
            ->where('booking_id', $booking->id)
            ->where('expires_at', '>', now())
            ->exists();
    }

    private function assertActiveGuide(string $guideUserId): void
    {
        if (! $this->guideRoster->accepts($guideUserId)) {
            throw new AllocationUnavailableException('That guide is not an active member of this workspace.');
        }
    }

    /**
     * @param  iterable<int, Booking>  $parties
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function sharedGuideWindow(iterable $parties): array
    {
        $start = null;
        $end = null;

        foreach ($parties as $party) {
            $rows = Allocation::query()->where('booking_id', $party->id)->get(['starts_at', 'ends_at']);

            if ($rows->isEmpty()) {
                $rows = Hold::query()
                    ->where('booking_id', $party->id)
                    ->where('expires_at', '>', now())
                    ->get(['starts_at', 'ends_at']);
            }

            if ($rows->isEmpty()) {
                $rows = collect([(object) ['starts_at' => $party->starts_at, 'ends_at' => $party->ends_at]]);
            }

            foreach ($rows as $row) {
                $spanStart = CarbonImmutable::parse($row->starts_at)->utc();
                $spanEnd = CarbonImmutable::parse($row->ends_at)->utc();
                $start = $start === null || $spanStart->lessThan($start) ? $spanStart : $start;
                $end = $end === null || $spanEnd->greaterThan($end) ? $spanEnd : $end;
            }
        }

        if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable) {
            throw new AllocationUnavailableException('Choose a valid interval and party size.');
        }

        return [$start, $end];
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
            ->withoutGlobalScope(StaffLocationScope::class)
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
