<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>Invitation unavailable – {{ $tenant->name }}</title>
</head>
<body class="auth">
    <main class="auth-panel">
        <div class="auth-brand">
            <x-avatar :name="$workspaceBranding->displayName !== '' ? $workspaceBranding->displayName : $tenant->name" :logo="$workspaceBranding->logoUrl" />
            <p class="auth-tenant">{{ $workspaceBranding->displayName !== '' ? $workspaceBranding->displayName : $tenant->name }}</p>
        </div>
        <h1>Invitation unavailable</h1>
        <p>This invitation is no longer valid. Ask the workspace owner to send a new one.</p>
        <p class="auth-foot"><a href="/staff/login">Go to sign in</a></p>
    </main>
</body>
</html>
