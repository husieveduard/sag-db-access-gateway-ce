@extends('layouts.app')

@section('title', 'MFA-підтвердження · SAG DB Access Gateway CE')

@push('styles')
<style>
    .shell {
        width: min(430px, 100%);
        margin: 12vh auto 0;
    }
    .card-main {
        padding: 30px;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 14px;
    }
    h1 { margin: 0; font-size: 25px; }
    p { color: var(--muted); line-height: 1.5; }
    label {
        display: block;
        margin: 18px 0 7px;
        color: #c7d2e4;
        font-size: 13px;
        font-weight: 700;
    }
    input {
        width: 100%;
        padding: 12px;
        color: var(--text);
        background: #0d1726;
        border: 1px solid var(--line);
        border-radius: 8px;
        outline: none;
        text-align: center;
        font-size: 20px;
        letter-spacing: .12em;
    }
    input:focus { border-color: var(--blue); }
    .submit {
        width: 100%;
        margin-top: 24px;
        padding: 12px;
        border: 0;
        border-radius: 8px;
        color: #06111d;
        background: var(--blue);
        font-weight: 800;
        cursor: pointer;
    }
    .error {
        margin-top: 14px;
        padding: 10px 12px;
        border-radius: 8px;
        color: #ffc0c0;
        background: rgba(243,107,107,.12);
        border: 1px solid rgba(243,107,107,.24);
        font-size: 13px;
    }
    .note {
        margin-top: 20px;
        font-size: 12px;
    }
</style>
@endpush

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
