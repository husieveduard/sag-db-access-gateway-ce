@extends('layouts.app')

@section('title', __('auth.mfa_setup.title').' · '.__('ce.app_title'))

@section('content')
<div class="shell">
    <section class="card-main">
        <h1>{{ __('auth.mfa_setup.heading') }}</h1>

        <p>{{ __('auth.mfa_setup.intro') }}</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <div class="qr-box">
            <img src="{{ $qrDataUri }}" alt="{{ __('auth.mfa_setup.qr_alt') }}">
        </div>

        <div class="manual">
            {{ __('auth.mfa_setup.manual_intro') }}
            <code>{{ $secret }}</code>

            <p>{{ __('auth.mfa_setup.account', ['email' => $user->email]) }}</p>
        </div>

        <form method="POST" action="{{ route('mfa.setup.store') }}">
            @csrf

            <label for="code">{{ __('auth.mfa_setup.code') }}</label>

            <input
                id="code"
                name="code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                pattern="\d{6}"
                required
                autofocus
            >

            <button class="submit" type="submit">
                {{ __('auth.mfa_setup.submit') }}
            </button>
        </form>
    </section>
</div>
@endsection
