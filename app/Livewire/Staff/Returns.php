<?php

namespace App\Livewire\Staff;

use App\Enums\LateFeeDecision;
use App\Enums\ResourceStatus;
use App\Enums\ReturnCondition;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Location;
use App\Models\RentalCheckout;
use App\Models\TenantSetting;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Booking\WaitlistPromoter;
use App\Services\Operations\ResourceReturn;
use App\Support\LateFee;
use App\Support\Lateness;
use App\Tenancy\StaffLocationAccess;
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

    /**
     * @var array<string, string>
     */
    public array $lateFeeAction = [];

    /**
     * @var array<string, string>
     */
    public array $lateFeeReason = [];

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

        $setting = $this->settings();

        return view('livewire.staff.returns', [
            'locations' => $locations,
            'selected' => $selected,
            'checkouts' => $checkouts,
            'now' => CarbonImmutable::now('UTC'),
            'graceMinutes' => LateFee::graceMinutes($setting),
            'lateFeeRate' => $setting instanceof TenantSetting ? $setting->late_fee_per_minute_cents : null,
            'currency' => $setting instanceof TenantSetting && $setting->currency !== '' ? $setting->currency : 'USD',
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

        $rules = [
            'returnedAt.'.$checkoutId => ['required', 'date_format:Y-m-d\TH:i'],
            'condition.'.$checkoutId => ['required', 'string', Rule::in(ReturnCondition::staffValues())],
            'note.'.$checkoutId => ['nullable', 'string', 'max:500'],
        ];
        $messages = [
            'condition.'.$checkoutId.'.in' => 'Condition must be good, needs attention, or damaged.',
        ];
        $zone = $this->zone($booking->location);
        $rawTime = $this->returnedAt[$checkoutId] ?? '';
        $preview = $this->assess($checkout, $rawTime, $zone);

        if ($preview['cents'] > 0) {
            $rules['lateFeeAction.'.$checkoutId] = ['required', 'string', Rule::in([LateFeeDecision::Applied->value, LateFeeDecision::Waived->value])];
            $rules['lateFeeReason.'.$checkoutId] = ['nullable', 'string', 'max:500'];
            $messages['lateFeeAction.'.$checkoutId.'.required'] = 'Apply the late fee or waive it.';
            $messages['lateFeeAction.'.$checkoutId.'.in'] = 'Apply the late fee or waive it.';

            if (($this->lateFeeAction[$checkoutId] ?? '') === LateFeeDecision::Waived->value) {
                $rules['lateFeeReason.'.$checkoutId] = ['required', 'string', 'min:3', 'max:500'];
                $messages['lateFeeReason.'.$checkoutId.'.required'] = 'Say why the late fee is waived.';
                $messages['lateFeeReason.'.$checkoutId.'.min'] = 'Say why the late fee is waived.';
            }
        }

        $this->validate($rules, $messages);

        $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $rawTime, $zone);

        if (! $parsed instanceof CarbonImmutable) {
            $this->addError('returnedAt.'.$checkoutId, 'Enter the time it came back.');

            return;
        }

        try {
            $choice = $this->lateFeeAction[$checkoutId] ?? '';
            $decision = $preview['cents'] > 0 && $choice !== ''
                ? LateFeeDecision::from($choice)
                : null;
            $result = $returns->record(
                $checkout,
                $parsed,
                ReturnCondition::from($this->condition[$checkoutId]),
                $this->note[$checkoutId] ?? null,
                $this->currentUser()->id,
                $decision,
                $this->lateFeeReason[$checkoutId] ?? null,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $message = $messages[0] ?? 'Check the return and try again.';
                $field = match ($key) {
                    'late_fee' => 'lateFeeAction.'.$checkoutId,
                    'late_fee_reason' => 'lateFeeReason.'.$checkoutId,
                    'note' => 'note.'.$checkoutId,
                    'condition' => 'condition.'.$checkoutId,
                    default => 'returnedAt.'.$checkoutId,
                };
                $this->addError($field, is_string($message) ? $message : 'Check the return and try again.');
            }

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
            'late_fee_cents' => $result['late_fee_cents'],
            'late_fee_decision' => $result['late_fee_decision'],
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

        if ($late > 0) {
            $this->flash = 'Returned. '.$late.' '.($late === 1 ? 'minute' : 'minutes').' late.';
        } elseif ($result['within_grace']) {
            $this->flash = 'Returned within the grace period.';
        } else {
            $this->flash = 'Returned on time.';
        }

        unset(
            $this->returnedAt[$checkoutId],
            $this->condition[$checkoutId],
            $this->note[$checkoutId],
            $this->lateFeeAction[$checkoutId],
            $this->lateFeeReason[$checkoutId],
        );
    }

    /**
     * @return array{cents: int}
     */
    private function assess(RentalCheckout $checkout, string $rawTime, string $zone): array
    {
        $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $rawTime, $zone);

        if (! $parsed instanceof CarbonImmutable) {
            return ['cents' => 0];
        }

        $due = $checkout->due_at;
        $setting = $this->settings();
        $minutes = LateFee::billableMinutes($due, $parsed, LateFee::graceMinutes($setting));
        $rate = $setting instanceof TenantSetting ? $setting->late_fee_per_minute_cents : null;

        return ['cents' => LateFee::amountCents($minutes, $rate)];
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
        app(StaffLocationAccess::class)->constrainToVisibleBookings($query);

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
