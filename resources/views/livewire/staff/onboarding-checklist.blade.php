<section class="notice" aria-labelledby="onboarding-heading">
    <h2 id="onboarding-heading">Finish setting up</h2>
    <p>Customers can book after this workspace is active. Card payments stay off until Stripe can charge the connected account.</p>
    <ul class="rows">
        @if ($progress->emailRequired)
            <li class="row">
                <span @class(['badge', 'badge-confirmed' => $progress->emailVerified, 'badge-held' => ! $progress->emailVerified])>{{ $progress->emailVerified ? 'Done' : 'To do' }}</span>
                <span class="grow">
                    <strong>Verify your email</strong>
                    @unless ($progress->emailVerified)
                        <span class="muted">Open the link we sent. It expires in 60 minutes.</span>
                        @if ($canResend)
                            <button type="button" class="link-button" wire:click="resendVerification">Send the link again</button>
                        @endif
                    @endunless
                </span>
            </li>
        @endif
        <li class="row">
            <span @class(['badge', 'badge-confirmed' => $progress->stripeConnected, 'badge-held' => ! $progress->stripeConnected])>{{ $progress->stripeConnected ? 'Done' : 'To do' }}</span>
            <span class="grow">
                <strong>Connect Stripe</strong>
                <span class="muted">Card payments stay off until Stripe can charge this account.</span>
                <a href="/staff/settings" wire:navigate>Stripe settings</a>
            </span>
        </li>
        <li class="row">
            <span @class(['badge', 'badge-confirmed' => $progress->serviceAndHoursReady(), 'badge-held' => ! $progress->serviceAndHoursReady()])>{{ $progress->serviceAndHoursReady() ? 'Done' : 'To do' }}</span>
            <span class="grow">
                <strong>Add a service and opening hours</strong>
                <span class="muted">
                    @if ($progress->serviceAndHoursReady())
                        A service and opening hours are set.
                    @elseif (! $progress->serviceReady && ! $progress->hoursReady)
                        Add a service and opening hours.
                    @elseif (! $progress->serviceReady)
                        Add a service.
                    @else
                        Add opening hours for a location.
                    @endif
                </span>
                <a href="/staff/setup/services" wire:navigate>Services</a>
                <a href="/staff/setup/locations" wire:navigate>Hours</a>
            </span>
        </li>
        <li class="row">
            <span @class(['badge', 'badge-confirmed' => $progress->brandingReady, 'badge-held' => ! $progress->brandingReady])>{{ $progress->brandingReady ? 'Done' : 'To do' }}</span>
            <span class="grow">
                <strong>Branding</strong>
                <span class="muted">Set a logo, colors, or a support contact.</span>
                <a href="/staff/branding" wire:navigate>Branding</a>
            </span>
        </li>
        <li class="row">
            <span @class(['badge', 'badge-confirmed' => $progress->testBookingPlaced, 'badge-held' => ! $progress->testBookingPlaced])>{{ $progress->testBookingPlaced ? 'Done' : 'To do' }}</span>
            <span class="grow">
                <strong>Place a test booking</strong>
                <a href="/staff/bookings/new" wire:navigate>New booking</a>
            </span>
        </li>
    </ul>
    @if ($isOwner)
        <button type="button" class="button primary top-gap" wire:click="goLive" @disabled(! $progress->canGoLive)>Go live</button>
    @endif
    @if ($problem)
        <p class="error" role="alert">{{ $problem }}</p>
    @endif
    @if ($notice)
        <p role="status">{{ $notice }}</p>
    @endif
</section>
