@extends('layouts.app')

@section('title', __('auth.mfa_challenge.title').' · '.__('ce.app_title'))

@section('content')
<div class="shell">
    <section class="card-main">
        <h1>{{ __('auth.mfa_challenge.heading') }}</h1>

        <p>{{ __('auth.mfa_challenge.intro') }}</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('mfa.challenge.verify') }}">
            @csrf

            <label for="code">{{ __('auth.mfa_challenge.code') }}</label>

            <input
                id="code"
                name="code"
                type="text"
                autocomplete="one-time-code"
                maxlength="64"
                required
                autofocus
            >

            <button class="submit" type="submit">
                {{ __('auth.mfa_challenge.submit') }}
            </button>
        </form>

        <p class="note">
            {{ __('auth.mfa_challenge.operator') }}:
            <code>{{ $user->email }}</code>
        </p>
    </section>
</div>
@endsection
