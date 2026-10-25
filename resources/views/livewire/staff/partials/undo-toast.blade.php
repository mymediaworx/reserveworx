@if (is_string($undoActionId ?? null) && $undoActionId !== '')
    <div
        class="undo-toast"
        role="status"
        aria-live="polite"
        x-data="{
            init() {
                const expires = Date.parse(@js($undoExpiresAt));
                const wait = Number.isNaN(expires) ? 10000 : Math.max(0, expires - Date.now());
                setTimeout(() => $wire.dismissUndo(@js($undoActionId)), wait);
            }
        }"
    >
        <p>{{ $undoMessage }}</p>
        <button type="button" class="button" wire:click="undoAction('{{ $undoActionId }}')">Undo</button>
    </div>
    <style>
        @media (max-width: 390px) {
            .undo-toast { display: grid; width: auto; min-width: 0; }
            .undo-toast p { min-width: 0; overflow-wrap: anywhere; }
            .undo-toast .button { width: 100%; min-width: 0; }
        }
    </style>
@endif
