<?php

namespace App\Livewire\Staff;

use App\Enums\ServiceKind;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Service;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\Booking\GuideRoster;
use App\Services\Booking\SlotFinder;
use App\Services\Booking\Waitlist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('New booking')]
class NewBooking extends Component
{
    use WithinTenant;

    #[Url]
    public string $serviceId = '';

    public string $date = '';

    public int $durationMinutes = 0;

    public int $participants = 1;

    public string $startsAt = '';

    public string $departureId = '';

    public string $customerName = '';

    public string $customerEmail = '';

    public string $guideUserId = '';

    public ?string $problem = null;

    public bool $offerWaitlist = false;

    #[Locked]
    public string $idempotencyKey = '';

    public function mount(): void
    {
        $this->authorize('create', Booking::class);
        $this->idempotencyKey = (string) Str::uuid();
        $this->date = CarbonImmutable::now($this->defaultTimezone())->toDateString();
        $this->selectService($this->serviceId);
    }

    public function updatedServiceId(string $value): void
    {
        $this->selectService($value);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['date', 'durationMinutes', 'participants'], true)) {
            $this->startsAt = '';
            $this->departureId = '';
        }

        $this->problem = null;
        $this->offerWaitlist = false;
    }

    public function book(Allocator $allocator): void
    {
        $this->authorize('create', Booking::class);
        $service = $this->service();
        abort_unless($service instanceof Service, 404);
        $this->validate([
            'participants' => ['required', 'integer', 'min:1', 'max:500'],
            'customerName' => ['required', 'string', 'max:120'],
            'customerEmail' => ['nullable', 'email', 'max:255'],
        ]);

        [$start, $end, $departureId] = $this->interval($service);

        if ($start === null || $end === null) {
            $this->problem = $service->kind === ServiceKind::Tour ? 'Choose a departure.' : 'Choose a start time.';

            return;
        }

        $guideUserId = $this->guideUserId !== '' ? $this->guideUserId : null;

        if ($guideUserId !== null && ! app(GuideRoster::class)->accepts($guideUserId)) {
            $this->problem = 'That guide is not an active member of this workspace.';

            return;
        }

        $intent = new AllocationIntent(
            $this->tenant()->id,
            $service->id,
            $start,
            $end,
            $this->participants,
            $this->idempotencyKey,
            $this->customerName,
            $this->customerEmail !== '' ? $this->customerEmail : null,
            $departureId,
            $guideUserId,
        );

        try {
            $booking = $allocator->hold($intent);
        } catch (AllocationUnavailableException $exception) {
            $this->problem = $exception->getMessage();
            $this->offerWaitlist = $service->waitlist_enabled;
            $this->idempotencyKey = (string) Str::uuid();

            return;
        } catch (IdempotencyConflictException $exception) {
            $this->problem = $exception->getMessage();
            $this->idempotencyKey = (string) Str::uuid();

            return;
        }

        if ($booking->created_by === null) {
            $booking->created_by = $this->currentUser()->id;
            $booking->save();
        }

        $this->audit('booking.held', $booking, [
            'service_id' => $booking->service_id,
            'starts_at' => $booking->starts_at->toIso8601String(),
            'channel' => 'staff_web',
        ]);

        $this->redirect('/staff/bookings/'.$booking->id, navigate: true);
    }

    public function joinWaitlist(Waitlist $waitlist): void
    {
        $this->authorize('create', Booking::class);
        $service = $this->service();
        abort_unless($service instanceof Service && $service->waitlist_enabled, 404);
        [$start, $end, $departureId] = $this->interval($service);

        if ($start === null || $end === null) {
            return;
        }

        $waitlist->join(
            $this->tenant()->id,
            $service->id,
            $departureId,
            $service->location_id,
            $start,
            $end,
            $this->participants,
            $this->customerName !== '' ? $this->customerName : null,
            $this->customerEmail !== '' ? $this->customerEmail : null,
            $this->currentUser()->id,
            (string) Str::uuid(),
        );

        $this->offerWaitlist = false;
        $this->problem = null;
        session()->flash('status', 'Added to the waitlist. They are offered the spot automatically if it opens up.');
        $this->redirect('/staff/bookings', navigate: true);
    }

    public function render(SlotFinder $slots): View
    {
        $services = Service::query()->with(['location', 'durations'])->orderBy('name')->get();
        $service = $services->firstWhere('id', $this->serviceId);
        $times = [];
        $departures = collect();

        if ($service instanceof Service && $this->date !== '') {
            if ($service->kind === ServiceKind::Tour) {
                $departures = $slots->departures($service, $this->date);
            } elseif ($this->durationMinutes > 0) {
                $times = $slots->rentalSlots($service, $this->date, $this->durationMinutes, max(1, $this->participants));
            }
        }

        return view('livewire.staff.new-booking', [
            'services' => $services,
            'service' => $service,
            'times' => $times,
            'departures' => $departures,
            'zone' => $service->location->timezone ?? $this->defaultTimezone(),
            'guides' => app(GuideRoster::class)->active(),
        ]);
    }

    private function selectService(string $id): void
    {
        $service = $id !== '' ? Service::query()->with('durations')->find($id) : null;
        $this->serviceId = $service instanceof Service ? $service->id : '';
        $this->durationMinutes = $service instanceof Service ? (int) ($service->durations->sortBy('duration_minutes')->first()->duration_minutes ?? 60) : 0;
        $this->participants = max(1, (int) ($service->min_participants ?? 1));
        $this->startsAt = '';
        $this->departureId = '';
    }

    private function service(): ?Service
    {
        return $this->serviceId !== '' ? Service::query()->with('location')->find($this->serviceId) : null;
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null, 2: string|null}
     */
    private function interval(Service $service): array
    {
        if ($service->kind === ServiceKind::Tour) {
            $departure = $this->departureId !== '' ? Departure::query()->find($this->departureId) : null;

            if (! $departure instanceof Departure || $departure->service_id !== $service->id) {
                return [null, null, null];
            }

            return [
                CarbonImmutable::instance($departure->starts_at)->utc(),
                CarbonImmutable::instance($departure->ends_at)->utc(),
                $departure->id,
            ];
        }

        if ($this->startsAt === '' || $this->durationMinutes < 1) {
            return [null, null, null];
        }

        try {
            $start = CarbonImmutable::parse($this->startsAt)->utc();
        } catch (\Throwable) {
            return [null, null, null];
        }

        return [$start, $start->addMinutes($this->durationMinutes), null];
    }
}
