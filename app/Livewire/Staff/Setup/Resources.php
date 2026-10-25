<?php

namespace App\Livewire\Staff\Setup;

use App\Enums\ResourceStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Pool;
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
    use WithinTenant;

    public string $kind = 'pool';

    public string $locationId = '';

    public string $name = '';

    public int $quantity = 1;

    public int $capacity = 1;

    public bool $isGuide = false;

    public int $turnaround = 0;

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
            'canManage' => $this->allows('inventory.manage'),
            'statuses' => ResourceStatus::cases(),
        ])->title($this->tenant()->industry->catalog());
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
