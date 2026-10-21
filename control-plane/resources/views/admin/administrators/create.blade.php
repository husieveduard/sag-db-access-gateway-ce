@extends('layouts.app')

@section('title', 'Новий адміністратор')

@section('content')
<a class="back-link" href="{{ route('admin.administrators.index') }}">← До адміністраторів</a>
<h1 class="page-title">Додати локального адміністратора</h1>
<p class="subtitle">Для користувачів Active Directory окремий обліковий запис не створюється — вони з’являються після дозволеного першого входу.</p>
<div class="card form-card">
    <form method="POST" action="{{ route('admin.administrators.store') }}" autocomplete="off">
        @csrf
        <div class="form-grid">
            <div class="full">
                <label for="name">Ім’я</label>
                <input id="name" name="name" maxlength="120" required value="{{ old('name') }}" autocomplete="name">
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="full">
                <label for="email">Email (логін)</label>
                <input id="email" type="email" name="email" maxlength="255" required value="{{ old('email') }}" autocomplete="off">
                @error('email')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="full">
                <label for="role">Роль</label>
                <select id="role" name="role" required>
                    <option value="admin" @selected(old('role', 'admin') === 'admin')>Адміністратор (власні ресурси)</option>
                    <option value="superadmin" @selected(old('role') === 'superadmin')>Суперадміністратор (повний доступ)</option>
                </select>
                @error('role')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="password">Тимчасовий пароль</label>
                <input id="password" type="password" name="password" minlength="14" maxlength="255" required autocomplete="new-password">
                @error('password')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="password_confirmation">Підтвердження пароля</label>
                <input id="password_confirmation" type="password" name="password_confirmation" minlength="14" maxlength="255" required autocomplete="new-password">
            </div>
            <div class="full hint">Мінімум 14 символів, велика та мала літери, цифра. Передайте пароль користувачу захищеним каналом. При першому вході він обов’язково налаштує власне TOTP MFA.</div>
        </div>
        <div class="submit-row">
            <button class="button primary" type="submit">Створити адміністратора</button>
            <a class="button" href="{{ route('admin.administrators.index') }}">Скасувати</a>
        </div>
    </form>
</div>
@endsection
