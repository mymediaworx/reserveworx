@php
    use App\Models\Booking;
    use App\Support\Display;
    use App\Support\Lateness;
@endphp
<div class="page home-page">
    <style>
        @media (max-width: 390px) {
            .home-page { min-width: 0; }
            .home-page .below-minimum-list,
            .home-page .below-minimum-list li,
            .home-page .below-minimum-list a,
            .home-page .checked-out-list,
            .home-page .checked-out-list li,
            .home-page .checked-out-list a { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
            .home-page .below-minimum-list .badge { white-space: normal; }
        }
    </style>
    <div class="welcome">
        <h1>Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}</h1>
        <button type="button" class="icon-button" wire:click="$refresh" aria-label="Refresh">
            <x-icon name="refresh" size="26" wire:loading.class="spin" />
        </button>
    </div>

    @if ($notice && ! $onboarding)
        <p role="status">{{ $notice }}</p>
    @endif
    @if ($setupGuide)
        <section class="notice setup-guide-card" aria-labelledby="setup-guide-heading">
            <h2 id="setup-guide-heading"><x-icon name="sparkles" size="20" /> Set up your business</h2>
            <p><strong>{{ $setupGuide['done'] }} of {{ $setupGuide['total'] }}</strong> setup steps done.</p>
            <progress value="{{ $setupGuide['done'] }}" max="{{ $setupGuide['total'] }}" style="width:100%" aria-labelledby="setup-guide-heading">{{ $setupGuide['done'] }} of {{ $setupGuide['total'] }}</progress>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
                <a class="button primary" href="/staff/setup" wire:navigate>Open setup guide</a>
                <button type="button" class="button" wire:click="hideSetupGuide">Hide setup guide</button>
            </div>
        </section>
    @endif

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
        <a class="stat-card" href="{{ $checkedOutLaterCount > 0 ? '#checked-out' : '/staff/returns' }}" @if ($checkedOutLaterCount === 0) wire:navigate @endif data-checked-out="{{ $checkedOutCount }}">
            <span class="stat-strip"><x-icon name="history" size="16" /> Right now</span>
            <span class="stat-body">
                <span class="stat-title">Checked out</span>
                <span class="icon-circle {{ $checkedOutCount > 0 ? 'tone-brand' : 'tone-ok' }}"><x-icon name="return" size="22" /></span>
                @if ($checkedOutCount === 0)
                    <span class="stat-good">All clear</span>
                @else
                    <span class="stat-value">{{ $checkedOutCount }}</span>
                @endif
            </span>
        </a>
        <a class="stat-card" href="/staff/returns" wire:navigate data-overdue="{{ $overdueCount }}">
            <span class="stat-strip"><x-icon name="history" size="16" /> Right now</span>
            <span class="stat-body">
                <span class="stat-title">Overdue</span>
                <span class="icon-circle {{ $overdueCount > 0 ? 'tone-late' : 'tone-ok' }}"><x-icon name="clock" size="22" /></span>
                @if ($overdueCount === 0)
                    <span class="stat-good">All clear</span>
                @else
                    <span class="stat-value">{{ $overdueCount }}</span>
                @endif
            </span>
        </a>
        <a class="stat-card" href="/staff/bookings?status=attention&when=all" wire:navigate data-attention="{{ $attentionCount }}">
            <span class="stat-strip"><x-icon name="history" size="16" /> Right now</span>
            <span class="stat-body">
                <span class="stat-title">Needs attention</span>
                <span class="icon-circle {{ $attentionCount > 0 ? 'tone-late' : 'tone-ok' }}"><x-icon name="bell" size="22" /></span>
                @if ($attentionCount === 0)
                    <span class="stat-good">All clear</span>
                @else
                    <span class="stat-value">{{ $attentionCount }}</span>
                @endif
            </span>
        </a>
        <a class="stat-card" href="/staff/bookings?status=waiting&when=all" wire:navigate>
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

    @if ($checkedOutLaterCount > 0)
        <section class="panel" id="checked-out" aria-labelledby="checked-out-heading" data-checked-out-later="{{ $checkedOutLaterCount }}">
            <div class="panel-head">
                <h2 id="checked-out-heading">Due later</h2>
                <a href="/staff/returns" wire:navigate>Returns</a>
            </div>
            <p>These rentals are out and not due yet. Overdue rentals stay in the list below.</p>
            <ul class="plain-list checked-out-list">
                @foreach ($checkedOutLater as $checkout)
                    @php
                        $booking = $checkout->booking;
                        $due = $checkout->due_at;
                        $resource = $checkout->asset?->name ?? $checkout->pool?->name ?? 'Resource';
                        $customer = $booking?->customer;
                        $who = $customer?->name ?: ($customer?->email ?: 'Walk-in');
                        $checkoutZone = $booking instanceof Booking ? $zones->get($booking->location_id, $zone) : $zone;
                        $checkoutZone = is_string($checkoutZone) && $checkoutZone !== '' ? $checkoutZone : $zone;
                    @endphp
                    <li data-checked-out-row="{{ $checkout->id }}">
                        <strong>{{ $resource }}</strong>
                        <span>{{ $who }}</span>
                        @if ($due !== null)
                            <span>Due {{ Display::dateTime($due, $checkoutZone) }}</span>
                        @endif
                        @if ($booking instanceof Booking)
                            <a href="/staff/bookings/{{ $booking->id }}">Open booking</a>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if ($checkedOutLaterCount > $checkedOutLater->count())
                <p class="muted">Showing {{ $checkedOutLater->count() }} of {{ $checkedOutLaterCount }}.</p>
            @endif
        </section>
    @endif

    @if ($overdueCount > 0)
        <section class="panel" aria-labelledby="overdue-heading" data-overdue-list="{{ $overdueCount }}">
            <div class="panel-head">
                <h2 id="overdue-heading">Still out</h2>
                <a href="/staff/returns" wire:navigate>Returns</a>
            </div>
            <ul class="plain-list">
                @foreach ($overdueRentals as $checkout)
                    @php
                        $booking = $checkout->booking;
                        $due = $checkout->due_at;
                        $resource = $checkout->asset?->name ?? $checkout->pool?->name ?? 'Resource';
                        $customer = $booking?->customer;
                        $who = $customer?->name ?: ($customer?->email ?: 'Walk-in');
                        $minutes = $due !== null ? Lateness::minutes($due, $overdueAsOf) : 0;
                    @endphp
                    <li data-overdue-checkout="{{ $checkout->id }}" data-minutes="{{ $minutes }}">
                        <strong>{{ $resource }}</strong>
                        <span>{{ $who }}</span>
                        @if ($due !== null)
                            <span class="late">{{ Lateness::label($due, $overdueAsOf) }}</span>
                        @endif
                        @if ($booking instanceof Booking)
                            <a href="/staff/bookings/{{ $booking->id }}">Open booking</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($belowMinimum->isNotEmpty())
        <section class="panel" aria-labelledby="below-minimum-heading" data-below-minimum-count="{{ $belowMinimum->count() }}">
            <div class="panel-head">
                <h2 id="below-minimum-heading">Tours below minimum</h2>
            </div>
            <p>These departures are still short of the minimum. They stay on the schedule for you to decide.</p>
            <ul class="plain-list below-minimum-list">
                @foreach ($belowMinimum as $departure)
                    @php
                        $fact = $departureSummary[$departure->id] ?? ['participants' => 0, 'minimum' => null, 'belowMinimum' => false];
                        $departureZone = $zones->get($departure->location_id, $zone);
                        $departureDate = \Carbon\CarbonImmutable::instance($departure->starts_at)->timezone($departureZone)->toDateString();
                    @endphp
                    <li data-below-minimum="{{ $departure->id }}">
                        <strong>{{ Display::dateTime($departure->starts_at, $departureZone) }}</strong>
                        <span>{{ $departure->service?->name }}</span>
                        <span class="muted">{{ $fact['participants'] }} of {{ $fact['minimum'] }}</span>
                        <span class="badge badge-below-minimum">Below minimum</span>
                        <a href="/staff/manifest?date={{ $departureDate }}&amp;location={{ $departure->location_id }}" wire:navigate>Open manifest</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

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
                        @php
                            $fact = $departureSummary[$departure->id] ?? ['participants' => $departure->headcount(), 'minimum' => $departure->service?->min_participants, 'belowMinimum' => false];
                            $departureZone = $zones->get($departure->location_id, $zone);
                        @endphp
                        <li @if ($fact['belowMinimum']) data-below-minimum="{{ $departure->id }}" @endif>
                            <strong>{{ Display::time($departure->starts_at, $departureZone) }}</strong>
                            {{ $departure->service?->name }}
                            <span class="muted">
                                @if ($fact['belowMinimum'] && is_int($fact['minimum']))
                                    {{ $fact['participants'] }} of {{ $fact['minimum'] }}
                                @else
                                    {{ $fact['participants'] }}{{ $departure->seats ? ' of '.$departure->seats : '' }} booked
                                @endif
                            </span>
                            @if ($fact['belowMinimum'])
                                <span class="badge badge-below-minimum">Below minimum</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </section>
</div>
