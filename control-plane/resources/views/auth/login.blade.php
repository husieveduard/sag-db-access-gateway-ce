@extends('layouts.app')

@section('title', 'Вхід · SAG DB Access Gateway CE')

@push('styles')
<style>
    .login-shell {
        width: min(430px, 100%);
        margin: 10vh auto 0;
    }
    .login-card {
        padding: 30px;
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 14px;
    }
    .login-card h1 { margin: 0; font-size: 25px; }
    .login-card p { color: var(--muted); line-height: 1.5; }
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
    .note { margin-top: 22px; font-size: 12px; }
</style>
@endpush

@section('content')
<div class="login-shell">
    <section class="login-card">
        <h1>SAG DB Access Gateway CE</h1>
        <p>Локальний адміністративний доступ до DB resources, gateway sessions та SQL audit.</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf

            <label for="email">Email</label>
            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email') }}"
                autocomplete="username"
                required
                autofocus
            >

            <label for="password">Пароль</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <button class="submit" type="submit">Увійти до консолі</button>
        </form>

        <p class="note">
            Доступ дозволений лише активним користувачам із роллю <code>admin</code>.
        </p>
    </section>
</div>
@endsection
