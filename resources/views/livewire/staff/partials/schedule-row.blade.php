<div class="schedule-label" style="--lanes: {{ $row['lanes'] }}" data-resource="{{ $row['key'] }}" data-flag="{{ $row['flag'] ?? '' }}">
    <span>{{ $row['name'] }}</span>
    @if ($row['detail'] !== '')
        <small>{{ $row['detail'] }}</small>
    @endif
</div>
<div class="schedule-lane" style="--days: {{ count($days) }}; --lanes: {{ $row['lanes'] }}">
    @foreach ($days as $day)
        <div @class(['schedule-daylane', 'is-today' => $day['today']]) data-date="{{ $day['date'] }}" data-minutes="{{ $day['minutes'] }}">
            @foreach ($row['shades'][$day['date']] ?? [] as $shade)
                <div
                    class="schedule-closure"
                    style="left: {{ $shade['left'] }}%; width: {{ $shade['width'] }}%"
                    data-closure="{{ $shade['id'] }}"
                    data-resource="{{ $row['key'] }}"
                    data-reason="{{ $shade['reason'] }}"
                    data-left="{{ $shade['left'] }}"
                    data-width="{{ $shade['width'] }}"
                    title="Closed: {{ $shade['reason'] }}"
                    role="img"
                    aria-label="Closed, {{ $shade['reason'] }}"
                ></div>
            @endforeach
            @if (($canBook ?? false) && ! str_starts_with($row['key'], 'location:'))
                @if ($view === 'day')
                    @foreach ($day['hours'] as $hour)
                        <button
                            type="button"
                            class="schedule-slot"
                            wire:click="openSlot('{{ $row['key'] }}', '{{ $day['date'] }}', '{{ $hour['start'] }}')"
                            style="left: {{ $hour['left'] }}%; width: {{ $hour['width'] }}%"
                            data-resource="{{ $row['key'] }}"
                            data-date="{{ $day['date'] }}"
                            data-start="{{ $hour['start'] }}"
                            aria-label="New booking, {{ $row['name'] }}, {{ $day['date'] }} {{ $hour['label'] }}"
                        ></button>
                    @endforeach
                @else
                    <button
                        type="button"
                        class="schedule-slot"
                        wire:click="openSlot('{{ $row['key'] }}', '{{ $day['date'] }}', '09:00')"
                        style="left: 0%; width: 100%"
                        data-resource="{{ $row['key'] }}"
                        data-date="{{ $day['date'] }}"
                        data-start="09:00"
                        aria-label="New booking, {{ $row['name'] }}, {{ $day['date'] }}"
                    ></button>
                @endif
            @endif
            @foreach ($row['cells'][$day['date']]['blocks'] as $block)
                <button
                    type="button"
                    class="schedule-block {{ $block['kind'] === 'hold' ? 'is-hold' : 'is-booking' }} {{ $block['status'] === 'no_show' ? 'is-quiet' : '' }} {{ $selected === $block['booking_id'] ? 'is-selected' : '' }}"
                    wire:click="openMove('{{ $block['booking_id'] }}', '{{ $block['row'] }}')"
                    style="left: {{ $block['left'] }}%; width: {{ $block['width'] }}%; --lane: {{ $block['lane'] }}"
                    aria-pressed="{{ $selected === $block['booking_id'] ? 'true' : 'false' }}"
                    aria-controls="move-panel"
                    data-booking="{{ $block['booking_id'] }}"
                    data-row="{{ $block['row'] }}"
                    data-kind="{{ $block['kind'] }}"
                    data-day="{{ $block['day'] }}"
                    data-local-start="{{ $block['local_start'] }}"
                    data-local-end="{{ $block['local_end'] }}"
                    data-occupied-start="{{ $block['occupied_start'] }}"
                    data-occupied-end="{{ $block['occupied_end'] }}"
                    data-left="{{ $block['left'] }}"
                    data-width="{{ $block['width'] }}"
                    data-buffer-before="{{ $block['buffer_before'] }}"
                    data-buffer-after="{{ $block['buffer_after'] }}"
                    title="{{ $block['title'] }}"
                    aria-label="{{ $block['title'] }}"
                >
                    @if ($block['buffer_before'] !== '0.000')
                        <span class="schedule-buffer" style="width: {{ $block['buffer_before'] }}%" aria-hidden="true"></span>
                    @endif
                    <span class="schedule-core">
                        <span class="schedule-who">{{ $block['who'] }}</span>
                        <span class="schedule-when">{{ $block['time'] }}</span>
                    </span>
                    @if ($block['buffer_after'] !== '0.000')
                        <span class="schedule-buffer is-tail" style="width: {{ $block['buffer_after'] }}%" aria-hidden="true"></span>
                    @endif
                </button>
            @endforeach
        </div>
    @endforeach
</div>
