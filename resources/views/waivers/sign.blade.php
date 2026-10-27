@php use App\Support\Display; @endphp
@extends('widget.layout')

@section('title', 'Sign your waiver')

@section('content')
    <style>
        .waiver-text { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 1rem 1.1rem; max-height: 22rem; overflow-y: auto; white-space: pre-wrap; overflow-wrap: anywhere; margin: 0 0 .75rem; }
        .done { background: #fff; border: 1px solid var(--line); border-radius: 16px; box-shadow: var(--shadow); padding: 1.25rem; }
        .person { border: 1px solid var(--line); border-radius: 12px; padding: .9rem; display: grid; gap: .6rem; }
        .person legend { font-weight: 600; color: var(--ink); }
        .agree { display: flex; gap: .6rem; align-items: flex-start; font-weight: 500; color: var(--ink); }
        .agree input { width: 1.2rem; height: 1.2rem; min-height: 0; margin-top: .15rem; flex: none; accent-color: var(--brand); }
        .ok { background: var(--ok-surface); color: var(--ok); border-radius: 10px; padding: .75rem 1rem; }
        p[data-when] { max-width: 100%; overflow-wrap: anywhere; }
        @media (max-width: 390px) {
            p[data-when] { max-width: 100%; min-width: 0; overflow-wrap: anywhere; }
        }
    </style>

    <h1>Sign your waiver</h1>
    <p>{{ $businessName }} · {{ $booking->service?->name }}</p>
    <p data-when>{{ Display::rangeWithZone($booking->starts_at, $booking->ends_at, \App\Support\DisplayZone::forLocation($booking->location)) }}</p>

    @if (session('status'))
        <p class="ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($waivers->isEmpty())
        <div class="done"><p>There is nothing to sign for this booking.</p></div>
    @elseif ($people === [])
        <div class="done" data-waivers="complete"><p>All waivers for this booking are signed. You are ready for check-in.</p></div>
    @else
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif

        @foreach ($waivers as $waiver)
            <h2>{{ $waiver->name }} <span class="optional">version {{ $waiver->version }}</span></h2>
            <div class="waiver-text" tabindex="0" aria-label="{{ $waiver->name }} text">{{ $waiver->body }}</div>
        @endforeach

        <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf
            @foreach ($people as $person)
                @php $p = $person['participant']; $key = $person['key']; @endphp
                <fieldset class="person" data-signer="{{ $key }}">
                    @if ($p)
                        <legend>{{ $p->name }}{{ $p->is_minor ? ' (under 18)' : '' }}</legend>
                        @if ($p->is_minor && ! (is_string($p->guardian_name) && trim($p->guardian_name) !== ''))
                            <label>Parent or guardian's full name
                                <input name="guardian[{{ $key }}]" type="text" maxlength="120" required value="{{ old('guardian.'.$key) }}">
                            </label>
                        @elseif ($p->is_minor)
                            <p class="optional">Signed by {{ $p->guardian_name }} as parent or guardian.</p>
                        @endif
                    @else
                        <legend>Person taking part</legend>
                        <label>Full name
                            <input name="signer_name" type="text" maxlength="120" required autocomplete="name" value="{{ old('signer_name') }}">
                        </label>
                        <label class="agree">
                            <input type="checkbox" name="signer_minor" value="1" @checked(old('signer_minor'))>
                            <span>This person is under 18</span>
                        </label>
                        <label>Parent or guardian's full name <span class="optional">(only if under 18)</span>
                            <input name="guardian[{{ $key }}]" type="text" maxlength="120" value="{{ old('guardian.'.$key) }}">
                        </label>
                    @endif
                    <label class="agree">
                        <input type="checkbox" name="agree[{{ $key }}]" value="1" required>
                        <span>I have read {{ $waivers->count() === 1 ? 'this waiver' : 'these waivers' }} and agree{{ $p ? ' for '.$p->name : '' }}.</span>
                    </label>
                </fieldset>
            @endforeach
            <button type="submit">Sign</button>
        </form>
        <p class="optional">Your name, the time, your IP address, and your browser are stored with each signature.</p>
    @endif
@endsection
