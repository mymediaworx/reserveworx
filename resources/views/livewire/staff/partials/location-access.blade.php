<fieldset class="location-picks">
    <legend>Locations</legend>
    <label class="check">
        <input type="checkbox" wire:model.live="{{ $allModel }}">
        <span>All locations</span>
    </label>
    @unless ($all)
        @forelse ($locations as $location)
            <label class="check">
                <input type="checkbox" value="{{ $location->id }}" wire:model="{{ $idsModel }}">
                <span>{{ $location->name }}</span>
            </label>
        @empty
            <p class="muted">Add a location before you can limit someone.</p>
        @endforelse
    @endunless
    <p class="muted">All locations is the default. Clear it to limit bookings, inventory, returns, closures, the waitlist, and reports.</p>
</fieldset>
