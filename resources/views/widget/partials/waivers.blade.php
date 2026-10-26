<section class="checkout-waivers" data-waivers="required">
    <h2>Sign your waiver</h2>
    <p>Read the waiver, then sign, before you pay. Signing does not change the price.</p>
    @if (! empty($waiverError))
        <p class="error" role="alert" data-waiver-error>{{ $waiverError }}</p>
    @endif
    @foreach ($waiverVersions as $waiver)
        <h3>{{ $waiver->name }} <span class="optional">version {{ $waiver->version }}</span></h3>
        <div class="waiver-text" tabindex="0" aria-label="{{ $waiver->name }} text">{{ $waiver->body }}</div>
    @endforeach
    <form method="post" action="{{ $waiverSignUrl }}" data-waiver-sign>
        @foreach ($waiverPeople as $person)
            @php $p = $person['participant']; $key = $person['key']; @endphp
            <fieldset class="person" data-signer="{{ $key }}">
                @if ($p)
                    <legend>{{ $p->name }}{{ $p->is_minor ? ' (under 18)' : '' }}</legend>
                    @if ($p->is_minor && ! (is_string($p->guardian_name) && trim($p->guardian_name) !== ''))
                        <label>Parent or guardian's full name
                            <input name="guardian[{{ $key }}]" type="text" maxlength="120" required autocomplete="name" value="{{ $waiverOld['guardian'][$key] ?? '' }}">
                        </label>
                    @elseif ($p->is_minor)
                        <p class="optional">Signed by {{ $p->guardian_name }} as parent or guardian.</p>
                    @endif
                @else
                    <legend>Person taking part</legend>
                    <label>Full name
                        <input name="signer_name" type="text" maxlength="120" required autocomplete="name" value="{{ $waiverOld['signer_name'] }}">
                    </label>
                    <label class="agree">
                        <input type="checkbox" name="signer_minor" value="1" @checked($waiverOld['signer_minor'])>
                        <span>This person is under 18</span>
                    </label>
                    <label>Parent or guardian's full name <span class="optional">(only if under 18)</span>
                        <input name="guardian[{{ $key }}]" type="text" maxlength="120" autocomplete="name" value="{{ $waiverOld['guardian'][$key] ?? '' }}">
                    </label>
                @endif
                <label class="agree">
                    <input type="checkbox" name="agree[{{ $key }}]" value="1" @checked(($waiverOld['agree'][$key] ?? '') !== '') required>
                    <span>I have read {{ $waiverVersions->count() === 1 ? 'this waiver' : 'these waivers' }} and agree{{ $p ? ' for '.$p->name : '' }}.</span>
                </label>
            </fieldset>
        @endforeach
        <button type="submit">Sign waiver</button>
    </form>
    <p class="optional">Your name, the time, your IP address, and your browser are stored with each signature.</p>
    <style>
        .checkout-waivers, .checkout-waivers * { min-width: 0; overflow-wrap: anywhere; }
        .checkout-waivers { margin: 0 0 1rem; }
        .waiver-text { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 1rem 1.1rem; max-height: 16rem; overflow-y: auto; white-space: pre-wrap; overflow-wrap: anywhere; margin: 0 0 .75rem; }
        .person { border: 1px solid var(--line); border-radius: 12px; padding: .9rem; display: grid; gap: .6rem; margin: 0 0 .75rem; }
        .person legend { font-weight: 600; color: var(--ink); }
        .agree { display: flex; gap: .6rem; align-items: flex-start; font-weight: 500; color: var(--ink); }
        .agree input { width: 1.2rem; height: 1.2rem; min-height: 0; margin-top: .15rem; flex: none; accent-color: var(--brand); }
        @media (max-width: 390px) {
            .checkout-waivers form, .checkout-waivers button, .checkout-waivers input, .checkout-waivers .waiver-text { max-width: 100%; }
        }
    </style>
</section>
