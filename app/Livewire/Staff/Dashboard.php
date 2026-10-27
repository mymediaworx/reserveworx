<?php

namespace App\Livewire\Staff;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\TenantStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Livewire\Staff\Concerns\LoadsBookingRows;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Payment;
use App\Models\RentalCheckout;
use App\Models\Service;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Booking\BookingListFilters;
use App\Services\Onboarding\OnboardingChecklist;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Onboarding\SetupGuide;
use App\Services\Scheduling\TourMinimum;
use App\Services\Signup\SignupVerification;
use App\Services\TenantLifecycle;
use App\Services\Workspaces\WorkspaceDirectory;
use App\Tenancy\Access;
use App\Tenancy\StaffLocationAccess;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Home')]
class Dashboard extends Component
{
    use LoadsBookingRows, WithinTenant;

    public ?string $problem = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);
    }

    public function render(): View
    {
        $zone = $this->defaultTimezone();
        $now = CarbonImmutable::now($zone);
        $start = $now->startOfDay()->utc();
        $end = $now->endOfDay()->utc();
        $active = [BookingStatus::Confirmed->value, BookingStatus::Held->value, BookingStatus::PendingPayment->value];

        $today = Booking::query()
            ->with('service')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->whereNotIn('status', [BookingStatus::Cancelled->value])
            ->orderBy('starts_at')
            ->get();

        $departures = Departure::query()
            ->with('service')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();
        $upcomingDepartures = Departure::query()
            ->with('service')
            ->where('status', DepartureStatus::Scheduled)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addDays(TourMinimum::HOME_HORIZON_DAYS))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
        $departureSummary = app(TourMinimum::class)->summarize(
            $departures->concat($upcomingDepartures)->unique('id')->values(),
            $now,
        );
        $belowMinimum = $upcomingDepartures
            ->filter(fn (Departure $departure): bool => $departureSummary[$departure->id]['belowMinimum'] ?? false)
            ->values();

        $setting = TenantSetting::query()->first();
        $currency = $setting instanceof TenantSetting ? $setting->currency : 'USD';
        $user = auth()->user();
        $firstName = trim((string) strtok((string) ($user->name ?? ''), ' '));

        $onboarding = $this->tenant()->status === TenantStatus::Onboarding;
        $progress = $onboarding ? app(OnboardingChecklist::class)->assess($this->tenant()) : null;
        $access = app(StaffLocationAccess::class);
        $nowUtc = CarbonImmutable::now('UTC');
        $open = RentalCheckout::query()->whereNull('returned_at');
        $access->constrainToVisibleBookings($open);
        $overdue = (clone $open)->where('due_at', '<', $nowUtc);
        $overdueRentals = (clone $overdue)
            ->with(['booking.customer', 'booking.service', 'asset', 'pool'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(20)
            ->get();
        $later = (clone $open)->where('due_at', '>=', $nowUtc);
        $checkedOutLater = (clone $later)
            ->with(['booking.customer', 'booking.location', 'asset', 'pool'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(20)
            ->get();
        $paid = Payment::query()
            ->where('status', 'paid')
            ->where('created_at', '>=', $now->subDays(30)->utc());
        $access->constrainToVisibleBookings($paid);

        return view('livewire.staff.dashboard', [
            'zone' => $zone,
            'now' => $now,
            'firstName' => $firstName,
            'today' => $today,
            'departures' => $departures,
            'departureSummary' => $departureSummary,
            'belowMinimum' => $belowMinimum,
            'todayCount' => $today->count(),
            'overdueCount' => (clone $overdue)->count(),
            'overdueRentals' => $overdueRentals,
            'overdueAsOf' => $nowUtc,
            'checkedOutCount' => (clone $open)->count(),
            'checkedOutLaterCount' => (clone $later)->count(),
            'checkedOutLater' => $checkedOutLater,
            'attentionCount' => BookingListFilters::needsAttention(Booking::query())->count(),
            'waitingCount' => Booking::query()->whereIn('status', [BookingStatus::Held->value, BookingStatus::PendingPayment->value])->count(),
            'upcomingCount' => Booking::query()
                ->where('starts_at', '>', $end)
                ->where('starts_at', '<', $now->addDays(7)->endOfDay()->utc())
                ->whereIn('status', $active)
                ->count(),
            'paidCents' => (int) $paid->sum('amount_cents'),
            'currency' => $currency,
            'customers' => $this->customersFor($today),
            'zones' => $this->zones(),
            'isEmptyWorkspace' => ! Location::query()->exists() || ! Service::query()->exists(),
            'canManageSetup' => $this->allows('inventory.manage'),
            'onboarding' => $onboarding,
            'setupGuide' => $this->setupGuideSummary(),
            'progress' => $progress,
            'isOwner' => $user instanceof User && app(WorkspaceDirectory::class)->isOwner($user, $this->tenant()->id),
            'canResend' => $progress instanceof OnboardingProgress && $user instanceof User && $progress->canResend($user),
        ]);
    }

    /**
     * @return array{done: int, total: int}|null
     */
    private function setupGuideSummary(): ?array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! app(Access::class)->hasRole($user, $this->tenant()->id, 'owner', 'manager')) {
            return null;
        }

        $guide = app(SetupGuide::class);

        if ($guide->isHidden($this->tenant())) {
            return null;
        }

        $steps = $guide->steps($this->tenant());

        if ($guide->finished($steps)) {
            return null;
        }

        return ['done' => collect($steps)->where('state', '!=', 'todo')->count(), 'total' => count($steps)];
    }

    public function hideSetupGuide(): void
    {
        $user = $this->currentUser();
        abort_unless(app(Access::class)->hasRole($user, $this->tenant()->id, 'owner', 'manager'), 403);
        app(SetupGuide::class)->setHidden($this->tenant(), true);
        $this->notice = 'Setup guide hidden. Turn it back on any time in Settings.';
    }

    public function resendVerification(SignupVerification $verification, OnboardingChecklist $checklist): void
    {
        $tenant = $this->tenant();
        $user = $this->currentUser();
        $progress = $checklist->assess($tenant);
        abort_unless($progress->canResend($user), 403);

        $verification->send($user, $tenant);
        $this->notice = 'We sent another verification link. It expires in 60 minutes.';
        $this->problem = null;
    }

    public function goLive(TenantLifecycle $lifecycle, OnboardingChecklist $checklist): void
    {
        $tenant = $this->tenant();
        $user = $this->currentUser();
        abort_unless(app(WorkspaceDirectory::class)->isOwner($user, $tenant->id), 403);
        $this->problem = null;
        $tenant->refresh();
        $progress = $checklist->assess($tenant);

        if (! $progress->canGoLive) {
            $this->problem = 'Finish the required steps before going live.';

            return;
        }

        if (! $lifecycle->canTransition($tenant->status, TenantStatus::Active)) {
            $this->problem = 'That status change is not allowed.';

            return;
        }

        $before = $tenant->status->value;
        $tenant->status = TenantStatus::Active;
        $tenant->save();
        $this->audit('tenant.status_changed', $tenant, [
            'before' => $before,
            'after' => TenantStatus::Active->value,
            'via' => 'go_live',
        ]);
        $this->redirect('/staff', navigate: true);
    }
}
