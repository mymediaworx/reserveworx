@extends('admin.layout')

@section('title', 'Staff sign in')

@section('content')
    <h1>Staff sign in</h1>
    <p class="muted">This page is for people who work at this business.</p>
    @if ($errors->any())
        <p class="error">{{ $errors->first() }}</p>
    @endif
    <form method="post" action="{{ route('admin.login.store') }}">
        @csrf
        <label>
            Email
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required>
        </label>
        <label>
            Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">Sign in</button>
    </form>
    <p><a href="/staff/forgot-password">Forgot password?</a></p>
@endsection
