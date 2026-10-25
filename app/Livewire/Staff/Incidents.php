<?php

namespace App\Livewire\Staff;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\Incident;
use App\Models\Location;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Open and resolved damage reports for the boats this member can see.
 * Resolving a report does not change the customer total or a maintenance window.
 */
#[Layout('layouts.staff')]
#[Title('Incidents')]
class Incidents extends Component
{
    use WithinTenant;

    #[Url]
    public string $status = 'open';

    #[Url]
    public string $severity = '';

    #[Url]
    public string $location = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Incident::class);
    }

    public function resolve(string $incidentId): void
    {
        $this->reset('flash');
        $incident = Incident::query()->find($incidentId);
        abort_unless($incident instanceof Incident, 404);
        $booking = Booking::query()->find($incident->booking_id);
        abort_unless($booking instanceof Booking, 404);
        $this->authorize('checkIn', $booking);

        if ($incident->status === IncidentStatus::Resolved) {
            $this->flash = 'Already resolved.';

            return;
        }

        $incident->status = IncidentStatus::Resolved;
        $incident->resolved_at = Carbon::now('UTC');
        $incident->resolved_by = $this->currentUser()->id;
        $incident->save();
        $this->audit('incident.resolved', $incident, [
            'booking_id' => $incident->booking_id,
            'asset_id' => $incident->asset_id,
        ]);
        $this->flash = 'Marked resolved.';
    }

    public function render(): View
    {
        $this->guardFilters();
        $locations = Location::query()->orderBy('name')->orderBy('id')->get();
        $selected = $this->selectedLocation($locations);
        $setting = $this->settings();

        return view('livewire.staff.incidents', [
            'locations' => $locations,
            'incidents' => $this->incidents($selected),
            'canResolve' => $this->allows('bookings.check_in'),
            'canMaintain' => $this->allows('inventory.view'),
            'currency' => $setting instanceof TenantSetting && $setting->currency !== '' ? $setting->currency : 'USD',
        ]);
    }

    private function guardFilters(): void
    {
        abort_unless(in_array($this->status, ['open', 'resolved', 'all'], true), 404);
        abort_unless($this->severity === '' || IncidentSeverity::tryFrom($this->severity) instanceof IncidentSeverity, 404);
    }

    /**
     * @param  Collection<int, Location>  $locations
     */
    private function selectedLocation(Collection $locations): ?Location
    {
        if ($this->location === '') {
            return null;
        }

        $selected = $locations->firstWhere('id', $this->location);
        abort_unless($selected instanceof Location, 404);

        return $selected;
    }

    /**
     * @return Collection<int, Incident>
     */
    private function incidents(?Location $selected): Collection
    {
        $query = Incident::query()
            ->with(['asset', 'photos', 'booking.customer', 'booking.location', 'booking.service', 'maintenanceWindow'])
            ->whereHas('booking')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if ($this->severity !== '') {
            $query->where('severity', $this->severity);
        }

        if ($selected instanceof Location) {
            $query->whereHas('booking', fn (Builder $booking): Builder => $booking->where('location_id', $selected->id));
        }

        return $query->limit(200)->get();
    }
}
