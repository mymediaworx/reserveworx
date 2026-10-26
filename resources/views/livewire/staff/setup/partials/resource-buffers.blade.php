<div class="buffer-times" data-buffers="{{ $type }}-{{ $resource->id }}">
    @if ($canManage)
        <label class="field">
            <span>Prep before {{ $resource->name }} (minutes)</span>
            <input type="number" min="0" max="1440" value="{{ $resource->prep_buffer_minutes }}" wire:change="setBuffer('{{ $type }}', '{{ $resource->id }}', 'prep', $event.target.value)">
        </label>
        <label class="field">
            <span>Return after {{ $resource->name }} (minutes)</span>
            <input type="number" min="0" max="1440" value="{{ $resource->return_buffer_minutes }}" wire:change="setBuffer('{{ $type }}', '{{ $resource->id }}', 'return', $event.target.value)">
        </label>
        <label class="field">
            <span>Turnaround for {{ $resource->name }} (minutes)</span>
            <input type="number" min="0" max="1440" value="{{ $resource->turnaround_minutes }}" wire:change="setBuffer('{{ $type }}', '{{ $resource->id }}', 'turnaround', $event.target.value)">
        </label>
        @error($type.'-'.$resource->id.'-prep')<p class="error">{{ $message }}</p>@enderror
        @error($type.'-'.$resource->id.'-return')<p class="error">{{ $message }}</p>@enderror
        @error($type.'-'.$resource->id.'-turnaround')<p class="error">{{ $message }}</p>@enderror
    @else
        <p class="muted">{{ $resource->prep_buffer_minutes }} min before a booking, {{ $resource->return_buffer_minutes }} min after, {{ $resource->turnaround_minutes }} min between bookings.</p>
    @endif
</div>
