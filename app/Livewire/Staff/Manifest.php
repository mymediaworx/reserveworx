<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\Location;
use App\Services\Dispatch\DispatchManifest;
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

        if (! $selected instanceof Location) {
            $sections = array_values(array_filter(
                $sections,
                fn (array $section): bool => $section['bookings'] !== [],
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
