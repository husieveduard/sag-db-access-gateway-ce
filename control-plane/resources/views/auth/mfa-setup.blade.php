@extends('layouts.app')

@section('title', 'Налаштування MFA · SAG DB Access Gateway CE')

@push('styles')
<style>
    .shell {
        width: min(620px, 100%);
        margin: 6vh auto 0;
    }
    .card-main {
        padding: 28px;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 14px;
    }
    h1 { margin: 0; font-size: 25px; }
    p, li { color: var(--muted); line-height: 1.55; }
    .qr-box {
        display: flex;
        justify-content: center;
        margin: 24px 0;
        padding: 18px;
        background: #fff;
        border-radius: 12px;
    }
    .qr-box img {
        width: 280px;
        max-width: 100%;
        height: auto;
    }
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
        letter-spacing: .18em;
        font-size: 20px;
    }
    input:focus { border-color: var(--blue); }
    .submit {
        width: 100%;
        margin-top: 22px;
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
    .manual {
        margin-top: 16px;
        padding: 12px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: rgba(255,255,255,.02);
    }
    .manual code {
        display: block;
        margin-top: 8px;
        word-break: break-all;
        color: #d7e8ff;
    }
</style>
@endpush

@section('content')
<div class="shell">
    <section class="card-main">
        <h1>Налаштування MFA</h1>

        <p>
            Відскануйте QR-код у Microsoft Authenticator, Google Authenticator,
            1Password або іншому TOTP-сумісному застосунку.
        </p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <div class="qr-box">
            <img src="{{ $qrDataUri }}" alt="MFA QR code">
        </div>

        <div class="manual">
            Не вдається сканувати QR? Додайте ключ вручну:
            <code>{{ $secret }}</code>
            <p>Account: {{ $user->email }}</p>
        </div>

        <form method="POST" action="{{ route('mfa.setup.store') }}">
            @csrf

            <label for="code">Код із застосунку</label>

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
                Підтвердити та увімкнути MFA
            </button>
        </form>
    </section>
</div>
@endsection
