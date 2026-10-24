<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>Sign in – ReserveWorx platform</title>
</head>
<body class="auth">
    <main class="auth-panel">
        <p class="auth-tenant">ReserveWorx</p>
        <h1>Platform sign in</h1>
        <form method="POST" action="/platform/login" class="stack">
            @csrf
            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            </label>
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            @error('email')
                <p class="error" role="alert">{{ $message }}</p>
            @enderror
            <button type="submit" class="button primary">Sign in</button>
        </form>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <p class="auth-foot"><a href="/platform/forgot-password">Forgot password</a></p>
    </main>
</body>
</html>
