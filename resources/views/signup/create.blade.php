<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>Start a workspace – ReserveWorx</title>
</head>
<body class="auth">
    <main class="auth-panel wide">
        <div class="auth-brand">
            <x-avatar name="ReserveWorx" />
            <p class="auth-tenant">ReserveWorx</p>
        </div>
        <h1>Start a workspace</h1>
        <p class="muted">Your address will be a subdomain of {{ $apex }}. Booking fees stay at the standard rates. There is no subscription.</p>
        <form method="POST" action="/signup" class="stack">
            @csrf
            <div class="hp-field" aria-hidden="true">
                <label>Company website <input type="text" name="company_url" tabindex="-1" autocomplete="off" value=""></label>
            </div>
            <label class="field">
                <span>Business name</span>
                <input type="text" name="business_name" value="{{ old('business_name') }}" maxlength="120" required autofocus>
            </label>
            @error('business_name')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label class="field">
                <span>Industry</span>
                <select name="industry">
                    @include('partials.industry-options', ['selected' => old('industry', $selectedIndustry)])
                </select>
            </label>
            @error('industry')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label class="field">
                <span>Desired address</span>
                <input id="signup-slug" type="text" name="slug" value="{{ old('slug') }}" maxlength="63" required autocapitalize="off" spellcheck="false" autocomplete="off" pattern="[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*">
            </label>
            <p id="signup-host" class="muted small" role="status">{{ old('slug', 'name') }}.{{ $apex }}</p>
            @error('slug')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label class="field">
                <span>Your name</span>
                <input type="text" name="owner_name" value="{{ old('owner_name') }}" maxlength="120" required autocomplete="name">
            </label>
            @error('owner_name')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email">
            </label>
            @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" required minlength="12" autocomplete="new-password">
            </label>
            <label class="field">
                <span>Confirm password</span>
                <input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">
            </label>
            @error('password')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label class="check">
                <input type="checkbox" name="accepted_terms" value="1" @checked(old('accepted_terms'))>
                <span>{{ $terms }}</span>
            </label>
            @error('accepted_terms')<p class="error" role="alert">{{ $message }}</p>@enderror
            <button type="submit" class="button primary">Create workspace</button>
        </form>
        <p class="auth-foot"><a href="/">Back</a></p>
    </main>
    <script>
        (function () {
            var input = document.getElementById('signup-slug');
            var status = document.getElementById('signup-host');
            if (!input || !status) return;
            var apex = @json($apex);
            var timer = null;
            var messages = {
                available: ' is available.',
                taken: ' is already taken.',
                reserved: ' is reserved.',
                invalid: 'Use 3 to 63 characters: lowercase letters, numbers, and hyphens.'
            };
            input.addEventListener('input', function () {
                window.clearTimeout(timer);
                var slug = input.value.trim().toLowerCase();
                status.textContent = slug === '' ? 'name.' + apex : slug + '.' + apex;
                if (slug === '') return;
                timer = window.setTimeout(function () {
                    fetch('/signup/availability?slug=' + encodeURIComponent(slug), { headers: { 'Accept': 'application/json' } })
                        .then(function (response) { return response.ok ? response.json() : null; })
                        .then(function (body) {
                            if (!body || input.value.trim().toLowerCase() !== slug) return;
                            if (body.status === 'invalid') {
                                status.textContent = messages.invalid;
                                return;
                            }
                            var host = body.hostname || (slug + '.' + apex);
                            status.textContent = host + (messages[body.status] || '');
                        })
                        .catch(function () {});
                }, 300);
            });
        }());
    </script>
</body>
</html>
