<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>ReserveWorx</title>
</head>
<body class="auth">
    <main class="welcome-shell">
        <section class="auth-panel welcome-intro">
            <div class="auth-brand">
                <x-avatar name="Brand Worx" />
                <p class="auth-tenant">BrandWorx</p>
            </div>
            <h1>ReserveWorx</h1>
            <p>Bookings and rentals for marinas, tours, equipment, and the other industries below, with shared inventory that never double-books.</p>
            <p class="muted">Each operator works on their own address. Customers book through the operator’s booking link.</p>
            <p class="top-gap"><a class="button primary" href="/signup">Start a workspace</a></p>
            <p class="auth-foot"><a href="/platform/login">Platform sign in</a></p>
        </section>
        <section class="industry-board" aria-labelledby="industries-heading">
            <h2 id="industries-heading">Industries</h2>
            <ul class="industry-grid">
                @foreach (\App\Enums\Industry::cases() as $industry)
                    <li>
                        <a class="industry-card" href="/signup?industry={{ $industry->value }}">
                            <x-icon :name="$industry->icon()" size="22" />
                            <span>{{ $industry->label() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    </main>
</body>
</html>
