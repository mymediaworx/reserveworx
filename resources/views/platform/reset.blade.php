<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>Choose a new password – ReserveWorx platform</title>
</head>
<body class="auth">
    <main class="auth-panel">
        <div class="auth-brand">
            <x-avatar name="Brand Worx" />
            <p class="auth-tenant">ReserveWorx</p>
        </div>
        <h1>Choose a new password</h1>
        <form method="POST" action="/platform/reset-password" class="stack">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email', $email) }}" autocomplete="username" required autofocus>
            </label>
            <label class="field">
                <span>New password</span>
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
            <label class="field">
                <span>Confirm password</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required>
            </label>
            @error('email')
                <p class="error" role="alert">{{ $message }}</p>
            @enderror
            @error('password')
                <p class="error" role="alert">{{ $message }}</p>
            @enderror
            <button type="submit" class="button primary">Save new password</button>
        </form>
        <p class="auth-foot"><a href="/platform/login">Back to sign in</a></p>
    </main>
</body>
</html>
