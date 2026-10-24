<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>Check your email – ReserveWorx</title>
</head>
<body class="auth">
    <main class="auth-panel">
        <div class="auth-brand">
            <x-avatar name="ReserveWorx" />
            <p class="auth-tenant">ReserveWorx</p>
        </div>
        <h1>Check your email</h1>
        <p role="status">{{ session('status', 'If this is a new workspace, we sent a verification link. It expires in 60 minutes.') }}</p>
        <p class="muted">The link opens your workspace. It stays in onboarding until the setup steps are finished.</p>
        <form method="POST" action="/signup/resend" class="stack">
            @csrf
            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email">
            </label>
            @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
            <button type="submit" class="button">Send the link again</button>
        </form>
        <p class="auth-foot"><a href="/signup">Start over</a></p>
    </main>
</body>
</html>
