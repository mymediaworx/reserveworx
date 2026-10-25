<?php

namespace App\Livewire\Staff;

use App\Enums\ResourceStatus;
use App\Enums\ReturnCondition;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Location;
use App\Models\RentalCheckout;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Booking\WaitlistPromoter;
use App\Services\Operations\ResourceReturn;
use App\Support\Lateness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Returns')]
class Returns extends Component
{
    use WithinTenant;

    #[Url]
    public string $location = '';

    /**
     * @var array<string, string>
     */
    public array $returnedAt = [];

    /**
     * @var array<string, string>
     */
    public array $condition = [];

    /**
     * @var array<string, string>
     */
    public array $note = [];

    public ?string $flash = null;

    public ?string $problem = null;

    public function mount(): void
    {
        $this->authorize('checkInAny', Booking::class);
    }

    public function render(): View
    {
        $locations = Location::query()->orderBy('name')->orderBy('id')->get();
        $selected = $this->selectedLocation($locations);
        $checkouts = $this->checkouts($selected);
        $this->fillDefaults($checkouts);

        return view('livewire.staff.returns', [
            'locations' => $locations,
            'selected' => $selected,
            'checkouts' => $checkouts,
            'now' => CarbonImmutable::now('UTC'),
        ]);
    }

    public function recordReturn(string $checkoutId, ResourceReturn $returns, WaitlistPromoter $promoter): void
    {
        $this->reset('flash', 'problem');
        $checkout = RentalCheckout::query()->with('booking.location')->find($checkoutId);
        abort_unless($checkout instanceof RentalCheckout, 404);
        $booking = $checkout->booking;
        abort_unless($booking instanceof Booking, 404);
        $this->authorize('checkIn', $booking);

        $this->validate([
            'returnedAt.'.$checkoutId => ['required', 'date_format:Y-m-d\TH:i'],
            'condition.'.$checkoutId => ['required', 'string', Rule::in(ReturnCondition::staffValues())],
            'note.'.$checkoutId => ['nullable', 'string', 'max:500'],
        ], [
            'condition.'.$checkoutId.'.in' => 'Condition must be good, needs attention, or damaged.',
        ]);

        $rawTime = $this->returnedAt[$checkoutId];
        $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $rawTime, $this->zone($booking->location));

        if (! $parsed instanceof CarbonImmutable) {
            $this->addError('returnedAt.'.$checkoutId, 'Enter the time it came back.');

            return;
        }

        try {
            $result = $returns->record(
                $checkout,
                $parsed,
                ReturnCondition::from($this->condition[$checkoutId]),
                $this->note[$checkoutId] ?? null,
                $this->currentUser()->id,
            );
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->addError('returnedAt.'.$checkoutId, is_string($message) ? $message : 'Check the return and try again.');

            return;
        } catch (AllocationUnavailableException $exception) {
            $this->problem = $exception->getMessage();

            return;
        }

        if ($result['released_start'] instanceof CarbonImmutable && $result['released_end'] instanceof CarbonImmutable) {
            $promoter->afterRelease($result['booking'], $result['released_start'], $result['released_end']);
        }

        $this->audit('rental.returned', $result['return'], [
            'booking_id' => $result['booking']->id,
            'checkout_id' => $result['checkout']->id,
            'asset_id' => $result['checkout']->asset_id,
            'pool_id' => $result['checkout']->pool_id,
            'condition' => $result['return']->condition->value,
            'minutes_late' => $result['minutes_late'],
            'returned_at' => $parsed->utc()->toIso8601String(),
        ]);

        $assetId = $result['maintained_asset_id'];

        if (is_string($assetId) && $assetId !== '') {
            $asset = Asset::query()->find($assetId);

            if ($asset instanceof Asset) {
                $this->audit('asset.updated', $asset, [
                    'status' => ResourceStatus::Maintenance->value,
                ]);
            }
        }

        $late = $result['minutes_late'];
        $this->flash = $late > 0
            ? 'Returned. '.$late.' '.($late === 1 ? 'minute' : 'minutes').' late.'
            : 'Returned on time.';
        unset($this->returnedAt[$checkoutId], $this->condition[$checkoutId], $this->note[$checkoutId]);
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
     * @return Collection<int, RentalCheckout>
     */
    private function checkouts(?Location $selected): Collection
    {
        $now = CarbonImmutable::now('UTC');
        $query = RentalCheckout::query()
            ->with(['booking.service', 'booking.customer', 'booking.location', 'asset', 'pool'])
            ->whereNull('returned_at')
            ->orderBy('due_at')
            ->orderBy('id');

        if ($selected instanceof Location) {
            $query->whereHas('booking', fn ($booking) => $booking->where('location_id', $selected->id));
        }

        return $query->limit(300)->get()
            ->filter(function (RentalCheckout $checkout) use ($now): bool {
                $booking = $checkout->booking;

                if (! $booking instanceof Booking) {
                    return false;
                }

                $zone = $this->zone($booking->location);
                $due = CarbonImmutable::instance($checkout->due_at)->utc();

                return Lateness::overdue($due, $now) || $due->timezone($zone)->isSameDay($now->timezone($zone));
            })
            ->sortBy([
                fn (RentalCheckout $checkout): int => Lateness::overdue($checkout->due_at, $now) ? 0 : 1,
                fn (RentalCheckout $checkout): string => CarbonImmutable::instance($checkout->due_at)->utc()->format('Y-m-d H:i:s').$checkout->id,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, RentalCheckout>  $checkouts
     */
    private function fillDefaults(Collection $checkouts): void
    {
        foreach ($checkouts as $checkout) {
            $booking = $checkout->booking;
            $zone = $booking instanceof Booking ? $this->zone($booking->location) : 'UTC';

            if (! isset($this->returnedAt[$checkout->id]) || $this->returnedAt[$checkout->id] === '') {
                $this->returnedAt[$checkout->id] = CarbonImmutable::now($zone)->format('Y-m-d\TH:i');
            }

            if (! isset($this->condition[$checkout->id]) || $this->condition[$checkout->id] === '') {
                $this->condition[$checkout->id] = ReturnCondition::Good->value;
            }
        }
    }

    private function zone(?Location $location): string
    {
        $zone = $location?->timezone;

        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
    }
}
