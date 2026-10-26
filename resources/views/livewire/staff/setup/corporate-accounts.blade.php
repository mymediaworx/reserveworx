<div class="page narrow">
    <header class="page-head">
        <h1>Corporate accounts</h1>
        <p class="lede">A named account can have its own rate. Guests still see the public group price.</p>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>

    @if ($accounts->isEmpty())
        <p class="empty">No corporate accounts yet.</p>
    @else
        <ul class="rows">
            @foreach ($accounts as $account)
                <li class="row">
                    <span class="grow">
                        <strong>{{ $account->name }}</strong>
                        @unless ($account->active)
                            <span class="muted">Off</span>
                        @endunless
                    </span>
                    @if ($canManage)
                        <button type="button" class="button" wire:click="edit('{{ $account->id }}')">Rename</button>
                        @if ($account->active)
                            <button type="button" class="button" wire:click="deactivate('{{ $account->id }}')">Turn off</button>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canManage)
        <form wire:submit="{{ $editingId === '' ? 'create' : 'save' }}" class="form-row top-gap">
            <label class="field grow">
                <span>Name</span>
                <input type="text" wire:model="name" maxlength="120" required>
            </label>
            <button type="submit" class="button">{{ $editingId === '' ? 'Add account' : 'Save name' }}</button>
        </form>
        @error('name')<p class="error">{{ $message }}</p>@enderror
    @endif
</div>
