<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>Sign in – {{ $tenant->name }}</title>
</head>
<body class="auth">
    <main class="auth-panel">
        <p class="auth-tenant">{{ $tenant->name }}</p>
        <h1>Team sign in</h1>
        <form method="POST" action="/staff/login" class="stack">
            @csrf
            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            </label>
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <label class="check">
                <input type="checkbox" name="remember" value="1">
                <span>Keep me signed in on this device</span>
            </label>
            @error('email')
                <p class="error" role="alert">{{ $message }}</p>
            @enderror
            <button type="submit" class="button primary">Sign in</button>
        </form>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <p class="auth-foot"><a href="/staff/forgot-password">Forgot password</a></p>
        <p class="auth-foot"><a href="/">Go to the booking page</a></p>
    </main>
</body>
</html>
