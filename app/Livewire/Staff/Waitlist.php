<?php

namespace App\Livewire\Staff;

use App\Enums\StaffUndoKind;
use App\Enums\WaitlistStatus;
use App\Livewire\Concerns\PresentsUndoToast;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Location;
use App\Models\Service;
use App\Models\WaitlistEntry;
use App\Services\Booking\WaitlistPromoter;
use App\Services\Staff\StaffUndo;
use App\Support\Display;
use App\Support\DisplayZone;
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
    use PresentsUndoToast, WithinTenant;

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

    public function cancel(string $entryId, StaffUndo $undo): void
    {
        $entry = $this->entry($entryId);
        $this->authorize('update', $entry);

        if ($entry->status !== WaitlistStatus::Waiting) {
            $this->flash = 'That person is no longer waiting.';

            return;
        }

        $snapshot = [
            'status' => $entry->status->value,
            'customer_name' => $entry->customer_name,
            'customer_email' => $entry->customer_email,
            'customer_phone' => $entry->customer_phone,
            'participants' => $entry->participants,
        ];
        $entry->status = WaitlistStatus::Cancelled;
        $entry->save();
        $this->audit('waitlist.cancelled', $entry, []);
        $this->rememberUndo($undo->offer(
            $this->currentUser(),
            StaffUndoKind::WaitlistRemove,
            'Removed from the waitlist.',
            $entry->location_id,
            $entry,
            $snapshot,
        ));
        $this->flash = 'Removed from the waitlist.';
    }

    public function whenLabel(WaitlistEntry $entry): string
    {
        $zone = $entry->service?->location?->timezone;

        return Display::rangeWithZone(
            $entry->starts_at,
            $entry->ends_at,
            $this->zone(is_string($zone) ? $zone : null),
        );
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

            $matched = false;

            foreach ($locations as $location) {
                $window = $this->localDay($date, $location->timezone);

                if ($window === null) {
                    continue;
                }

                $matched = true;
                [$start, $end] = $window;
                $outer->orWhere(function ($inner) use ($location, $start, $end): void {
                    $inner->where('location_id', $location->id)
                        ->where('starts_at', '>=', $start)
                        ->where('starts_at', '<', $end);
                });
            }

            if (! $matched) {
                $outer->whereRaw('1 = 0');
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
        return DisplayZone::resolve($timezone, $this->tenant());
    }

    /**
     * Half-open local day: midnight in this location through the next midnight.
     * A fall-back day is 25 hours. A spring-forward day is 23.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function localDay(string $date, mixed $timezone): ?array
    {
        $zone = $this->zone(is_string($timezone) ? $timezone : null);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone);

        if (! $start instanceof CarbonImmutable) {
            return null;
        }

        $start = $start->startOfDay();

        return [$start->utc(), $start->addDay()->utc()];
    }
}
