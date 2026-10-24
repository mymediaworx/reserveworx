<div class="page narrow">
    <header class="page-head">
        <div>
            <h1>Add a business</h1>
            <p class="lede">This uses the same setup as a new workspace. Booking fees stay at 3% for direct card payments and 1% for third-party bookings.</p>
        </div>
    </header>

    <section class="panel">
        <form method="post" action="/staff/businesses" class="stack">
            @csrf
            <label class="field">
                <span>Business name</span>
                <input type="text" name="name" value="{{ old('name') }}" maxlength="120" required autofocus>
            </label>
            @error('name')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label class="field">
                <span>Short address</span>
                <input type="text" name="slug" value="{{ old('slug') }}" maxlength="63" required pattern="[a-z0-9]+(?:-[a-z0-9]+)*" autocapitalize="off" spellcheck="false">
            </label>
            @error('slug')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label class="field">
                <span>Sign-in address (optional)</span>
                <input type="text" name="hostname" value="{{ old('hostname') }}" maxlength="253" placeholder="lakeside.example.com" autocapitalize="off" spellcheck="false">
            </label>
            @error('hostname')<p class="error" role="alert">{{ $message }}</p>@enderror
            <p class="muted small">The address is saved unverified. You can open the business from All businesses right away. Signing in there waits until the address is verified.</p>
            <button type="submit" class="button primary">Add business</button>
        </form>
    </section>
</div>
