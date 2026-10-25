<?php

namespace App\Livewire\Staff;

use App\Domain\Fees\FeeChannel;
use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DisclosureVersion;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\Pool;
use App\Models\PricingSnapshot;
use App\Models\StripeAccount;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Booking\WaitlistPromoter;
use App\Services\Payments\CheckoutService;
use App\Services\Pricing\BookingPricer;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One booking and everything staff can do to it. Each action mirrors the
 * matching API endpoint: same service call, same authorization, same audit entry.
 */
#[Layout('layouts.staff')]
class BookingShow extends Component
{
    use WithinTenant;

    #[Locked]
    public string $bookingId = '';

    public string $taxPercent = '0';

    public string $promoCode = '';

    public int $depositPercent = 100;

    public string $rescheduleStart = '';

    public string $rescheduleEnd = '';

    public string $extendEnd = '';

    public ?string $paymentUrl = null;

    public ?string $flash = null;

    public ?string $problem = null;

    public function mount(string $booking): void
    {
        $model = $this->booking($booking);
        $this->authorize('view', $model);
        $this->bookingId = $model->id;
        $zone = $this->zoneFor($model);
        $this->rescheduleStart = CarbonImmutable::instance($model->starts_at)->timezone($zone)->format('Y-m-d\TH:i');
        $this->rescheduleEnd = CarbonImmutable::instance($model->ends_at)->timezone($zone)->format('Y-m-d\TH:i');
        $this->extendEnd = $this->rescheduleEnd;
    }

    public function price(BookingPricer $pricer): void
    {
        $booking = $this->editable();
        $hasLocationTax = $this->locationHasTax($booking);
        $rules = [
            'promoCode' => ['nullable', 'string', 'max:40'],
            'depositPercent' => ['integer', 'between:1,100'],
        ];

        if (! $hasLocationTax) {
            $rules['taxPercent'] = ['required', 'numeric', 'between:0,100'];
        }

        $this->validate($rules);

        try {
            $pricer->quote(
                $booking->load('location'),
                $this->tenant(),
                FeeChannel::Direct,
                $hasLocationTax ? 0 : (int) round(((float) $this->taxPercent) * 100),
                $this->promoCode !== '' ? $this->promoCode : null,
                $this->depositPercent,
            );
            $this->notice('Price saved.');
        } catch (InvalidArgumentException $exception) {
            $this->warn($exception->getMessage());
        }
    }

    public function confirm(Allocator $allocator): void
    {
        $booking = $this->editable();

        try {
            $confirmed = $allocator->confirm($booking);
        } catch (AllocationUnavailableException $exception) {
            $this->warn($exception->getMessage());

            return;
        }

        $exception = $confirmed->status === BookingStatus::PaymentException;
        $this->audit($exception ? 'booking.payment_exception' : 'booking.confirmed', $confirmed);
        $exception ? $this->warn('That time is no longer available.') : $this->notice('Booking confirmed.');
    }

    public function recordOffline(CheckoutService $checkout): void
    {
        $booking = $this->editable();

        try {
            $payment = $checkout->recordOffline($booking);
        } catch (AllocationUnavailableException $exception) {
            $this->warn($exception->getMessage());

            return;
        }

        $this->audit('payment.offline', $payment, ['booking_id' => $booking->id]);
        $this->notice('Payment recorded. No booking fee applies to offline payments.');
    }

    public function createPaymentLink(CheckoutService $checkout): void
    {
        $booking = $this->editable();
        $back = url('/staff/bookings/'.$booking->id);

        try {
            $started = $checkout->startCheckout($booking->load('service'), $back.'?paid=1', $back);
        } catch (AllocationUnavailableException $exception) {
            $this->warn($exception->getMessage());

            return;
        }

        $this->paymentUrl = $started['url'];
        $this->notice('Payment link created. Send it to the customer or open it to take the card now.');
    }

    public function checkOut(Allocator $allocator): void
    {
        $booking = $this->editable();

        try {
            $saved = $allocator->checkout($booking);
        } catch (AllocationUnavailableException $exception) {
            $this->warn($exception->getMessage());

            return;
        }

        $this->audit('booking.checked_out', $saved);
        $this->notice('Checked out. The gear is on the water.');
    }

    public function markReturned(Allocator $allocator, WaitlistPromoter $promoter): void
    {
        $booking = $this->editable();

        try {
            $result = $allocator->returnRental($booking);
        } catch (AllocationUnavailableException $exception) {
            $this->warn($exception->getMessage());

            return;
        }

        if ($result['released_start'] instanceof CarbonImmutable && $result['released_end'] instanceof CarbonImmutable) {
            $promoter->afterRelease($result['booking'], $result['released_start'], $result['released_end']);
        }

        $this->audit('booking.returned', $result['booking']);
        $this->notice('Returned. The gear is free for the next booking.');
    }

    public function markNoShow(Allocator $allocator, WaitlistPromoter $promoter): void
    {
        $booking = $this->editable();

        try {
            $saved = $allocator->noShow($booking);
        } catch (AllocationUnavailableException $exception) {
            $this->warn($exception->getMessage());

            return;
        }

        $promoter->afterRelease($saved);
        $this->audit('booking.no_show', $saved);
        $this->notice('Marked as a no-show.');
    }

    public function cancelBooking(Allocator $allocator, WaitlistPromoter $promoter): void
    {
        $booking = $this->editable();
        $cancelled = $allocator->cancel($booking);
        $promoter->afterRelease($cancelled);
        $this->audit('booking.cancelled', $cancelled);
        $this->notice('Booking cancelled. Any refund is handled separately.');
    }

    public function setOperational(string $status): void
    {
        $booking = $this->editable();
        $value = OperationalStatus::tryFrom($status);

        if ($value === null) {
            $this->warn('Choose a valid status.');

            return;
        }

        $booking->operational_status = $value;
        $booking->save();
        $this->audit('booking.operational_status', $booking, ['operational_status' => $value->value]);
        $this->notice('Status updated.');
    }

    public function reschedule(Allocator $allocator): void
    {
        $booking = $this->editable();
        $this->validate([
            'rescheduleStart' => ['required', 'date_format:Y-m-d\TH:i'],
            'rescheduleEnd' => ['required', 'date_format:Y-m-d\TH:i'],
        ]);
        $zone = $this->zoneFor($booking);
        $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->rescheduleStart, $zone)->utc();
        $end = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->rescheduleEnd, $zone)->utc();

        if ($end <= $start) {
            $this->addError('rescheduleEnd', 'The end must be after the start.');

            return;
        }

        try {
            $saved = $allocator->reschedule($booking, $start, $end);
        } catch (AllocationUnavailableException $exception) {
            $this->warn($exception->getMessage());

            return;
        }

        $this->audit('booking.rescheduled', $saved);
        $this->notice('Rescheduled.');
    }

    public function extend(Allocator $allocator): void
    {
        $booking = $this->editable();
        $this->validate(['extendEnd' => ['required', 'date_format:Y-m-d\TH:i']]);
        $end = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->extendEnd, $this->zoneFor($booking))->utc();

        try {
            $result = $allocator->extend($booking, $end);
        } catch (AllocationUnavailableException $exception) {
            $this->warn($exception->getMessage());

            return;
        }

        if ($result['offers'] !== []) {
            $this->warn('That time is no longer available. Try a shorter extension.');

            return;
        }

        $this->audit('booking.extended', $result['booking']);
        $this->notice('Extended.');
    }

    public function render(): View
    {
        $booking = $this->booking($this->bookingId)->load(['service', 'location', 'departure', 'allocations', 'holds']);
        $zone = $this->zoneFor($booking);
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();
        $disclosure = $snapshot?->disclosure_version_id
            ? DisclosureVersion::query()->find($snapshot->disclosure_version_id)
            : null;
        $assetIds = $booking->allocations->pluck('asset_id')->concat($booking->holds->pluck('asset_id'))->filter()->unique();
        $poolIds = $booking->allocations->pluck('pool_id')->concat($booking->holds->pluck('pool_id'))->filter()->unique();
        $holdExpires = $booking->holds->max('expires_at');

        return view('livewire.staff.booking-show', [
            'booking' => $booking,
            'zone' => $zone,
            'customer' => $booking->customer_id ? Customer::query()->find($booking->customer_id) : null,
            'snapshot' => $snapshot,
            'disclosure' => $disclosure,
            'payments' => Payment::query()->where('booking_id', $booking->id)->orderBy('created_at')->get(),
            'assets' => Asset::query()->whereIn('id', $assetIds)->pluck('name', 'id'),
            'pools' => Pool::query()->whereIn('id', $poolIds)->pluck('name', 'id'),
            'holdExpires' => $holdExpires,
            'canCharge' => StripeAccount::query()->where('tenant_id', $booking->tenant_id)->first()?->canCharge() ?? false,
            'canManage' => $this->allows('bookings.manage'),
            'history' => AuditLog::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $this->tenant()->id)
                ->where('subject_type', Booking::class)
                ->where('subject_id', $booking->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(),
            'operationalStatuses' => OperationalStatus::cases(),
            'hasLocationTax' => $this->locationHasTax($booking),
        ])->title(($booking->service->name ?? 'Booking').' – '.$booking->starts_at->timezone($zone)->format('M j'));
    }

    private function locationHasTax(Booking $booking): bool
    {
        return $booking->location_id !== ''
            && LocationTaxRule::query()->where('location_id', $booking->location_id)->exists();
    }

    private function booking(string $id): Booking
    {
        $booking = Booking::query()->find($id);
        abort_unless($booking instanceof Booking, 404);

        return $booking;
    }

    private function editable(): Booking
    {
        $this->reset('flash', 'problem');
        $booking = $this->booking($this->bookingId);
        $this->authorize('update', $booking);

        return $booking;
    }

    private function zoneFor(Booking $booking): string
    {
        $zone = $booking->location?->timezone;

        return is_string($zone) && $zone !== '' ? $zone : $this->defaultTimezone();
    }

    private function notice(string $message): void
    {
        $this->flash = $message;
        $this->problem = null;
    }

    private function warn(string $message): void
    {
        $this->problem = $message;
        $this->flash = null;
    }
}
