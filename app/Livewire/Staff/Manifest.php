<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Services\Dispatch\DispatchManifest;
use App\Services\Scheduling\TourMinimum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Dispatch manifest')]
class Manifest extends Component
{
    use WithinTenant;

    #[Url]
    public string $date = '';

    #[Url]
    public string $location = '';

    public string $editing = '';

    public string $note = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);

        if (! DispatchManifest::validDate($this->date)) {
            $this->date = CarbonImmutable::now($this->activeTimezone())->toDateString();
        }
    }

    public function shift(int $direction): void
    {
        $this->date = $this->day()->addDays($direction > 0 ? 1 : -1)->toDateString();
        $this->reset('editing', 'note', 'flash');
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now($this->activeTimezone())->toDateString();
        $this->reset('editing', 'note', 'flash');
    }

    public function editNote(string $bookingId): void
    {
        $booking = $this->booking($bookingId);
        $this->authorize('view', $booking);
        $this->editing = $booking->id;
        $this->note = is_string($booking->staff_notes) ? $booking->staff_notes : '';
        $this->flash = null;
    }

    public function closeNote(): void
    {
        $this->reset('editing', 'note');
    }

    public function saveNote(): void
    {
        $booking = $this->booking($this->editing);
        $this->authorize('update', $booking);
        $validated = $this->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $text = trim((string) ($validated['note'] ?? ''));
        $booking->staff_notes = $text === '' ? null : $text;
        $booking->save();
        $this->audit('booking.staff_note', $booking, ['booking_id' => $booking->id]);
        $this->flash = 'Note saved.';
        $this->reset('editing', 'note');
    }

    public function render(DispatchManifest $manifest): View
    {
        $locations = Location::query()->orderBy('name')->orderBy('id')->get();
        $selected = null;

        if ($this->location !== '') {
            $selected = $locations->firstWhere('id', $this->location);
            abort_unless($selected instanceof Location, 404);
        }

        $shown = $selected instanceof Location ? new Collection([$selected]) : $locations;
        $sections = $manifest->sections($this->date, $shown);
        $departureRows = $this->departureRows($this->date, $shown);

        foreach ($sections as $index => $section) {
            $sections[$index]['departures'] = $departureRows[$section['id']] ?? [];
        }

        if (! $selected instanceof Location) {
            $sections = array_values(array_filter(
                $sections,
                fn (array $section): bool => $section['bookings'] !== [] || $section['departures'] !== [],
            ));
        }

        $heading = CarbonImmutable::createFromFormat('!Y-m-d', $this->date, 'UTC');

        return view('livewire.staff.manifest', [
            'locations' => $locations,
            'selected' => $selected instanceof Location ? $selected : null,
            'sections' => $sections,
            'canManage' => $this->allows('bookings.manage'),
            'headingDate' => $heading instanceof CarbonImmutable ? $heading->format('l, F j, Y') : $this->date,
        ]);
    }

    /**
     * Departures that start on this local day, with the below-minimum flag
     * already decided. A short tour stays scheduled.
     *
     * @param  Collection<int, Location>  $locations
     * @return array<string, list<array{departure: Departure, participants: int, minimum: int|null, belowMinimum: bool, timezone: string}>>
     */
    private function departureRows(string $date, Collection $locations): array
    {
        if (! DispatchManifest::validDate($date)) {
            return [];
        }

        $found = new Collection;
        $grouped = [];

        foreach ($locations as $location) {
            $zone = $this->zone($location);
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone);

            if (! $start instanceof CarbonImmutable) {
                $grouped[$location->id] = ['zone' => $zone, 'rows' => new Collection];

                continue;
            }

            $start = $start->startOfDay();
            $rows = Departure::query()
                ->with('service')
                ->where('location_id', $location->id)
                ->where('starts_at', '>=', $start->utc())
                ->where('starts_at', '<', $start->addDay()->utc())
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get();
            $grouped[$location->id] = ['zone' => $zone, 'rows' => $rows];
            $found = $found->concat($rows);
        }

        $summary = app(TourMinimum::class)->summarize($found, CarbonImmutable::now('UTC'));
        $result = [];

        foreach ($grouped as $locationId => $group) {
            $result[$locationId] = [];

            foreach ($group['rows'] as $departure) {
                $fact = $summary[$departure->id] ?? ['participants' => 0, 'minimum' => null, 'belowMinimum' => false];
                $result[$locationId][] = [
                    'departure' => $departure,
                    'participants' => $fact['participants'],
                    'minimum' => $fact['minimum'],
                    'belowMinimum' => $fact['belowMinimum'],
                    'timezone' => $group['zone'],
                ];
            }
        }

        return $result;
    }

    private function zone(?Location $location): string
    {
        $zone = $location?->timezone;

        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
    }

    private function booking(string $id): Booking
    {
        $booking = Booking::query()->find($id);
        abort_unless($booking instanceof Booking, 404);

        return $booking;
    }

    private function activeTimezone(): string
    {
        if ($this->location !== '') {
            $zone = Location::query()->whereKey($this->location)->value('timezone');

            if (is_string($zone) && $zone !== '') {
                return $zone;
            }
        }

        return $this->defaultTimezone();
    }

    private function day(): CarbonImmutable
    {
        $zone = $this->activeTimezone();
        $parsed = DispatchManifest::validDate($this->date)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->date, $zone)
            : false;

        return $parsed instanceof CarbonImmutable
            ? $parsed->startOfDay()
            : CarbonImmutable::now($zone)->startOfDay();
    }
}
