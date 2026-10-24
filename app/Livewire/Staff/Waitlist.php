<?php

namespace App\Livewire\Staff;

use App\Enums\WaitlistStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Location;
use App\Models\Service;
use App\Models\WaitlistEntry;
use App\Services\Booking\WaitlistPromoter;
use App\Support\Display;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Waitlist')]
class Waitlist extends Component
{
    use WithinTenant;

    #[Url]
    public string $date = '';

    #[Url]
    public string $service = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', WaitlistEntry::class);

        if (! $this->validDate($this->date)) {
            $this->date = CarbonImmutable::now($this->defaultTimezone())->toDateString();
        }

        $this->assertService();
    }

    public function updatedDate(): void
    {
        if (! $this->validDate($this->date)) {
            $this->date = CarbonImmutable::now($this->defaultTimezone())->toDateString();
        }

        $this->flash = null;
    }

    public function updatedService(): void
    {
        $this->assertService();
        $this->flash = null;
    }

    public function promote(string $entryId, WaitlistPromoter $promoter): void
    {
        $entry = $this->entry($entryId);
        $this->authorize('update', $entry);

        if ($entry->status !== WaitlistStatus::Waiting) {
            $this->flash = 'That person is no longer waiting.';

            return;
        }

        if (! $promoter->promote($entry)) {
            $this->flash = 'That time is still full.';

            return;
        }

        $entry->refresh();
        $this->audit('waitlist.promoted', $entry, [
            'booking_id' => $entry->booking_id,
            'service_id' => $entry->service_id,
        ]);
        $this->flash = 'Promoted. The spot is held and the notification is queued.';
    }

    public function cancel(string $entryId): void
    {
        $entry = $this->entry($entryId);
        $this->authorize('update', $entry);

        if ($entry->status !== WaitlistStatus::Waiting) {
            $this->flash = 'That person is no longer waiting.';

            return;
        }

        $entry->status = WaitlistStatus::Cancelled;
        $entry->save();
        $this->audit('waitlist.cancelled', $entry, []);
        $this->flash = 'Removed from the waitlist.';
    }

    public function whenLabel(WaitlistEntry $entry): string
    {
        $zone = $entry->service?->location?->timezone;

        return Display::dateTime($entry->starts_at, $this->zone(is_string($zone) ? $zone : null));
    }

    public function statusLabel(WaitlistEntry $entry): string
    {
        return match ($entry->status) {
            WaitlistStatus::Waiting => 'Waiting',
            WaitlistStatus::Promoted => 'Promoted',
            WaitlistStatus::Cancelled => 'Cancelled',
        };
    }

    public function render(): View
    {
        return view('livewire.staff.waitlist', [
            'entries' => $this->entries(),
            'services' => Service::query()->orderBy('name')->orderBy('id')->get(),
            'canManage' => $this->allows('bookings.manage'),
        ]);
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    private function entries(): Collection
    {
        $locations = Location::query()->orderBy('id')->get();
        $query = WaitlistEntry::query()->with(['service.location']);

        if ($this->service !== '') {
            $query->where('service_id', $this->service);
        }

        $date = $this->date;
        $query->where(function ($outer) use ($locations, $date): void {
            if ($locations->isEmpty()) {
                $outer->whereRaw('1 = 0');

                return;
            }

            foreach ($locations as $location) {
                $start = CarbonImmutable::parse($date.' 00:00:00', $this->zone($location->timezone))->utc();
                $end = $start->addDay();
                $outer->orWhere(function ($inner) use ($location, $start, $end): void {
                    $inner->where('location_id', $location->id)
                        ->where('starts_at', '>=', $start)
                        ->where('starts_at', '<', $end);
                });
            }
        });

        return $query->orderBy('starts_at')->orderBy('created_at')->orderBy('id')->get();
    }

    private function entry(string $id): WaitlistEntry
    {
        $entry = WaitlistEntry::query()->find($id);
        abort_unless($entry instanceof WaitlistEntry, 404);

        return $entry;
    }

    private function assertService(): void
    {
        if ($this->service === '') {
            return;
        }

        abort_unless(Service::query()->whereKey($this->service)->exists(), 404);
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function zone(?string $timezone): string
    {
        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }
}
