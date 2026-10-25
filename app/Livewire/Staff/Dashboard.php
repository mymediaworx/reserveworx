<?php

namespace App\Livewire\Staff;

use App\Enums\BookingStatus;
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
use App\Services\Onboarding\OnboardingChecklist;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Signup\SignupVerification;
use App\Services\TenantLifecycle;
use App\Services\Workspaces\WorkspaceDirectory;
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

        $setting = TenantSetting::query()->first();
        $currency = $setting instanceof TenantSetting ? $setting->currency : 'USD';
        $user = auth()->user();
        $firstName = trim((string) strtok((string) ($user->name ?? ''), ' '));

        $onboarding = $this->tenant()->status === TenantStatus::Onboarding;
        $progress = $onboarding ? app(OnboardingChecklist::class)->assess($this->tenant()) : null;

        return view('livewire.staff.dashboard', [
            'zone' => $zone,
            'now' => $now,
            'firstName' => $firstName,
            'today' => $today,
            'departures' => $departures,
            'todayCount' => $today->count(),
            'overdueCount' => RentalCheckout::query()->whereNull('returned_at')->where('due_at', '<', CarbonImmutable::now('UTC'))->count(),
            'waitingCount' => Booking::query()->where('status', BookingStatus::Held->value)->count(),
            'upcomingCount' => Booking::query()
                ->where('starts_at', '>', $end)
                ->where('starts_at', '<', $now->addDays(7)->endOfDay()->utc())
                ->whereIn('status', $active)
                ->count(),
            'paidCents' => (int) Payment::query()
                ->where('status', 'paid')
                ->where('created_at', '>=', $now->subDays(30)->utc())
                ->sum('amount_cents'),
            'currency' => $currency,
            'customers' => $this->customersFor($today),
            'zones' => $this->zones(),
            'isEmptyWorkspace' => ! Location::query()->exists() || ! Service::query()->exists(),
            'canManageSetup' => $this->allows('inventory.manage'),
            'onboarding' => $onboarding,
            'progress' => $progress,
            'isOwner' => $user instanceof User && app(WorkspaceDirectory::class)->isOwner($user, $this->tenant()->id),
            'canResend' => $progress instanceof OnboardingProgress && $user instanceof User && $progress->canResend($user),
        ]);
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
