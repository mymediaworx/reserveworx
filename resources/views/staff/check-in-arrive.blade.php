@php use App\Support\Display; @endphp
<div class="page check-in-arrive">
    <style>
        .check-in-arrive { min-width: 0; }
        .check-in-arrive .wrap { overflow-wrap: anywhere; }
        @media (max-width: 390px) {
            .check-in-arrive .button { width: 100%; }
            .check-in-arrive textarea { width: 100%; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Check in</h1>
            <p class="lede wrap">{{ $arrival->booking->service?->name }}</p>
        </div>
    </header>

    @if (session('status'))
        <p class="flash ok" role="status">{{ session('status') }}</p>
    @elseif (session('problem'))
        <p class="flash" role="alert">{{ session('problem') }}</p>
    @elseif ($arrival->checkedInNow)
        <p class="flash ok" role="status">Checked in.</p>
    @elseif ($arrival->already)
        <p class="flash ok" role="status">Already checked in.</p>
    @elseif ($arrival->blocked)
        <p class="flash" role="alert">Check-in is blocked until the missing waivers are signed.</p>
    @elseif ($arrival->refused)
        <p class="flash" role="alert">This booking cannot be checked in.</p>
    @endif

    <section class="panel">
        <p class="wrap">Booking {{ $arrival->booking->id }}</p>
        <p>Arrival: {{ Display::status($arrival->booking->operational_status->value) }}</p>
        <p>
            Balance due
            @if ($arrival->balanceDueCents === null)
                No price yet
            @else
                {{ Display::money($arrival->balanceDueCents, $arrival->currency) }}
            @endif
        </p>
        @if ($arrival->waivers->complete)
            <p>Waivers are signed.</p>
        @else
            <ul>
                @foreach ($arrival->waivers->missing as $missing)
                    <li>{{ $missing['who'] }} still needs {{ $missing['waiver'] }}</li>
                @endforeach
            </ul>
        @endif
        @if (is_string($arrival->booking->check_in_waiver_override_reason) && $arrival->booking->check_in_waiver_override_reason !== '')
            <p class="wrap">Override: {{ $arrival->booking->check_in_waiver_override_reason }}</p>
        @endif
    </section>

    @if ($arrival->blocked)
        <form method="POST" action="{{ request()->getRequestUri() }}">
            @csrf
            <label>
                <span>Reason</span>
                <textarea name="reason" required minlength="3" maxlength="500"></textarea>
            </label>
            @error('reason')
                <p role="alert">{{ $message }}</p>
            @enderror
            <button type="submit" class="button">Check in anyway</button>
        </form>
    @endif
</div>
