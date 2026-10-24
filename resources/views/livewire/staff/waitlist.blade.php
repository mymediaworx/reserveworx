@php use App\Enums\WaitlistStatus; @endphp
<div class="page waitlist-page">
    <style>
        @media (max-width: 390px) {
            .waitlist-page { min-width: 0; }
            .waitlist-page .waitlist-card,
            .waitlist-page .waitlist-card * { min-width: 0; overflow-wrap: anywhere; }
            .waitlist-page .waitlist-actions { display: grid; }
            .waitlist-page .waitlist-actions .button { width: 100%; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Waitlist</h1>
            <p class="lede">People waiting for a full time.</p>
        </div>
    </header>

    @if ($flash)
        <p class="flash ok" role="status">{{ $flash }}</p>
    @endif

    <form class="filters" wire:submit.prevent>
        <label class="field compact">
            <span>Date</span>
            <input type="date" wire:model.live="date">
        </label>
        <label class="field compact">
            <span>Service</span>
            <select wire:model.live="service">
                <option value="">Any service</option>
                @foreach ($services as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>
    </form>

    @if ($entries->isEmpty())
        <p class="empty">No one is waiting on this date.</p>
    @else
        <div class="stack">
            @foreach ($entries as $entry)
                <article class="panel waitlist-card">
                    <div class="panel-head">
                        <h2>{{ $entry->customer_name ?: 'Guest' }}</h2>
                        <p class="muted">{{ $this->statusLabel($entry) }}</p>
                    </div>
                    <p>{{ $entry->service?->name }} · {{ $this->whenLabel($entry) }}</p>
                    <p class="muted">{{ $entry->participants }} {{ $entry->participants === 1 ? 'person' : 'people' }}
                        @if ($entry->customer_email) · {{ $entry->customer_email }}@endif
                        @if ($entry->customer_phone) · {{ $entry->customer_phone }}@endif
                    </p>
                    @if ($canManage && $entry->status === WaitlistStatus::Waiting)
                        <div class="waitlist-actions form-row">
                            <button type="button" class="button" wire:click="promote('{{ $entry->id }}')">Promote</button>
                            <button type="button" class="button danger" wire:click="cancel('{{ $entry->id }}')" wire:confirm="Remove this person from the waitlist?">Cancel</button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
