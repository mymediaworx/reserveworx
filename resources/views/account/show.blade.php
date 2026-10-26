@extends('widget.layout')
@section('title', 'Account')

@section('content')
    <div class="account-page">
        <style>
            .account-page, .account-page * { min-width: 0; }
            .account-page h1, .account-page p, .account-page li, .account-page dt, .account-page dd { overflow-wrap: anywhere; }
            .account-page form, .account-page button, .account-page input { max-width: 100%; }
            .account-page ul { margin: 0 0 1rem; padding: 0; list-style: none; display: grid; gap: .75rem; }
            .account-page li, .account-page .receipt-summary { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 1rem 1.1rem; }
            .account-page .row-actions { display: flex; justify-content: space-between; gap: .75rem; align-items: center; }
        </style>
        <h1>Account</h1>
        @if (session('status'))
            <p class="notice" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif

        @if ($account)
            <div class="row-actions">
                <p>Signed in as {{ $account->email }}.</p>
                <form method="post" action="{{ url('/account/logout') }}">
                    @csrf
                    <button type="submit" class="secondary">Sign out</button>
                </form>
            </div>
            <p class="muted">You can still book without using this account. These are the bookings for this email at this business.</p>

            <h2>Upcoming</h2>
            @if ($upcoming === [])
                <p>No upcoming bookings.</p>
            @else
                <ul>
                    @foreach ($upcoming as $row)
                        <li data-booking="{{ $row['id'] }}" data-when="upcoming">
                            <strong>{{ $row['reference'] }}</strong>
                            <span class="optional">{{ $row['id'] }}</span>
                            <div>{{ $row['service'] }}</div>
                            <div>{{ $row['when'] }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <h2>Past</h2>
            @if ($past === [])
                <p>No past bookings.</p>
            @else
                <ul>
                    @foreach ($past as $row)
                        <li data-booking="{{ $row['id'] }}" data-when="past">
                            <strong>{{ $row['reference'] }}</strong>
                            <span class="optional">{{ $row['id'] }}</span>
                            <div>{{ $row['service'] }}</div>
                            <div>{{ $row['when'] }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <h2>Receipts</h2>
            @php $receipts = array_merge($upcoming, $past); @endphp
            @if ($receipts === [])
                <p>No receipts.</p>
            @else
                @foreach ($receipts as $row)
                    <section class="receipt-wrap" data-receipt="{{ $row['id'] }}">
                        <h3>{{ $row['reference'] }} · {{ $row['total'] }}</h3>
                        @if ($row['receipt'] !== '')
                            {!! $row['receipt'] !!}
                        @endif
                    </section>
                @endforeach
            @endif

            <h2>Password</h2>
            <p class="muted">Optional. It works on this business only, and only because this email is already verified.</p>
            <form method="post" action="{{ url('/account/password') }}">
                @csrf
                <label>New password
                    <input name="password" type="password" required minlength="12" maxlength="255" autocomplete="new-password">
                </label>
                <label>Confirm password
                    <input name="password_confirmation" type="password" required minlength="12" maxlength="255" autocomplete="new-password">
                </label>
                <button type="submit">Save password</button>
            </form>
        @else
            <p>Booking does not require an account. An account keeps your bookings and receipts for this business.</p>
            <h2>Email me a sign-in link</h2>
            <form method="post" action="{{ url('/account') }}">
                @csrf
                <label>Email
                    <input name="email" type="email" required maxlength="255" autocomplete="email" value="{{ old('email') }}">
                </label>
                <label>Your name <span class="optional">(optional)</span>
                    <input name="name" type="text" maxlength="120" autocomplete="name" value="{{ old('name') }}">
                </label>
                <label>Password <span class="optional">(optional, at least 12 characters, works after the link)</span>
                    <input name="password" type="password" maxlength="255" autocomplete="new-password">
                </label>
                <label>Confirm password <span class="optional">(only if you set one)</span>
                    <input name="password_confirmation" type="password" maxlength="255" autocomplete="new-password">
                </label>
                <button type="submit">Email me a link</button>
            </form>

            <h2>Sign in with a password</h2>
            <form method="post" action="{{ url('/account/login') }}">
                @csrf
                <label>Email
                    <input name="email" type="email" required maxlength="255" autocomplete="username" value="{{ old('email') }}">
                </label>
                <label>Password
                    <input name="password" type="password" required maxlength="255" autocomplete="current-password">
                </label>
                <button type="submit">Sign in</button>
            </form>
        @endif
    </div>
@endsection
