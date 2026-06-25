@extends('layouts.app')

@section('title', 'Створити resource · SAG DB Access Gateway CE')

@push('styles')
<style>
    .back-link {
        display: inline-block;
        margin-bottom: 18px;
        color: var(--blue);
    }
    .form-card {
        width: min(820px, 100%);
        padding: 24px;
    }
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }
    .full { grid-column: 1 / -1; }
    label {
        display: block;
        margin-bottom: 7px;
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
    }
    input, select, textarea {
        width: 100%;
        padding: 10px 11px;
        color: var(--text);
        background: #0d1726;
        border: 1px solid var(--line);
        border-radius: 8px;
        outline: none;
    }
    textarea {
        min-height: 110px;
        resize: vertical;
    }
    input:focus, select:focus, textarea:focus {
        border-color: var(--blue);
    }
    .field-error {
        margin-top: 6px;
        color: #ffc2c2;
        font-size: 13px;
    }
    .hint {
        margin-top: 7px;
        color: var(--muted);
        font-size: 13px;
        line-height: 1.45;
    }
    .submit-row {
        display: flex;
        gap: 12px;
        align-items: center;
        margin-top: 22px;
    }
    .button {
        min-height: 39px;
        padding: 9px 13px;
        border: 1px solid var(--line);
        border-radius: 8px;
        color: var(--text);
        background: transparent;
        cursor: pointer;
        font-weight: 700;
    }
    .button.primary {
        border-color: rgba(90,169,255,.55);
        color: #d9ebff;
        background: rgba(90,169,255,.13);
    }
    .button:hover { border-color: var(--blue); }
    @media (max-width: 650px) {
        .form-grid { grid-template-columns: 1fr; }
    }
</style>
@endpush

@section('content')
<a class="back-link" href="{{ route('admin.resources.index') }}">
    ← До списку resources
</a>

<h1 class="page-title">Створити DB resource</h1>
<p class="subtitle">
    Resource описує цільову БД. Облікові дані БД тут не зберігаються.
</p>

<form
    class="card form-card"
    method="POST"
    action="{{ route('admin.resources.store') }}"
>
    @csrf

    <div class="form-grid">
        <div>
            <label for="name">Назва resource</label>
            <input
                id="name"
                name="name"
                type="text"
                maxlength="160"
                value="{{ old('name') }}"
                placeholder="production_redmine_mysql"
                required
                autofocus
            >
            @error('name')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="engine">Тип БД</label>
            <select id="engine" name="engine" required>
                @foreach ($engines as $value => $label)
                    <option value="{{ $value }}" @selected(old('engine', 'mysql') === $value)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
            <div class="hint">MSSQL позначено як experimental.</div>
            @error('engine')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="target_host">Target host</label>
            <input
                id="target_host"
                name="target_host"
                type="text"
                maxlength="253"
                value="{{ old('target_host') }}"
                placeholder="172.16.9.33 або db.internal.local"
                required
            >
            @error('target_host')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label for="target_port">Target port</label>
            <input
                id="target_port"
                name="target_port"
                type="number"
                min="1"
                max="65535"
                value="{{ old('target_port', 3306) }}"
                required
            >
            @error('target_port')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="full">
            <label for="target_database">Database / catalog</label>
            <input
                id="target_database"
                name="target_database"
                type="text"
                maxlength="128"
                value="{{ old('target_database') }}"
                placeholder="redmine2"
            >
            <div class="hint">
                Необов’язкове поле. Використовується для аудиту та зручності роботи клієнта.
            </div>
            @error('target_database')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="full">
            <label for="description">Опис</label>
            <textarea
                id="description"
                name="description"
                maxlength="5000"
                placeholder="Призначення БД, середовище, обмеження доступу…"
            >{{ old('description') }}</textarea>
            @error('description')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="hint">
        Новий resource буде активним, SQL audit — увімкненим.
        Тип авторизації: <code>client_passthrough</code>.
        Перевірка доступності target БД під час створення не виконується.
    </div>

    <div class="submit-row">
        <button class="button primary" type="submit">
            Створити resource
        </button>

        <a class="button" href="{{ route('admin.resources.index') }}">
            Скасувати
        </a>
    </div>
</form>
@endsection
