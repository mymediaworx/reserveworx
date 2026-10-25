<?php

namespace App\Livewire\Staff;

use App\Enums\StaffUndoKind;
use App\Livewire\Concerns\PresentsUndoToast;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Asset;
use App\Models\Location;
use App\Models\LocationClosure;
use App\Models\Pool;
use App\Models\ResourceCategory;
use App\Services\Inventory\ClosureBookings;
use App\Services\Inventory\ClosureWriter;
use App\Services\Staff\StaffUndo;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Closures')]
class Closures extends Component
{
    use PresentsUndoToast, WithinTenant;

    public string $locationId = '';

    public string $scope = 'location';

    public string $resourceCategoryId = '';

    public string $resourceKey = '';

    public string $reason = '';

    public string $span = 'day';

    public bool $yearly = false;

    public string $startsOn = '';

    public string $endsOn = '';

    public string $startsLocal = '12:00';

    public string $endsLocal = '17:00';

    public string $editingId = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', LocationClosure::class);
        $today = now($this->defaultTimezone())->toDateString();
        $this->startsOn = $today;
        $this->endsOn = $today;
    }

    public function edit(string $id): void
    {
        $closure = $this->closure($id);
        $this->authorize('update', $closure);
        $this->resetErrorBag();
        $this->editingId = $closure->id;
        $this->locationId = $closure->location_id;
        $this->reason = $closure->reason;
        $this->span = $closure->all_day ? 'day' : 'hours';
        $this->yearly = $closure->recurs_yearly;
        $this->startsOn = $closure->starts_on->toDateString();
        $this->endsOn = $closure->ends_on->toDateString();
        $this->startsLocal = $this->clock($closure->starts_local) ?? '12:00';
        $this->endsLocal = $this->clock($closure->ends_local) ?? '17:00';

        if ($closure->resource_id !== null && $closure->resource_type !== null) {
            $this->scope = 'resource';
            $this->resourceKey = $closure->resource_type.':'.$closure->resource_id;
            $this->resourceCategoryId = '';
        } elseif ($closure->resource_category_id !== null) {
            $this->scope = 'category';
            $this->resourceCategoryId = $closure->resource_category_id;
            $this->resourceKey = '';
        } else {
            $this->scope = 'location';
            $this->resourceCategoryId = '';
            $this->resourceKey = '';
        }
    }

    public function cancelEdit(): void
    {
        $this->authorize('viewAny', LocationClosure::class);
        $this->resetForm();
    }

    public function save(ClosureWriter $writer, ClosureBookings $bookings): void
    {
        $this->resetErrorBag();
        $existing = null;

        if ($this->editingId !== '') {
            $existing = $this->closure($this->editingId);
            $this->authorize('update', $existing);
        } else {
            $this->authorize('create', LocationClosure::class);
        }

        try {
            $saved = $writer->save($this->tenant(), [
                'location_id' => $this->locationId,
                'scope' => $this->scope,
                'resource_category_id' => $this->resourceCategoryId,
                'resource_key' => $this->resourceKey,
                'reason' => $this->reason,
                'span' => $this->span,
                'yearly' => $this->yearly,
                'starts_on' => $this->startsOn,
                'ends_on' => $this->endsOn,
                'starts_local' => $this->span === 'hours' ? $this->startsLocal : null,
                'ends_local' => $this->span === 'hours' ? $this->endsLocal : null,
            ], $existing);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $count = $bookings->overlapping($saved)->count();
        $this->audit($existing instanceof LocationClosure ? 'closure.updated' : 'closure.created', $saved, [
            'location_id' => $saved->location_id,
            'starts_on' => $saved->starts_on->toDateString(),
            'ends_on' => $saved->ends_on->toDateString(),
            'all_day' => $saved->all_day,
            'recurs_yearly' => $saved->recurs_yearly,
        ]);
        $this->resetForm();
        $this->flash = $count === 0
            ? 'Closure saved.'
            : 'Closure saved. '.$count.' confirmed '.($count === 1 ? 'booking overlaps' : 'bookings overlap').' it. Nothing was cancelled or refunded.';
    }

    public function remove(string $id, StaffUndo $undo): void
    {
        $closure = $this->closure($id);
        $this->authorize('delete', $closure);
        $closure->delete();
        $this->audit('closure.deleted', $closure);

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->rememberUndo($undo->offer(
            $this->currentUser(),
            StaffUndoKind::ClosureDelete,
            'Closure removed.',
            $closure->location_id,
            $closure,
            [
                'reason' => $closure->reason,
                'location_id' => $closure->location_id,
                'starts_on' => $closure->starts_on->toDateString(),
                'ends_on' => $closure->ends_on->toDateString(),
            ],
        ));
        $this->flash = 'Closure removed. Bookings that were already confirmed stay as they are.';
    }

    public function render(ClosureBookings $bookings): View
    {
        $locations = Location::query()->orderBy('name')->get();

        if ($this->locationId !== '' && ! $locations->contains('id', $this->locationId)) {
            abort(404);
        }

        if ($this->locationId === '' && $locations->isNotEmpty()) {
            $this->locationId = (string) $locations->first()->id;
        }

        $closures = LocationClosure::query()->with(['location', 'category'])->orderBy('starts_on')->orderBy('id')->get();
        $overlaps = [];

        foreach ($closures as $closure) {
            $overlaps[$closure->id] = $bookings->overlapping($closure);
        }

        $assets = Asset::query()->orderBy('name')->get();
        $pools = Pool::query()->orderBy('name')->get();

        return view('livewire.staff.closures', [
            'locations' => $locations,
            'categories' => ResourceCategory::query()->orderBy('name')->get(),
            'assets' => $assets->where('location_id', $this->locationId)->values(),
            'pools' => $pools->where('location_id', $this->locationId)->values(),
            'closures' => $closures,
            'overlaps' => $overlaps,
            'assetNames' => $assets->pluck('name', 'id'),
            'poolNames' => $pools->pluck('name', 'id'),
            'canManage' => $this->allows('settings.update'),
        ]);
    }

    private function closure(string $id): LocationClosure
    {
        $closure = LocationClosure::query()->find($id);
        abort_unless($closure instanceof LocationClosure, 404);

        return $closure;
    }

    private function resetForm(): void
    {
        $this->resetErrorBag();
        $this->editingId = '';
        $this->scope = 'location';
        $this->resourceCategoryId = '';
        $this->resourceKey = '';
        $this->reason = '';
        $this->span = 'day';
        $this->yearly = false;
        $this->startsLocal = '12:00';
        $this->endsLocal = '17:00';
    }

    private function clock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = substr((string) $value, 0, 5);

        return strlen($text) === 5 ? $text : null;
    }
}
