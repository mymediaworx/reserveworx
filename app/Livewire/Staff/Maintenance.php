<?php

namespace App\Livewire\Staff;

use App\Enums\MaintenanceKind;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Asset;
use App\Models\InspectionSchedule;
use App\Models\Location;
use App\Models\MaintenanceWindow;
use App\Models\Pool;
use App\Services\Operations\MaintenancePlanner;
use App\Support\DisplayZone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Maintenance windows, temporary blocks, and recurring inspections for the
 * boats and pools this member can see. A window blocks new holds through the
 * allocator and flags future bookings it touches. It never cancels them.
 */
#[Layout('layouts.staff')]
#[Title('Maintenance')]
class Maintenance extends Component
{
    use WithinTenant;

    #[Url]
    public string $location = '';

    #[Url]
    public string $resource = '';

    public string $windowResource = '';

    public string $windowKind = 'maintenance';

    public string $windowStarts = '';

    public string $windowEnds = '';

    public string $windowReason = '';

    public string $inspectionAsset = '';

    public string $inspectionName = '';

    public string $inspectionEvery = '30';

    public string $inspectionUnit = 'days';

    /**
     * @var array<string, string>
     */
    public array $doneNote = [];

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Asset::class);
        $tomorrow = CarbonImmutable::now($this->defaultTimezone())->addDay()->setTime(9, 0);
        $this->windowStarts = $tomorrow->format('Y-m-d\TH:i');
        $this->windowEnds = $tomorrow->setTime(12, 0)->format('Y-m-d\TH:i');
    }

    public function scheduleWindow(MaintenancePlanner $planner): void
    {
        $this->authorize('create', Asset::class);
        $this->reset('flash');
        $this->resetErrorBag();
        if ($this->windowResource === '') {
            $this->addError('windowResource', 'Choose a boat or pool.');

            return;
        }

        $resource = $this->resourceFromKey($this->windowResource);
        $this->authorize('update', $resource);
        $zone = $this->zoneOf($resource);

        $validated = Validator::make([
            'windowKind' => $this->windowKind,
            'windowStarts' => $this->windowStarts,
            'windowEnds' => $this->windowEnds,
            'windowReason' => trim($this->windowReason),
        ], [
            'windowKind' => ['required', Rule::enum(MaintenanceKind::class)],
            'windowStarts' => ['required', 'date_format:Y-m-d\TH:i'],
            'windowEnds' => ['required', 'date_format:Y-m-d\TH:i'],
            'windowReason' => ['required', 'string', 'max:160'],
        ], [
            'windowReason.required' => 'Add a short reason, like “Engine service”.',
        ])->validate();

        $startsAt = $this->localTime($validated['windowStarts'], $zone);
        $endsAt = $this->localTime($validated['windowEnds'], $zone);

        if (! $endsAt->greaterThan($startsAt)) {
            $this->addError('windowEnds', 'The end must be after the start.');

            return;
        }

        if (! $endsAt->greaterThan(CarbonImmutable::now())) {
            $this->addError('windowEnds', 'That time has already passed.');

            return;
        }

        $kind = MaintenanceKind::from($validated['windowKind']);
        $scheduled = $planner->schedule($resource, $kind, $startsAt, $endsAt, $validated['windowReason']);
        $this->audit('maintenance.scheduled', $scheduled['window'], [
            'kind' => $kind->value,
            'asset_id' => $scheduled['window']->asset_id,
            'pool_id' => $scheduled['window']->pool_id,
            'starts_at' => $startsAt->utc()->toIso8601String(),
            'ends_at' => $endsAt->utc()->toIso8601String(),
            'flagged_bookings' => $scheduled['flagged'],
        ]);

        $this->windowReason = '';
        $count = $scheduled['flagged'];
        $this->flash = ($kind === MaintenanceKind::Block ? 'Block saved.' : 'Maintenance scheduled.')
            .($count === 0
                ? ' No future bookings are affected.'
                : ' '.$count.' future '.($count === 1 ? 'booking is' : 'bookings are').' flagged for follow-up. Nothing was cancelled or moved.');
    }

    public function addInspection(): void
    {
        $this->authorize('create', Asset::class);
        $this->reset('flash');
        $this->resetErrorBag();
        if ($this->inspectionAsset === '') {
            $this->addError('inspectionAsset', 'Choose a boat.');

            return;
        }

        $asset = $this->resourceFromKey('asset:'.$this->inspectionAsset);
        abort_unless($asset instanceof Asset, 404);
        $this->authorize('update', $asset);

        $validated = Validator::make([
            'inspectionName' => trim($this->inspectionName),
            'inspectionEvery' => $this->inspectionEvery,
            'inspectionUnit' => $this->inspectionUnit,
        ], [
            'inspectionName' => ['required', 'string', 'max:120'],
            'inspectionEvery' => ['required', 'integer', 'min:1', $this->inspectionUnit === 'days' ? 'max:3650' : 'max:10000'],
            'inspectionUnit' => ['required', Rule::in(['days', 'uses'])],
        ])->validate();

        $every = (int) $validated['inspectionEvery'];
        $byDays = $validated['inspectionUnit'] === 'days';
        $schedule = InspectionSchedule::query()->create([
            'tenant_id' => $asset->tenant_id,
            'asset_id' => $asset->id,
            'name' => $validated['inspectionName'],
            'interval_days' => $byDays ? $every : null,
            'interval_uses' => $byDays ? null : $every,
            'next_due_at' => $byDays ? CarbonImmutable::now()->addDays($every) : null,
        ]);
        $this->audit('inspection.scheduled', $schedule, [
            'asset_id' => $asset->id,
            'interval_days' => $schedule->interval_days,
            'interval_uses' => $schedule->interval_uses,
        ]);

        $this->inspectionName = '';
        $this->flash = 'Inspection added for '.$asset->name.'.';
    }

    public function markDone(string $scheduleId, MaintenancePlanner $planner): void
    {
        $this->authorize('create', Asset::class);
        $this->reset('flash');
        $schedule = InspectionSchedule::query()->with('asset.location')->whereHas('asset')->find($scheduleId);
        abort_unless($schedule instanceof InspectionSchedule && $schedule->asset instanceof Asset, 404);
        $this->authorize('update', $schedule->asset);

        $note = trim($this->doneNote[$schedule->id] ?? '');

        if (mb_strlen($note) > 300) {
            $this->addError('doneNote.'.$schedule->id, 'Keep the note under 300 characters.');

            return;
        }

        $record = $planner->markInspectionDone($schedule, $note);
        $this->audit('inspection.done', $schedule, [
            'asset_id' => $schedule->asset_id,
            'maintenance_record_id' => $record->id,
        ]);
        unset($this->doneNote[$schedule->id]);

        $zone = DisplayZone::forLocation($schedule->asset->location);
        $this->flash = $schedule->next_due_at !== null
            ? $schedule->name.' done. Next due '.$schedule->next_due_at->setTimezone($zone)->format('M j, Y').'.'
            : $schedule->name.' done. The count of uses starts again now.';
    }

    public function render(MaintenancePlanner $planner): View
    {
        $locations = Location::query()->orderBy('name')->orderBy('id')->get();
        $selected = null;

        if ($this->location !== '') {
            $selected = $locations->firstWhere('id', $this->location);
            abort_unless($selected instanceof Location, 404);
        }

        $locationIds = $selected instanceof Location ? [$selected->id] : $locations->pluck('id')->all();
        $assets = Asset::query()->with('location')->whereIn('location_id', $locationIds)->where('is_guide', false)->orderBy('name')->get();
        $pools = Pool::query()->with('location')->whereIn('location_id', $locationIds)->orderBy('name')->get();
        $resources = $this->resourceOptions($assets, $pools, $locations->count() > 1 && ! $selected instanceof Location);

        if ($this->resource !== '' && ! $resources->has($this->resource)) {
            abort(404);
        }

        $windows = $this->windows($assets->pluck('id')->all(), $pools->pluck('id')->all());
        $now = CarbonImmutable::now();
        $inProgress = $windows->filter(fn (MaintenanceWindow $w): bool => $w->starts_at->lessThanOrEqualTo($now) && $w->ends_at->greaterThan($now))->values();
        $upcoming = $windows->filter(fn (MaintenanceWindow $w): bool => $w->starts_at->greaterThan($now))->values();
        $past = $windows->filter(fn (MaintenanceWindow $w): bool => $w->ends_at->lessThanOrEqualTo($now))->sortByDesc('ends_at')->take(20)->values();

        $affected = [];

        foreach ($inProgress->concat($upcoming) as $window) {
            $affected[$window->id] = $planner->affectedBookings($window);
        }

        $inspections = $this->inspections($assets->pluck('id')->all(), $planner);

        return view('livewire.staff.maintenance', [
            'locations' => $locations,
            'selected' => $selected,
            'resources' => $resources,
            'assets' => $assets,
            'inProgress' => $inProgress,
            'upcoming' => $upcoming,
            'past' => $past,
            'affected' => $affected,
            'inspections' => $inspections,
            'kinds' => MaintenanceKind::cases(),
            'canManage' => $this->allows('inventory.manage'),
        ]);
    }

    /**
     * @param  list<string>  $assetIds
     * @param  list<string>  $poolIds
     * @return Collection<int, MaintenanceWindow>
     */
    private function windows(array $assetIds, array $poolIds): Collection
    {
        [$type, $id] = array_pad(explode(':', $this->resource, 2), 2, '');

        return MaintenanceWindow::query()
            ->with(['asset.location', 'pool.location'])
            ->where(function (Builder $query) use ($assetIds, $poolIds): void {
                $query->whereIn('asset_id', $assetIds === [] ? [''] : $assetIds)
                    ->orWhereIn('pool_id', $poolIds === [] ? [''] : $poolIds);
            })
            ->when($type === 'asset', fn (Builder $query) => $query->where('asset_id', $id))
            ->when($type === 'pool', fn (Builder $query) => $query->where('pool_id', $id))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    /**
     * @param  list<string>  $assetIds
     * @return Collection<int, array{schedule: InspectionSchedule, due: bool, uses: int|null}>
     */
    private function inspections(array $assetIds, MaintenancePlanner $planner): Collection
    {
        [$type, $id] = array_pad(explode(':', $this->resource, 2), 2, '');

        if ($type === 'pool') {
            return collect();
        }

        return InspectionSchedule::query()
            ->with('asset.location')
            ->whereIn('asset_id', $assetIds === [] ? [''] : $assetIds)
            ->when($type === 'asset', fn (Builder $query) => $query->where('asset_id', $id))
            ->orderByRaw('next_due_at is null')
            ->orderBy('next_due_at')
            ->orderBy('name')
            ->get()
            ->map(fn (InspectionSchedule $schedule): array => [
                'schedule' => $schedule,
                'due' => $planner->isDue($schedule),
                'uses' => $schedule->interval_uses !== null ? $planner->usesSince($schedule) : null,
            ])
            ->sortByDesc('due')
            ->values()
            ->toBase();
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @param  Collection<int, Pool>  $pools
     * @return Collection<string, string>
     */
    private function resourceOptions(Collection $assets, Collection $pools, bool $withLocation): Collection
    {
        $options = collect();

        foreach ($assets as $asset) {
            $options->put('asset:'.$asset->id, $asset->name.($withLocation ? ' · '.$asset->location?->name : ''));
        }

        foreach ($pools as $pool) {
            $options->put('pool:'.$pool->id, $pool->name.' (pool)'.($withLocation ? ' · '.$pool->location?->name : ''));
        }

        return $options;
    }

    /**
     * Resolves a boat or pool through the tenant and location scopes, so one
     * in another workspace or at a location this member cannot use is a 404.
     */
    private function resourceFromKey(string $key): Asset|Pool
    {
        [$type, $id] = array_pad(explode(':', $key, 2), 2, '');
        $resource = match ($type) {
            'asset' => $id !== '' ? Asset::query()->with('location')->find($id) : null,
            'pool' => $id !== '' ? Pool::query()->with('location')->find($id) : null,
            default => null,
        };
        abort_unless($resource instanceof Asset || $resource instanceof Pool, 404);

        return $resource;
    }

    private function zoneOf(Asset|Pool $resource): string
    {
        return DisplayZone::forLocation($resource->location);
    }

    private function localTime(string $value, string $zone): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $zone) ?: CarbonImmutable::now();
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }
}
