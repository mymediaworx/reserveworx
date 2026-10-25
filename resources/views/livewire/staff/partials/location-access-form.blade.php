@php
    $chosen = collect($locations)->whereIn('id', $memberLocations[$person->id] ?? [])->pluck('name');
    $open = ($memberAll[$person->id] ?? true) === false;
@endphp
<form wire:submit="saveLocations('{{ $person->id }}')" class="stack">
    <fieldset class="location-picks">
        <legend class="sr-only">Locations for {{ $person->user?->name ?: $person->user?->email }}</legend>
        <label class="check">
            <input type="checkbox" wire:model.live="memberAll.{{ $person->id }}">
            <span>All locations</span>
        </label>
        @if ($open)
            @foreach ($locations as $location)
                <label class="check">
                    <input type="checkbox" value="{{ $location->id }}" wire:model="memberLocations.{{ $person->id }}">
                    <span>{{ $location->name }}</span>
                </label>
            @endforeach
        @endif
        <p class="muted">
            @if ($open)
                {{ $chosen->isEmpty() ? 'No locations selected.' : $chosen->join(', ') }}
            @else
                Every location
            @endif
        </p>
    </fieldset>
    <button type="submit" class="button">Save locations</button>
</form>
