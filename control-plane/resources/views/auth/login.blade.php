@extends('layouts.app')

@section('title', __('auth.login.title').' · '.__('ce.app_title'))

@section('content')
<div class="login-shell">
    <section class="login-card">
        <h1>{{ __('ce.app_title') }}</h1>

        <p>{{ __('auth.login.description') }}</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf

            <label for="email">{{ __('auth.login.email') }}</label>
            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email') }}"
                autocomplete="username"
                required
                autofocus
            >

            <label for="password">{{ __('auth.login.password') }}</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <button class="submit" type="submit">
                {{ __('auth.login.submit') }}
            </button>
        </form>

        <p class="note">
            {{ __('auth.login.note_before') }} <code>admin</code>.
        </p>
    </section>
</div>
@endsection
