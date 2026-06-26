@extends('layouts.app')

@section('title', 'MFA-підтвердження · SAG DB Access Gateway CE')

@section('content')
<div class="shell">
    <section class="card-main">
        <h1>MFA-підтвердження</h1>

        <p>
            Введіть 6-значний TOTP-код або один із recovery codes.
        </p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('mfa.challenge.verify') }}">
            @csrf

            <label for="code">MFA / recovery code</label>

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
                Підтвердити вхід
            </button>
        </form>

        <p class="note">
            Operator: <code>{{ $user->email }}</code>
        </p>
    </section>
</div>
@endsection
