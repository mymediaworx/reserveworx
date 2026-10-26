@php
    $named = $booking->namedParticipants;
    $canName = in_array($booking->status->value, ['held', 'confirmed'], true);
    $room = $named->count() < $booking->participants;
    $partyOld = $partyOld ?? ['name' => '', 'is_minor' => false, 'guardian_name' => ''];
@endphp
<section class="party-people" data-party-size="{{ $booking->participants }}" data-party-named="{{ $named->count() }}">
    <h2>Who is coming</h2>
    <p>{{ $named->count() }} of {{ $booking->participants }} {{ $booking->participants === 1 ? 'person' : 'people' }} named. A child needs a parent or guardian. Naming someone does not change the price.</p>
    @if (! empty($partyError))
        <p class="error" role="alert" data-party-error>{{ $partyError }}</p>
    @endif
    @if ($named->isNotEmpty())
        <ul>
            @foreach ($named as $person)
                <li data-participant="{{ $person->id }}">
                    <span>{{ $person->name }}</span>
                    @if ($person->is_minor)
                        <span class="muted">Under 18, guardian {{ $person->guardian_name }}</span>
                    @endif
                    @if ($canName && isset($partyRemoveUrls[$person->id]))
                        <form method="post" action="{{ $partyRemoveUrls[$person->id] }}" class="party-remove">
                            <input type="hidden" name="return" value="{{ $partyReturn }}">
                            <button type="submit">Remove {{ $person->name }}</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    @if ($canName && $room && ! empty($partyAddUrl))
        <form method="post" action="{{ $partyAddUrl }}" class="party-add">
            <label>Name
                <input name="name" type="text" required maxlength="120" autocomplete="name" value="{{ $partyOld['name'] }}">
            </label>
            <fieldset>
                <label>
                    <input type="checkbox" name="is_minor" value="1" @checked($partyOld['is_minor'])>
                    This person is under 18
                </label>
            </fieldset>
            <label>Parent or guardian <span class="optional">(required for a child)</span>
                <input name="guardian_name" type="text" maxlength="120" autocomplete="name" value="{{ $partyOld['guardian_name'] }}">
            </label>
            <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="return" value="{{ $partyReturn }}">
            <button type="submit">Add this person</button>
        </form>
    @elseif ($canName && ! $room)
        <p data-party-full>Everyone in this party is named.</p>
    @endif
    <style>
        .party-people, .party-people * { min-width: 0; overflow-wrap: anywhere; }
        .party-people { margin: 0 0 1rem; }
        .party-remove { padding: .75rem; }
        @media (max-width: 390px) {
            .party-people form, .party-people button, .party-people input { max-width: 100%; }
        }
    </style>
</section>
