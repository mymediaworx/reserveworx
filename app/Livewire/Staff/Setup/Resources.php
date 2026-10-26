<?php

namespace App\Livewire\Staff\Setup;

use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\StaffUndoKind;
use App\Livewire\Concerns\PresentsUndoToast;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\FleetItem;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Pool;
use App\Models\ResourceRequirement;
use App\Models\ServiceVariantResource;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Operations\AssetRetirement;
use App\Services\Staff\StaffUndo;
use App\Support\BookingReference;
use App\Support\Display;
use App\Tenancy\StaffLocationAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Boats and gear. A pool is a count of interchangeable items (12 single kayaks);
 * an asset is one tracked item (the pontoon boat, a named guide).
 */
#[Layout('layouts.staff')]
class Resources extends Component
{
    use PresentsUndoToast, WithinTenant;

    public string $kind = 'pool';

    public string $locationId = '';

    public string $name = '';

    public int $quantity = 1;

    public int $capacity = 1;

    public bool $isGuide = false;

    public int $turnaround = 0;

    public int $prepMinutes = 0;

    public int $returnMinutes = 0;

    public string $fleetName = '';

    public int $fleetQuantity = 1;

    public string $retireAssetId = '';

    public string $replacementId = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Asset::class);
    }

    public function create(): void
    {
        $tenantId = $this->tenant()->id;
        $owned = app(StaffLocationAccess::class)->locationExistsRule($tenantId);
        $base = [
            'locationId' => ['required', 'string', $owned],
            'name' => ['required', 'string', 'max:120'],
            'turnaround' => ['integer', 'between:0,1440'],
            'prepMinutes' => ['integer', 'between:0,1440'],
            'returnMinutes' => ['integer', 'between:0,1440'],
        ];

        if ($this->kind === 'asset') {
            $this->authorize('create', Asset::class);
            $this->validate($base + [
                'capacity' => ['integer', 'between:1,10000'],
                'isGuide' => ['boolean'],
            ]);
            $resource = Asset::query()->create([
                'tenant_id' => $tenantId,
                'location_id' => $this->locationId,
                'name' => $this->name,
                'status' => ResourceStatus::Active,
                'capacity' => $this->capacity,
                'is_guide' => $this->isGuide,
                'prep_buffer_minutes' => $this->prepMinutes,
                'return_buffer_minutes' => $this->returnMinutes,
                'turnaround_minutes' => $this->turnaround,
            ]);
            $this->audit('asset.created', $resource);
        } else {
            $this->authorize('create', Pool::class);
            $this->validate($base + ['quantity' => ['required', 'integer', 'between:0,100000']]);
            $resource = Pool::query()->create([
                'tenant_id' => $tenantId,
                'location_id' => $this->locationId,
                'name' => $this->name,
                'quantity' => $this->quantity,
                'status' => ResourceStatus::Active,
                'prep_buffer_minutes' => $this->prepMinutes,
                'return_buffer_minutes' => $this->returnMinutes,
                'turnaround_minutes' => $this->turnaround,
            ]);
            $this->audit('pool.created', $resource);
        }

        $this->reset('name', 'quantity', 'capacity', 'isGuide', 'turnaround', 'prepMinutes', 'returnMinutes');
        $this->flash = 'Added. Link it to a service under Services & prices so it can be booked.';
    }

    public function setStatus(string $type, string $id, string $status): void
    {
        $value = ResourceStatus::tryFrom($status);
        abort_if($value === null, 422);
        $resource = $this->resource($type, $id);
        $this->authorize('update', $resource);

        if ($value === ResourceStatus::Retired) {
            $this->addError($type.'-'.$id, $type === 'asset'
                ? 'Use Retire to take an item out of service.'
                : 'A pool cannot be retired.');

            return;
        }

        $resource->status = $value;
        $resource->save();
        $this->audit($type.'.updated', $resource, ['status' => $value->value]);
    }

    public function openRetire(string $id): void
    {
        $asset = $this->asset($id);
        $this->authorize('update', $asset);

        if ($asset->status === ResourceStatus::Retired) {
            $this->addError('status', 'This item is already retired.');

            return;
        }

        $this->retireAssetId = $asset->id;
        $this->replacementId = '';
        $this->resetErrorBag();
    }

    public function cancelRetire(): void
    {
        $this->reset('retireAssetId', 'replacementId');
        $this->resetErrorBag();
    }

    public function retire(AssetRetirement $retirement): void
    {
        $asset = $this->asset($this->retireAssetId);
        $this->authorize('update', $asset);
        $result = $retirement->retire($asset, $this->replacementId !== '' ? $this->replacementId : null);
        $this->audit('asset.retired', $result['asset'], [
            'replacement_asset_id' => $result['asset']->replacement_asset_id,
            'flagged_bookings' => $result['flagged_bookings'],
        ]);
        $this->reset('retireAssetId', 'replacementId');
        $this->flash = $result['asset']->name.' is retired. Future bookings stay booked.';
    }

    public function acceptOffer(string $bookingId, string $fromAssetId, string $toAssetId, Allocator $allocator): void
    {
        $booking = Booking::query()->find($bookingId);
        abort_unless($booking instanceof Booking, 404);
        $this->authorize('update', $booking);

        try {
            $saved = $allocator->reassignAsset($booking, $fromAssetId, $toAssetId);
        } catch (AllocationUnavailableException $exception) {
            $this->addError('move-'.$bookingId, $exception->getMessage());

            return;
        }

        $this->audit('booking.reassigned', $saved, [
            'from_asset_id' => $fromAssetId,
            'to_asset_id' => $toAssetId,
        ]);
        $this->flash = 'Moved onto the replacement. The price stays as it was.';
    }

    public function setQuantity(string $id, int $quantity): void
    {
        $pool = $this->resource('pool', $id);
        $this->authorize('update', $pool);

        if ($quantity < 0 || $quantity > 100000) {
            $this->addError('pool-'.$id, 'Enter a count between 0 and 100,000.');

            return;
        }

        $pool->quantity = $quantity;
        $pool->save();
        $this->audit('pool.updated', $pool, ['quantity' => $quantity]);
        $this->flash = 'Count updated.';
    }

    /**
     * Prep and return minutes are the buffers in spec Section 4.2. Turnaround
     * is the gap after the return buffer. The allocator already reads them.
     */
    public function setBuffer(string $type, string $id, string $field, mixed $minutes): void
    {
        $column = match ($field) {
            'prep' => 'prep_buffer_minutes',
            'return' => 'return_buffer_minutes',
            'turnaround' => 'turnaround_minutes',
            default => null,
        };
        abort_if($column === null, 422);

        $resource = $this->resource($type, $id);
        $this->authorize('update', $resource);
        $value = $this->wholeMinutes($minutes);

        if ($value === null) {
            $this->addError($type.'-'.$id.'-'.$field, 'Enter whole minutes from 0 to 1,440.');

            return;
        }

        if ((int) $resource->getAttribute($column) === $value) {
            return;
        }

        $resource->setAttribute($column, $value);
        $resource->save();
        $this->audit($type.'.updated', $resource, [$column => $value]);
        $this->flash = 'Time around bookings updated.';
    }

    public function deleteResource(string $type, string $id, StaffUndo $undo): void
    {
        $resource = $this->resource($type, $id);
        $this->authorize('delete', $resource);
        $name = $resource->name;
        $kind = $resource instanceof Asset ? StaffUndoKind::AssetDelete : StaffUndoKind::PoolDelete;
        $audit = $resource instanceof Asset ? 'asset.deleted' : 'pool.deleted';
        $resource->delete();
        $this->audit($audit, $resource);
        $this->rememberUndo($undo->offer(
            $this->currentUser(),
            $kind,
            $name.' removed.',
            $resource->location_id,
            $resource,
            [
                'name' => $name,
                'location_id' => $resource->location_id,
            ],
        ));
        $this->flash = $name.' removed.';
    }

    public function createFleet(): void
    {
        $this->authorize('create', FleetItem::class);
        $this->validate([
            'fleetName' => ['required', 'string', 'max:120'],
            'fleetQuantity' => ['required', 'integer', 'between:0,100000'],
        ]);

        $item = FleetItem::query()->create([
            'tenant_id' => $this->tenant()->id,
            'name' => $this->fleetName,
            'quantity' => $this->fleetQuantity,
            'status' => ResourceStatus::Active,
        ]);
        $this->audit('fleet_item.created', $item, ['quantity' => $item->quantity]);
        $this->reset('fleetName');
        $this->fleetQuantity = 1;
        $this->flash = 'Fleet item added. Mark a location as delivered from fleet, then link this item on a service.';
    }

    public function setFleetQuantity(string $id, int $quantity): void
    {
        $item = $this->fleetItem($id);
        $this->authorize('update', $item);

        if ($quantity < 0 || $quantity > 100000) {
            $this->addError('fleet-'.$id, 'Enter a count between 0 and 100,000.');

            return;
        }

        $item->quantity = $quantity;
        $item->save();
        $this->audit('fleet_item.updated', $item, ['quantity' => $quantity]);
        $this->flash = 'Fleet count updated.';
    }

    public function deleteFleet(string $id): void
    {
        $item = $this->fleetItem($id);
        $this->authorize('delete', $item);
        $linked = ResourceRequirement::query()
            ->where('resource_type', ResourceType::Fleet)
            ->where('resource_id', $item->id)
            ->exists()
            || ServiceVariantResource::query()
                ->where('resource_type', ResourceType::Fleet)
                ->where('resource_id', $item->id)
                ->exists()
            || Allocation::query()->where('fleet_item_id', $item->id)->exists()
            || Hold::query()->where('fleet_item_id', $item->id)->exists();

        if ($linked) {
            $this->addError('fleet-'.$id, 'That item is on a service or a booking, so it stays.');

            return;
        }

        $name = $item->name;
        $item->delete();
        $this->audit('fleet_item.deleted', $item);
        $this->flash = $name.' removed from the fleet.';
    }

    public function render(): View
    {
        $locations = Location::query()->orderBy('name')->get();

        if ($this->locationId === '' && $locations->isNotEmpty()) {
            $this->locationId = $locations->first()->id;
        }

        $assets = Asset::query()->with(['location', 'replacement'])->orderBy('name')->get();

        return view('livewire.staff.setup.resources', [
            'locations' => $locations,
            'pools' => Pool::query()->orderBy('name')->get()->groupBy('location_id'),
            'assets' => $assets->groupBy('location_id'),
            'fleetItems' => FleetItem::query()->orderBy('name')->get(),
            'retirement' => $this->retirementView($assets),
            'canManage' => $this->allows('inventory.manage'),
            'canMoveBookings' => $this->allows('bookings.manage'),
            'canManageFleet' => auth()->user()?->can('create', FleetItem::class) ?? false,
            'statuses' => ResourceStatus::cases(),
        ])->title($this->tenant()->industry->catalog());
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @return array<string, array{offers: list<array{booking_id: string, reference: string, when: string, customer: string, from_asset_id: string, to_asset_id: string, to_name: string}>, candidates: list<array{id: string, name: string}>}>
     */
    private function retirementView(Collection $assets): array
    {
        $retirement = app(AssetRetirement::class);
        $rows = [];

        foreach ($assets as $asset) {
            $rows[$asset->id] = [
                'offers' => $asset->status === ResourceStatus::Retired ? $this->offerRows($asset, $retirement) : [],
                'candidates' => $this->retireAssetId === $asset->id ? $this->candidates($asset) : [],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function candidates(Asset $asset): array
    {
        if (! is_string($asset->resource_category_id) || $asset->resource_category_id === '') {
            return [];
        }

        return Asset::query()
            ->where('location_id', $asset->location_id)
            ->where('resource_category_id', $asset->resource_category_id)
            ->where('status', ResourceStatus::Active)
            ->whereKeyNot($asset->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Asset $row): array => ['id' => $row->id, 'name' => $row->name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{booking_id: string, reference: string, when: string, customer: string, from_asset_id: string, to_asset_id: string, to_name: string}>
     */
    private function offerRows(Asset $asset, AssetRetirement $retirement): array
    {
        $offers = $retirement->offersFor($asset);

        if ($offers === []) {
            return [];
        }

        $bookings = Booking::query()->with('customer')->whereIn('id', array_column($offers, 'booking_id'))->get()->keyBy('id');
        $zone = $asset->location?->timezone;
        $zone = is_string($zone) && $zone !== '' ? $zone : 'UTC';
        $toName = $asset->replacement instanceof Asset ? $asset->replacement->name : 'the replacement';
        $rows = [];

        foreach ($offers as $offer) {
            $booking = $bookings->get($offer['booking_id']);

            if (! $booking instanceof Booking) {
                continue;
            }

            $customer = $booking->customer?->name;
            $rows[] = [
                'booking_id' => $booking->id,
                'reference' => BookingReference::short($booking->id),
                'when' => Display::dateTime($booking->starts_at, $zone),
                'customer' => is_string($customer) && $customer !== '' ? $customer : 'Guest',
                'from_asset_id' => $offer['from_asset_id'],
                'to_asset_id' => $offer['to_asset_id'],
                'to_name' => $toName,
            ];
        }

        return $rows;
    }

    private function fleetItem(string $id): FleetItem
    {
        $item = FleetItem::query()->find($id);
        abort_unless($item instanceof FleetItem, 404);

        return $item;
    }

    private function asset(string $id): Asset
    {
        $asset = Asset::query()->find($id);
        abort_unless($asset instanceof Asset, 404);

        return $asset;
    }

    private function resource(string $type, string $id): Asset|Pool
    {
        $resource = match ($type) {
            'asset' => Asset::query()->find($id),
            'pool' => Pool::query()->find($id),
            default => null,
        };
        abort_unless($resource instanceof Asset || $resource instanceof Pool, 404);

        return $resource;
    }

    private function wholeMinutes(mixed $minutes): ?int
    {
        if (is_int($minutes)) {
            return $minutes >= 0 && $minutes <= 1440 ? $minutes : null;
        }

        if (! is_string($minutes) || preg_match('/^\d{1,4}$/', $minutes) !== 1) {
            return null;
        }

        $value = (int) $minutes;

        return $value <= 1440 ? $value : null;
    }
}
