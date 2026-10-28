<?php

namespace App\Livewire\Staff;

use App\Enums\BookingStatus;
use App\Enums\ResourceStatus;
use App\Livewire\Concerns\PollsCalendar;
use App\Livewire\Concerns\WithinTenant;
use App\Livewire\Staff\Concerns\BooksFromSchedule;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Pool;
use App\Models\ResourceCategory;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Booking\TourDepartureChoices;
use App\Services\Notifications\NotificationHooks;
use App\Services\Payments\CheckoutService;
use App\Services\Schedule\ResourceSchedule;
use App\Support\Display;
use App\Support\DisplayZone;
use App\Tenancy\StaffLocationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Schedule')]
class Schedule extends Component
{
    use BooksFromSchedule;
    use PollsCalendar;
    use WithinTenant;

    #[Url]
    public string $date = '';

    #[Url]
    public string $view = 'day';

    #[Url]
    public string $location = '';

    #[Url]
    public string $category = '';

    public string $selected = '';

    public string $moveStart = '';

    public string $moveDeparture = '';

    public string $moveAsset = '';

    public string $fromAsset = '';

    public ?string $flash = null;

    public ?string $problem = null;

    /**
     * @var list<array{starts_at: string, label: string}>
     */
    public array $suggestedTimes = [];

    /**
     * @var list<array{id: string, name: string}>
     */
    public array $suggestedAssets = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);

        if (! in_array($this->view, ['day', 'week'], true)) {
            $this->view = 'day';
        }

        if (! $this->validDate($this->date)) {
            $this->date = CarbonImmutable::now($this->activeTimezone())->toDateString();
        }
    }

    public function shift(int $direction): void
    {
        $step = $this->view === 'day' ? 1 : 7;
        $this->date = $this->day()->addDays($direction > 0 ? $step : -$step)->toDateString();
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now($this->activeTimezone())->toDateString();
    }

    public function openMove(string $bookingId, string $row): void
    {
        $this->closeBooking();
        $this->reset('flash', 'problem', 'suggestedTimes', 'suggestedAssets', 'moveDeparture');
        $booking = $this->booking($bookingId);
        $this->authorize('view', $booking);
        $booking->loadMissing('location');
        $zone = $this->zoneFor($booking);
        $from = str_starts_with($row, 'asset:') ? substr($row, 6) : '';
        $allocated = $this->allocatedAssetId($booking);

        if ($from === '' || ! $this->bookingHasAsset($booking, $from)) {
            $from = $allocated ?? '';
        }

        $this->selected = $booking->id;
        $this->fromAsset = $from;
        $this->moveAsset = $from;
        $this->moveStart = CarbonImmutable::instance($booking->starts_at)->timezone($zone)->format('Y-m-d\TH:i');
    }

    public function closeMove(): void
    {
        $this->reset('selected', 'moveStart', 'moveAsset', 'moveDeparture', 'fromAsset', 'flash', 'problem', 'suggestedTimes', 'suggestedAssets');
    }

    public function useTime(string $startsAt): void
    {
        $booking = $this->booking($this->selected);
        $this->authorize('view', $booking);
        try {
            $start = CarbonImmutable::parse($startsAt);
        } catch (\Throwable) {
            return;
        }

        $this->moveStart = $start->timezone($this->zoneFor($booking))->format('Y-m-d\TH:i');
    }

    public function useAsset(string $assetId): void
    {
        $booking = $this->booking($this->selected);
        $this->authorize('view', $booking);

        if (! $this->assetChoices($booking)->contains(fn (Asset $asset): bool => $asset->id === $assetId)) {
            return;
        }

        $this->moveAsset = $assetId;
    }

    public function moveBooking(Allocator $allocator, NotificationHooks $hooks, CheckoutService $checkout): void
    {
        $this->reset('flash', 'problem', 'suggestedTimes', 'suggestedAssets');
        $booking = $this->booking($this->selected);
        $this->authorize('update', $booking);

        if ($booking->departure_id !== null && $booking->departure_id !== '') {
            $this->moveTour($allocator, $hooks, $booking);

            return;
        }

        $this->validate([
            'moveStart' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);

        $zone = $this->zoneFor($booking);
        $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->moveStart, $zone);

        if (! $start instanceof CarbonImmutable) {
            $this->addError('moveStart', 'Choose a valid start.');

            return;
        }

        $currentStart = CarbonImmutable::instance($booking->starts_at)->utc();
        $currentEnd = CarbonImmutable::instance($booking->ends_at)->utc();
        $seconds = $currentEnd->getTimestamp() - $currentStart->getTimestamp();

        if ($seconds < 60) {
            $this->warn('Choose a valid interval.');

            return;
        }

        $startUtc = $start->utc();
        $endUtc = $startUtc->addSeconds($seconds);
        $fromAsset = $this->fromAsset !== '' ? $this->fromAsset : null;
        $toAsset = $this->moveAsset !== '' ? $this->moveAsset : null;
        $previousAsset = $fromAsset ?? $this->allocatedAssetId($booking);

        try {
            $saved = $allocator->move($booking, $startUtc, $endUtc, $toAsset, $fromAsset);
        } catch (AllocationUnavailableException $exception) {
            $this->warn($exception->getMessage());
            $this->rememberOffers($booking, $exception->offers);

            return;
        }

        $saved->loadMissing('location');
        $savedStart = CarbonImmutable::instance($saved->starts_at)->utc();
        $savedEnd = CarbonImmutable::instance($saved->ends_at)->utc();
        $timeChanged = ! $savedStart->equalTo($currentStart) || ! $savedEnd->equalTo($currentEnd);
        $assetNow = $this->allocatedAssetId($saved);
        $assetChanged = $previousAsset !== null && $assetNow !== null && $assetNow !== $previousAsset;

        if (! $timeChanged && ! $assetChanged) {
            $this->notice('That booking is already there.');

            return;
        }

        if ($timeChanged) {
            $this->audit('booking.rescheduled', $saved, [
                'from_starts_at' => $currentStart->format('Y-m-d\TH:i:s\Z'),
                'to_starts_at' => $savedStart->format('Y-m-d\TH:i:s\Z'),
                'from_ends_at' => $currentEnd->format('Y-m-d\TH:i:s\Z'),
                'to_ends_at' => $savedEnd->format('Y-m-d\TH:i:s\Z'),
            ]);
        }

        if ($assetChanged) {
            $this->audit('booking.reassigned', $saved, [
                'from_asset_id' => $previousAsset,
                'to_asset_id' => $assetNow,
            ]);
        }

        $dedupe = 'booking.guest_rescheduled:'.$saved->id.':'.$savedStart->format('YmdHi');

        if ($assetNow !== null) {
            $dedupe .= ':'.substr($assetNow, -13);
        }

        $hooks->record(
            $saved->tenant_id,
            $saved->id,
            'booking.guest_rescheduled',
            $dedupe,
            ['booking_id' => $saved->id],
        );

        $this->date = $savedStart->timezone($zone)->toDateString();
        $this->fromAsset = $assetNow ?? '';
        $this->moveAsset = $assetNow ?? '';
        $this->moveStart = $savedStart->timezone($zone)->format('Y-m-d\TH:i');
        $this->notice($this->movedMessage($checkout, $saved));
    }

    public function render(): View
    {
        if ($this->location !== '') {
            $inWorkspace = Location::query()
                ->withoutGlobalScope(StaffLocationScope::class)
                ->whereKey($this->location)
                ->exists();

            if ($inWorkspace && ! Location::query()->whereKey($this->location)->exists()) {
                abort(404);
            }
        }

        $zone = $this->activeTimezone();
        $first = $this->view === 'day' ? $this->day() : $this->day()->startOfWeek(CarbonImmutable::MONDAY);
        $days = $this->view === 'day' ? 1 : 7;

        return view('livewire.staff.schedule', [
            'board' => app(ResourceSchedule::class)->present(
                $this->day()->toDateString(),
                $this->view,
                $this->location !== '' ? $this->location : null,
                $this->category !== '' ? $this->category : null,
            ),
            'zone' => $zone,
            'first' => $first,
            'last' => $first->addDays($days - 1),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'categories' => ResourceCategory::query()->orderBy('name')->get(['id', 'name']),
            'todayDate' => CarbonImmutable::now($zone)->toDateString(),
            'move' => $this->movePanel(),
            'canBook' => $this->allows('bookings.manage'),
            'booking' => $this->bookingPanel(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function movePanel(): ?array
    {
        if ($this->selected === '') {
            return null;
        }

        $booking = Booking::query()->with(['service', 'location', 'customer', 'allocations'])->find($this->selected);

        if (! $booking instanceof Booking) {
            return null;
        }

        $zone = $this->zoneFor($booking);
        $starts = CarbonImmutable::instance($booking->starts_at)->timezone($zone);
        $ends = CarbonImmutable::instance($booking->ends_at)->timezone($zone);
        $seconds = $ends->utc()->getTimestamp() - $starts->utc()->getTimestamp();
        $choices = $this->assetChoices($booking);
        $confirmed = $booking->status === BookingStatus::Confirmed;
        $canMove = $confirmed && $this->allows('bookings.manage');
        $tour = $booking->departure_id !== null && $booking->departure_id !== '';

        return [
            'id' => $booking->id,
            'who' => $this->who($booking),
            'service' => $booking->service->name ?? 'Booking',
            'when' => Display::rangeWithZone($booking->starts_at, $booking->ends_at, $zone),
            'length' => $this->lengthLabel($seconds),
            'href' => '/staff/bookings/'.$booking->id,
            'confirmed' => $confirmed,
            'canMove' => $canMove,
            'tour' => $tour,
            'departures' => $tour ? app(TourDepartureChoices::class)->forBooking($booking) : [],
            'assets' => $choices,
            'resource' => $this->resourceLabel($booking, $choices),
            'zone' => $zone,
        ];
    }

    private function moveTour(Allocator $allocator, NotificationHooks $hooks, Booking $booking): void
    {
        $this->validate([
            'moveDeparture' => ['required', 'string'],
        ]);
        $currentStart = CarbonImmutable::instance($booking->starts_at)->utc();
        $currentEnd = CarbonImmutable::instance($booking->ends_at)->utc();
        $fromDeparture = $booking->departure_id;

        try {
            $saved = $allocator->moveToDeparture($booking, $this->moveDeparture);
        } catch (AllocationUnavailableException $exception) {
            $this->warn($exception->getMessage());

            return;
        }

        $saved->loadMissing('location');
        $savedStart = CarbonImmutable::instance($saved->starts_at)->utc();
        $savedEnd = CarbonImmutable::instance($saved->ends_at)->utc();

        if ($fromDeparture === $saved->departure_id
            && $savedStart->equalTo($currentStart)
            && $savedEnd->equalTo($currentEnd)) {
            $this->notice('That booking is already there.');

            return;
        }

        $this->audit('booking.rescheduled', $saved, [
            'from_starts_at' => $currentStart->format('Y-m-d\TH:i:s\Z'),
            'to_starts_at' => $savedStart->format('Y-m-d\TH:i:s\Z'),
            'from_ends_at' => $currentEnd->format('Y-m-d\TH:i:s\Z'),
            'to_ends_at' => $savedEnd->format('Y-m-d\TH:i:s\Z'),
            'from_departure_id' => $fromDeparture,
            'to_departure_id' => $saved->departure_id,
        ]);
        $hooks->record(
            $saved->tenant_id,
            $saved->id,
            'booking.guest_rescheduled',
            'booking.guest_rescheduled:'.$saved->id.':'.$savedStart->format('YmdHi'),
            ['booking_id' => $saved->id],
        );
        $zone = $this->zoneFor($saved);
        $this->date = $savedStart->timezone($zone)->toDateString();
        $this->moveDeparture = '';
        $this->moveStart = $savedStart->timezone($zone)->format('Y-m-d\TH:i');
        $this->notice('Moved.');
    }

    private function booking(string $id): Booking
    {
        $booking = Booking::query()->find($id);
        abort_unless($booking instanceof Booking, 404);

        return $booking;
    }

    private function zoneFor(Booking $booking): string
    {
        $booking->loadMissing('location');

        return DisplayZone::forLocation($booking->location);
    }

    private function allocatedAssetId(Booking $booking): ?string
    {
        $id = Allocation::query()
            ->where('booking_id', $booking->id)
            ->whereNotNull('asset_id')
            ->orderBy('asset_id')
            ->value('asset_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function bookingHasAsset(Booking $booking, string $assetId): bool
    {
        return Allocation::query()
            ->where('booking_id', $booking->id)
            ->where('asset_id', $assetId)
            ->exists();
    }

    /**
     * @return Collection<int, Asset>
     */
    private function assetChoices(Booking $booking): Collection
    {
        $currentId = $this->fromAsset !== '' ? $this->fromAsset : $this->allocatedAssetId($booking);
        $current = $currentId !== null ? Asset::query()->find($currentId) : null;

        if (! $current instanceof Asset || $current->resource_category_id === null || $current->location_id !== $booking->location_id) {
            return collect();
        }

        return Asset::query()
            ->where('location_id', $current->location_id)
            ->where('resource_category_id', $current->resource_category_id)
            ->where(function ($query) use ($current): void {
                $query->where('status', ResourceStatus::Active->value)
                    ->orWhere('id', $current->id);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Asset>  $choices
     */
    private function resourceLabel(Booking $booking, Collection $choices): string
    {
        if ($choices->isNotEmpty()) {
            $current = $choices->firstWhere('id', $this->fromAsset !== '' ? $this->fromAsset : $this->moveAsset);

            return $current instanceof Asset ? $current->name : $choices->first()->name;
        }

        $poolId = Allocation::query()->where('booking_id', $booking->id)->whereNotNull('pool_id')->value('pool_id');
        $pool = is_string($poolId) ? Pool::query()->find($poolId) : null;

        return $pool instanceof Pool ? $pool->name : '';
    }

    private function who(Booking $booking): string
    {
        $customer = $booking->customer;

        if ($customer instanceof Customer && $customer->name !== '') {
            return $customer->name;
        }

        return 'Walk-in';
    }

    private function lengthLabel(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);

        if ($minutes > 0 && $minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 hour' : $hours.' hours';
        }

        return $minutes === 1 ? '1 minute' : $minutes.' minutes';
    }

    /**
     * @param  array<string, mixed>  $offers
     */
    private function rememberOffers(Booking $booking, array $offers): void
    {
        $zone = $this->zoneFor($booking);
        $times = [];

        foreach ($offers['times'] ?? [] as $time) {
            if (! is_array($time) || ! is_string($time['starts_at'] ?? null)) {
                continue;
            }

            $start = CarbonImmutable::parse($time['starts_at'])->timezone($zone);
            $times[] = [
                'starts_at' => $start->utc()->format('Y-m-d\TH:i:s\Z'),
                'label' => $start->format('D, g:i A'),
            ];
        }

        $ids = [];

        foreach ($offers['other_asset_ids'] ?? [] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        $assets = [];

        if ($ids !== []) {
            foreach (Asset::query()->whereIn('id', $ids)->orderBy('name')->get() as $asset) {
                $assets[] = ['id' => $asset->id, 'name' => $asset->name];
            }
        }

        $this->suggestedTimes = $times;
        $this->suggestedAssets = $assets;
    }

    private function movedMessage(CheckoutService $checkout, Booking $saved): string
    {
        $message = 'Moved.';
        $change = $checkout->pendingPriceChange($saved);

        if ($change === 'increase') {
            return $message.' The price is higher. Charge the difference with price increase checkout. The booking fee is the percentage on that increase only.';
        }

        if ($change === 'decrease') {
            return $message.' The price is lower. Refund the difference with the price decrease refund. The booking fee already charged comes back in the same proportion.';
        }

        return $message;
    }

    private function activeTimezone(): string
    {
        if ($this->location !== '') {
            $selected = Location::query()->find($this->location);

            if ($selected instanceof Location) {
                return DisplayZone::forLocation($selected);
            }
        }

        return $this->defaultTimezone();
    }

    private function day(): CarbonImmutable
    {
        $zone = $this->activeTimezone();
        $parsed = $this->validDate($this->date)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->date, $zone)
            : false;

        return $parsed instanceof CarbonImmutable
            ? $parsed->startOfDay()
            : CarbonImmutable::now($zone)->startOfDay();
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function notice(string $message): void
    {
        $this->flash = $message;
        $this->problem = null;
    }

    private function warn(string $message): void
    {
        $this->problem = $message;
        $this->flash = null;
    }
}
