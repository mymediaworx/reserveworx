<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>Reset password – ReserveWorx platform</title>
</head>
<body class="auth">
    <main class="auth-panel">
        <p class="auth-tenant">ReserveWorx</p>
        <h1>Reset password</h1>
        <p>Enter the email you use for the platform. If it can sign in here, we email a link that expires in 60 minutes.</p>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <form method="POST" action="/platform/forgot-password" class="stack">
            @csrf
            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            </label>
            @error('email')
                <p class="error" role="alert">{{ $message }}</p>
            @enderror
            <button type="submit" class="button primary">Email me a reset link</button>
        </form>
        <p class="auth-foot"><a href="/platform/login">Back to sign in</a></p>
    </main>
</body>
</html>
