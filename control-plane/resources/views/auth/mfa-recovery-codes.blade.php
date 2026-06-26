@extends('layouts.app')

@section('title', __('auth.mfa_recovery.title').' · '.__('ce.app_title'))

@section('content')
<div class="shell">
    <section class="card-main">
        <h1>{{ __('auth.mfa_recovery.heading') }}</h1>

        <div class="warning">
            {{ __('auth.mfa_recovery.warning') }}
        </div>

        <div class="codes">
            @foreach ($codes as $code)
                <div class="code">{{ $code }}</div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('mfa.recovery.acknowledge') }}">
            @csrf

            <button class="submit" type="submit">
                {{ __('auth.mfa_recovery.submit') }}
            </button>
        </form>
    </section>
</div>
@endsection
