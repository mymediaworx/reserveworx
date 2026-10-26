<?php

namespace App\Livewire\Staff\Setup;

use App\Enums\ResourceStatus;
use App\Enums\ResourceType;
use App\Enums\StaffUndoKind;
use App\Livewire\Concerns\PresentsUndoToast;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\FleetItem;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Pool;
use App\Models\ResourceRequirement;
use App\Models\ServiceVariantResource;
use App\Services\Staff\StaffUndo;
use App\Tenancy\StaffLocationAccess;
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

    public string $fleetName = '';

    public int $fleetQuantity = 1;

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
                'turnaround_minutes' => $this->turnaround,
            ]);
            $this->audit('pool.created', $resource);
        }

        $this->reset('name', 'quantity', 'capacity', 'isGuide', 'turnaround');
        $this->flash = 'Added. Link it to a service under Services & prices so it can be booked.';
    }

    public function setStatus(string $type, string $id, string $status): void
    {
        $value = ResourceStatus::tryFrom($status);
        abort_if($value === null, 422);
        $resource = $this->resource($type, $id);
        $this->authorize('update', $resource);
        $resource->status = $value;
        $resource->save();
        $this->audit($type.'.updated', $resource, ['status' => $value->value]);
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

        return view('livewire.staff.setup.resources', [
            'locations' => $locations,
            'pools' => Pool::query()->orderBy('name')->get()->groupBy('location_id'),
            'assets' => Asset::query()->orderBy('name')->get()->groupBy('location_id'),
            'fleetItems' => FleetItem::query()->orderBy('name')->get(),
            'canManage' => $this->allows('inventory.manage'),
            'canManageFleet' => auth()->user()?->can('create', FleetItem::class) ?? false,
            'statuses' => ResourceStatus::cases(),
        ])->title($this->tenant()->industry->catalog());
    }

    private function fleetItem(string $id): FleetItem
    {
        $item = FleetItem::query()->find($id);
        abort_unless($item instanceof FleetItem, 404);

        return $item;
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
}
