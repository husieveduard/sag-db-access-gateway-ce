@extends('layouts.app')

@section('title', 'Редагувати resource · SAG DB Access Gateway CE')

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

    .warning {
        margin-bottom: 18px;
        padding: 12px 14px;
        border: 1px solid rgba(242,201,76,.30);
        border-radius: 9px;
        color: #f7dfa0;
        background: rgba(242,201,76,.08);
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
<a class="back-link" href="{{ route('admin.resources.show', $resource) }}">
    ← До картки resource
</a>

<h1 class="page-title">Редагувати DB resource</h1>
<p class="subtitle">{{ $resource->name }}</p>

@if ($errors->has('resource'))
    <div class="form-error">{{ $errors->first('resource') }}</div>
@endif

@if ($resource->active_sessions_count > 0)
    <div class="warning">
        Зараз є active gateway sessions: {{ $resource->active_sessions_count }}.
        Зміна engine, target host, port або database буде заблокована до їх завершення.
    </div>
@endif

<form
    class="card form-card"
    method="POST"
    action="{{ route('admin.resources.update', $resource) }}"
>
    @csrf
    @method('PUT')

    <div class="form-grid">
        <div>
            <label for="name">Назва resource</label>
            <input
                id="name"
                name="name"
                type="text"
                maxlength="160"
                value="{{ old('name', $resource->name) }}"
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
                    <option
                        value="{{ $value }}"
                        @selected(old('engine', $resource->engine) === $value)
                    >
                        {{ $label }}
                    </option>
                @endforeach
            </select>
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
                value="{{ old('target_host', $resource->target_host) }}"
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
                value="{{ old('target_port', $resource->target_port) }}"
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
                value="{{ old('target_database', $resource->target_database) }}"
            >
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
            >{{ old('description', $resource->description) }}</textarea>
            @error('description')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="hint">
        Паролі БД не зберігаються. Використовується
        <code>client_passthrough</code>; SQL audit увімкнений.
    </div>

    <div class="submit-row">
        <button class="button primary" type="submit">
            Зберегти зміни
        </button>

        <a class="button" href="{{ route('admin.resources.show', $resource) }}">
            Скасувати
        </a>
    </div>
</form>
@endsection
