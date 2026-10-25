<?php

namespace App\Livewire\Staff\Setup;

use App\Enums\LocationStatus;
use App\Enums\ScheduleOwnerType;
use App\Livewire\Concerns\WithinTenant;
use App\Models\AvailabilitySchedule;
use App\Models\Blackout;
use App\Models\Location;
use App\Tenancy\Access;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Locations')]
class Locations extends Component
{
    use WithinTenant;

    public string $name = '';

    public string $timezone = 'America/New_York';

    public string $editingId = '';

    public string $editName = '';

    public string $editTimezone = '';

    public string $editStatus = 'active';

    public string $hoursLocationId = '';

    /** @var list<int> */
    public array $hoursDays = [1, 2, 3, 4, 5, 6, 0];

    public string $opens = '09:00';

    public string $closes = '18:00';

    public string $closedFrom = '';

    public string $closedUntil = '';

    public string $closedReason = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Location::class);
    }

    public function create(): void
    {
        $this->authorize('create', Location::class);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        $location = Location::query()->create([
            'tenant_id' => $this->tenant()->id,
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
            'status' => LocationStatus::Active,
        ]);

        $this->audit('location.created', $location);
        $this->reset('name');
        $this->flash = 'Location added. Next, add the boats and gear kept there.';
    }

    public function edit(string $id): void
    {
        $location = $this->location($id);
        $this->authorize('update', $location);
        $this->editingId = $location->id;
        $this->editName = $location->name;
        $this->editTimezone = $location->timezone;
        $this->editStatus = $location->status->value;
    }

    public function save(): void
    {
        $location = $this->location($this->editingId);
        $this->authorize('update', $location);
        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:120'],
            'editTimezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'editStatus' => ['required', Rule::enum(LocationStatus::class)],
        ]);

        $location->fill([
            'name' => $validated['editName'],
            'timezone' => $validated['editTimezone'],
            'status' => $validated['editStatus'],
        ])->save();

        $this->audit('location.updated', $location);
        $this->editingId = '';
        $this->flash = 'Location saved.';
    }

    public function addHours(): void
    {
        $this->authorize('create', AvailabilitySchedule::class);
        $location = $this->location($this->hoursLocationId);
        $this->validate([
            'hoursDays' => ['required', 'array', 'min:1'],
            'hoursDays.*' => ['integer', 'between:0,6'],
            'opens' => ['required', 'date_format:H:i'],
            'closes' => ['required', 'date_format:H:i', 'after:opens'],
        ]);

        foreach (array_unique($this->hoursDays) as $weekday) {
            $row = AvailabilitySchedule::query()->create([
                'tenant_id' => $this->tenant()->id,
                'owner_type' => ScheduleOwnerType::Location,
                'owner_id' => $location->id,
                'weekday' => (int) $weekday,
                'opens_local' => $this->opens.':00',
                'closes_local' => $this->closes.':00',
            ]);
            $this->audit('schedule.created', $row, ['owner_type' => 'location', 'weekday' => (int) $weekday]);
        }

        $this->flash = 'Opening hours added.';
    }

    public function removeHours(string $id): void
    {
        $row = AvailabilitySchedule::query()->find($id);
        abort_unless($row instanceof AvailabilitySchedule, 404);
        $this->authorize('delete', $row);
        $row->delete();
        $this->audit('schedule.deleted', $row);
    }

    public function addClosure(): void
    {
        $this->authorize('create', Blackout::class);
        $location = $this->location($this->hoursLocationId);
        $this->validate([
            'closedFrom' => ['required', 'date'],
            'closedUntil' => ['required', 'date', 'after_or_equal:closedFrom'],
            'closedReason' => ['nullable', 'string', 'max:200'],
        ]);

        $row = Blackout::query()->create([
            'tenant_id' => $this->tenant()->id,
            'location_id' => $location->id,
            'starts_on' => $this->closedFrom,
            'ends_on' => $this->closedUntil,
            'reason' => $this->closedReason !== '' ? $this->closedReason : null,
        ]);

        $this->audit('blackout.created', $row, ['starts_on' => $this->closedFrom, 'ends_on' => $this->closedUntil]);
        $this->reset('closedFrom', 'closedUntil', 'closedReason');
        $this->flash = 'Closure added. No bookings can start or end on those dates.';
    }

    public function removeClosure(string $id): void
    {
        $row = Blackout::query()->find($id);
        abort_unless($row instanceof Blackout, 404);
        $this->authorize('delete', $row);
        $row->delete();
        $this->audit('blackout.deleted', $row);
    }

    public function render(): View
    {
        $locations = Location::query()->orderBy('name')->get();

        if ($this->hoursLocationId !== '' && ! $locations->contains('id', $this->hoursLocationId)) {
            $this->hoursLocationId = (string) ($locations->first()->id ?? '');
        }

        if ($this->hoursLocationId === '' && $locations->isNotEmpty()) {
            $this->hoursLocationId = $locations->first()->id;
        }

        return view('livewire.staff.setup.locations', [
            'locations' => $locations,
            'hours' => AvailabilitySchedule::query()
                ->where('owner_type', ScheduleOwnerType::Location->value)
                ->where('owner_id', $this->hoursLocationId)
                ->orderBy('weekday')
                ->orderBy('opens_local')
                ->get(),
            'closures' => Blackout::query()
                ->where('location_id', $this->hoursLocationId)
                ->orderBy('starts_on')
                ->get(),
            'canManage' => $this->allows('inventory.manage'),
            'canSetTax' => app(Access::class)->hasRole($this->currentUser(), $this->tenant()->id, 'owner', 'manager'),
            'zones' => timezone_identifiers_list(),
            'weekdays' => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'],
        ]);
    }

    private function location(string $id): Location
    {
        $location = Location::query()->find($id);
        abort_unless($location instanceof Location, 404);

        return $location;
    }
}
