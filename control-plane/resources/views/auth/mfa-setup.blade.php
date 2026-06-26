@extends('layouts.app')

@section('title', 'Налаштування MFA · SAG DB Access Gateway CE')

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
