@extends('layouts.app')

@section('title', 'Вхід · SAG DB Access Gateway CE')

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
