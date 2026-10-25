@php
    use App\Enums\IncidentSeverity;
    use App\Models\Asset;
    use App\Support\Display;
@endphp
<div class="incident-form" data-file-incident="{{ $booking->id }}" @if ($fixedAsset instanceof Asset) data-fixed-asset="{{ $fixedAsset->id }}" @endif>
    @once
        <style>
            .incident-form { display: grid; gap: 0.75rem; min-width: 0; }
            .incident-form .button { width: 100%; }
            .incident-form .check { display: flex; gap: 0.5rem; align-items: flex-start; }
            .incident-form input[type="checkbox"] { width: auto; max-width: none; }
            .incident-reports { display: grid; gap: 0.35rem; margin: 0; padding: 0; list-style: none; }
            .incident-reports li { min-width: 0; }
            @media (max-width: 390px) {
                .incident-form,
                .incident-form * { overflow-wrap: anywhere; min-width: 0; max-width: 100%; }
                .incident-form input:not([type="checkbox"]):not([type="radio"]),
                .incident-form select,
                .incident-form textarea,
                .incident-form .button { width: 100%; max-width: 100%; }
            }
        </style>
    @endonce

    @if ($flash)
        <p class="flash ok" role="status">{{ $flash }}</p>
    @endif

    @if ($missingAsset)
        <p class="muted">That {{ $industry->unit() }} is no longer on this booking.</p>
    @elseif ($unassigned)
        <p class="muted">No {{ $industry->unit() }} is assigned to this booking yet.</p>
    @else
        <form wire:submit="file" class="stack">
            @if ($fixedAsset instanceof Asset)
                <p data-asset-name="{{ $fixedAsset->name }}"><strong>{{ $fixedAsset->name }}</strong></p>
            @else
                <label class="field">
                    <span>{{ ucfirst($industry->unit()) }}</span>
                    <select wire:model="assetId">
                        <option value="">Choose</option>
                        @foreach ($assets as $asset)
                            <option value="{{ $asset->id }}">{{ $asset->name }}</option>
                        @endforeach
                    </select>
                </label>
                @error('assetId')<p class="error">{{ $message }}</p>@enderror
            @endif

            <label class="field">
                <span>Severity</span>
                <select wire:model.live="severity">
                    <option value="">Choose</option>
                    @foreach (IncidentSeverity::cases() as $level)
                        <option value="{{ $level->value }}">{{ $level->label() }}</option>
                    @endforeach
                </select>
            </label>
            @error('severity')<p class="error">{{ $message }}</p>@enderror

            <label class="field">
                <span>Notes</span>
                <textarea wire:model="notes" rows="3" maxlength="2000" required></textarea>
            </label>
            @error('notes')<p class="error">{{ $message }}</p>@enderror

            <label class="field">
                <span>Photos</span>
                <input type="file" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp,image/gif">
            </label>
            <p class="muted small">Up to 6 photos. PNG, JPEG, WEBP, or GIF.</p>
            @error('photos')<p class="error">{{ $message }}</p>@enderror
            @foreach ($errors->getMessages() as $key => $photoErrors)
                @if (is_string($key) && str_starts_with($key, 'photos.'))
                    <p class="error">{{ $photoErrors[0] }}</p>
                @endif
            @endforeach

            <label class="field">
                <span>Damage amount</span>
                <input type="text" inputmode="decimal" wire:model="damageAmount" placeholder="Optional, like 25.50" autocomplete="off">
            </label>
            <p class="muted small">Added to the customer total, the same way a late fee is. It is not a booking fee, and no payment is taken here.</p>
            @error('damageAmount')<p class="error">{{ $message }}</p>@enderror

            @if ($severity === $serious)
                <label class="check">
                    <input type="checkbox" wire:model.live="blockAsset">
                    <span>Take this {{ $industry->unit() }} out for maintenance</span>
                </label>
                @if ($blockAsset)
                    <label class="field">
                        <span>Out from</span>
                        <input type="datetime-local" wire:model="blockStarts" required>
                    </label>
                    <label class="field">
                        <span>Back at</span>
                        <input type="datetime-local" wire:model="blockEnds" required>
                    </label>
                    @error('blockEnds')<p class="error">{{ $message }}</p>@enderror
                    <label class="field">
                        <span>Reason</span>
                        <input type="text" wire:model="blockReason" maxlength="160" required>
                    </label>
                    @error('blockReason')<p class="error">{{ $message }}</p>@enderror
                    <p class="muted small">This shows on the Maintenance page. Future bookings that overlap are flagged and stay booked.</p>
                @endif
            @endif

            <button type="submit" class="button primary" wire:loading.attr="disabled" wire:target="file,photos">File report</button>
        </form>
    @endif

    @if ($reports->isNotEmpty())
        <ul class="incident-reports">
            @foreach ($reports as $report)
                <li data-report="{{ $report->id }}">
                    <span>{{ $report->asset?->name ?? 'Boat' }}</span>
                    <span>{{ $report->severity?->label() ?? 'Recorded' }}</span>
                    <span>{{ $report->status->label() }}</span>
                    @if (is_int($report->damage_cents) && $report->damage_cents > 0)
                        <span>{{ Display::money($report->damage_cents, $currency) }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
