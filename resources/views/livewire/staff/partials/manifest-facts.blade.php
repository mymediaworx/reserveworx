@php use App\Support\Display; @endphp
<dl class="manifest-facts">
    <div>
        <dt>Resource</dt>
        <dd>{{ $booking->resource }}</dd>
    </div>
    <div>
        <dt>Guide</dt>
        <dd>{{ $booking->guideName !== '' ? $booking->guideName : 'No guide' }}</dd>
    </div>
    <div>
        <dt>Customer</dt>
        <dd>
            {{ $booking->customerName }}
            @if ($booking->customerPhone !== '')
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $booking->customerPhone) }}">{{ $booking->customerPhone }}</a>
            @endif
        </dd>
    </div>
    <div>
        <dt>Party</dt>
        <dd>
            {{ $booking->partySize }}
            @if ($booking->participantSummary() !== '')
                · {{ $booking->participantSummary() }}
            @endif
        </dd>
    </div>
    <div>
        <dt>Waivers</dt>
        <dd>{{ $booking->waiverSummary() }}</dd>
    </div>
    <div>
        <dt>Add-ons</dt>
        <dd>{{ $booking->addOns !== '' ? $booking->addOns : '—' }}</dd>
    </div>
    <div>
        <dt>Balance</dt>
        <dd>
            @if ($booking->balanceCents === null)
                No price yet
            @else
                {{ Display::money($booking->balanceCents, $booking->currency) }}
            @endif
        </dd>
    </div>
    <div>
        <dt>Check-in</dt>
        <dd>{{ $booking->checkIn }}</dd>
    </div>
    <div>
        <dt>Return due</dt>
        <dd>{{ $booking->startsAt->timezone($booking->timezone)->isSameDay($booking->returnDue->timezone($booking->timezone)) ? Display::time($booking->returnDue, $booking->timezone) : Display::dateTime($booking->returnDue, $booking->timezone) }}</dd>
    </div>
    <div>
        <dt>Staff notes</dt>
        <dd>
            {{ $booking->staffNotes ?? '—' }}
            @if ($canManage)
                <button type="button" class="link-button no-print" wire:click="editNote('{{ $booking->id }}')">Edit note</button>
            @endif
        </dd>
    </div>
</dl>
<a class="button" href="/staff/bookings/{{ $booking->id }}" wire:navigate>Open booking</a>
