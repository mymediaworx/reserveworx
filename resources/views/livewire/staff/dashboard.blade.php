@php use App\Support\Display; @endphp
<div class="page">
    <div class="welcome">
        <h1>Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}</h1>
        <button type="button" class="icon-button" wire:click="$refresh" aria-label="Refresh">
            <x-icon name="refresh" size="26" wire:loading.class="spin" />
        </button>
    </div>

    @if ($onboarding && $progress instanceof \App\Services\Onboarding\OnboardingProgress)
        @include('livewire.staff.onboarding-checklist')
    @elseif ($isEmptyWorkspace)
        <section class="notice">
            <h2>Let’s get you taking bookings</h2>
            <p>{{ $industry->dashboardSetup() }}</p>
            <a class="button primary" href="/staff/setup/locations" wire:navigate>Start setup</a>
        </section>
    @endif

    <div class="stat-grid">
        <a class="stat-card" href="/staff/calendar?view=day" wire:navigate>
            <span class="stat-strip"><x-icon name="history" size="16" /> Today</span>
            <span class="stat-body">
                <span class="stat-title">Bookings</span>
                <span class="icon-circle tone-ok"><x-icon name="bookings" size="22" /></span>
                @if ($todayCount === 0)
                    <span class="stat-good">All clear</span>
                @else
                    <span class="stat-value">{{ $todayCount }}</span>
                @endif
            </span>
        </a>
        <a class="stat-card" href="/staff/bookings?when=all" wire:navigate>
            <span class="stat-strip"><x-icon name="history" size="16" /> Last 30 days</span>
            <span class="stat-body">
                <span class="stat-title">Payments</span>
                <span class="icon-circle tone-ok"><x-icon name="dollar" size="22" /></span>
                <span class="stat-value">{{ Display::money($paidCents, $currency) }}</span>
            </span>
        </a>
        <a class="stat-card" href="/staff/bookings?status=held" wire:navigate>
            <span class="stat-strip"><x-icon name="history" size="16" /> Right now</span>
            <span class="stat-body">
                <span class="stat-title">Waiting on payment</span>
                <span class="icon-circle tone-accent"><x-icon name="clock" size="22" /></span>
                @if ($waitingCount === 0)
                    <span class="stat-good">All caught up</span>
                @else
                    <span class="stat-value">{{ $waitingCount }}</span>
                @endif
            </span>
        </a>
        <a class="stat-card" href="/staff/calendar" wire:navigate>
            <span class="stat-strip"><x-icon name="history" size="16" /> Next 7 days</span>
            <span class="stat-body">
                <span class="stat-title">Upcoming</span>
                <span class="icon-circle tone-brand"><x-icon name="calendar" size="22" /></span>
                <span class="stat-value">{{ $upcomingCount }}</span>
            </span>
        </a>
    </div>

    <section class="panel" aria-labelledby="shortcuts-heading">
        <div class="panel-head">
            <h2 id="shortcuts-heading">Shortcuts</h2>
        </div>
        <div class="app-grid">
            <a href="/staff/bookings/new" wire:navigate><span class="app-circle"><x-icon name="plus" size="26" /></span>New booking</a>
            <a href="/staff/calendar" wire:navigate><span class="app-circle"><x-icon name="calendar" size="26" /></span>Calendar</a>
            <a href="/staff/share" wire:navigate><span class="app-circle"><x-icon name="share" size="26" /></span>Share link</a>
            <a href="/staff/setup/services" wire:navigate><span class="app-circle"><x-icon name="tag" size="26" /></span>Services</a>
        </div>
    </section>

    <section class="panel" aria-labelledby="today-heading">
        <div class="panel-head">
            <h2 id="today-heading">Today’s schedule</h2>
            <span class="muted small">{{ $now->format('l, F j') }}</span>
        </div>
        @if ($today->isEmpty() && $departures->isEmpty())
            <p class="empty">Nothing booked today. New bookings show up here as they come in.</p>
        @else
            <ol class="time-rail">
                @foreach ($today as $booking)
                    <x-booking-row :booking="$booking" :customer="$customers->get($booking->customer_id)" :zone="$zones->get($booking->location_id, $zone)" />
                @endforeach
            </ol>
            @if ($departures->isNotEmpty())
                <h3>Tour departures</h3>
                <ul class="plain-list">
                    @foreach ($departures as $departure)
                        <li>
                            <strong>{{ Display::time($departure->starts_at, $zones->get($departure->location_id, $zone)) }}</strong>
                            {{ $departure->service?->name }}
                            <span class="muted">{{ $departure->headcount() }}{{ $departure->seats ? ' of '.$departure->seats : '' }} booked</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </section>
</div>
