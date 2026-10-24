<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>Join {{ $tenant->name }} – ReserveWorx</title>
</head>
<body class="auth">
    <main class="auth-panel">
        <div class="auth-brand">
            <x-avatar :name="$workspaceBranding->displayName !== '' ? $workspaceBranding->displayName : $tenant->name" :logo="$workspaceBranding->logoUrl" />
            <p class="auth-tenant">{{ $workspaceBranding->displayName !== '' ? $workspaceBranding->displayName : $tenant->name }}</p>
        </div>
        <h1>Join the team</h1>
        <p>{{ $membership->user?->email }} is invited as {{ $roleName }}.</p>
        @if ($blocked)
            <p class="error" role="alert">Sign out of the other account before accepting this invitation.</p>
            <form method="POST" action="/staff/logout" class="stack">
                @csrf
                <button type="submit" class="button">Sign out</button>
            </form>
        @elseif ($needsPassword)
            <form method="POST" action="{{ request()->fullUrl() }}" class="stack">
                @csrf
                <label class="field">
                    <span>Your name</span>
                    <input type="text" name="name" value="{{ old('name', $membership->user?->name) }}" maxlength="120" autocomplete="name" required autofocus>
                </label>
                <label class="field">
                    <span>Password</span>
                    <input type="password" name="password" autocomplete="new-password" required>
                </label>
                <label class="field">
                    <span>Confirm password</span>
                    <input type="password" name="password_confirmation" autocomplete="new-password" required>
                </label>
                @error('name')<p class="error" role="alert">{{ $message }}</p>@enderror
                @error('password')<p class="error" role="alert">{{ $message }}</p>@enderror
                @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
                <button type="submit" class="button primary">Create account and join</button>
            </form>
        @else
            <form method="POST" action="{{ request()->fullUrl() }}" class="stack">
                @csrf
                @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
                <button type="submit" class="button primary">Join {{ $tenant->name }}</button>
            </form>
            <p class="auth-foot">Use the password you already have. This page does not change it.</p>
        @endif
    </main>
</body>
</html>
