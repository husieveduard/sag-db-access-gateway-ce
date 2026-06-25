@extends('layouts.app')

@section('title', 'Створити session · SAG DB Access Gateway CE')

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
    input, select {
        width: 100%;
        padding: 10px 11px;
        color: var(--text);
        background: #0d1726;
        border: 1px solid var(--line);
        border-radius: 8px;
        outline: none;
    }
    input:focus, select:focus { border-color: var(--blue); }
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
    .mode-note {
        margin-top: 8px;
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: 8px;
        color: #c6d7eb;
        background: rgba(255,255,255,.02);
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
<a class="back-link" href="{{ route('admin.sessions.index') }}">
    ← До списку sessions
</a>

<h1 class="page-title">Створити DB session</h1>
<p class="subtitle">
    Створення доступу до вибраного DB resource. Gateway стартує окремою дією після створення.
</p>

@if ($resources->isEmpty())
    <div class="form-error">
        Немає активних DB resources. Спочатку створи resource.
    </div>
@else
    <form
        class="card form-card"
        method="POST"
        action="{{ route('admin.sessions.store') }}"
    >
        @csrf

        <div class="form-grid">
            <div class="full">
                <label for="resource_id">DB resource</label>
                <select id="resource_id" name="resource_id" required autofocus>
                    <option value="">Оберіть resource</option>

                    @foreach ($resources as $resource)
                        <option
                            value="{{ $resource->id }}"
                            @selected((string) old('resource_id') === (string) $resource->id)
                        >
                            {{ $resource->name }}
                            — {{ $resource->engine }}
                            — {{ $resource->target_host }}:{{ $resource->target_port }}
                            @if ($resource->target_database)
                                / {{ $resource->target_database }}
                            @endif
                        </option>
                    @endforeach
                </select>

                @error('resource_id')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="mode">Тип підключення</label>
                <select id="mode" name="mode" required>
                    <option value="temporary" @selected(old('mode', 'temporary') === 'temporary')>
                        Temporary — з TTL
                    </option>
                    <option value="persistent" @selected(old('mode') === 'persistent')>
                        Persistent — до ручного завершення
                    </option>
                </select>

                <div id="mode-note" class="mode-note"></div>

                @error('mode')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div id="ttl-wrap">
                <label for="ttl_seconds">TTL, секунд</label>
                <input
                    id="ttl_seconds"
                    name="ttl_seconds"
                    type="number"
                    min="60"
                    max="604800"
                    value="{{ old('ttl_seconds', 3600) }}"
                >
                <div class="hint">
                    Від 60 секунд до 7 днів. Після TTL gateway і активні підключення будуть примусово завершені.
                </div>

                @error('ttl_seconds')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="source_cidr">Source IP / CIDR</label>
                <input
                    id="source_cidr"
                    name="source_cidr"
                    type="text"
                    maxlength="128"
                    value="{{ old('source_cidr') }}"
                    placeholder="10.10.10.1/32"
                    required
                >
                <div class="hint">
                    З якої IP-адреси або CIDR дозволене підключення до gateway.
                </div>

                @error('source_cidr')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <label for="db_username">DB username</label>
                <input
                    id="db_username"
                    name="db_username"
                    type="text"
                    maxlength="128"
                    value="{{ old('db_username') }}"
                    placeholder="db_readonly_user"
                    required
                >
                <div class="hint">
                    Використовується лише для аудиту. Пароль БД не зберігається.
                </div>

                @error('db_username')
                    <div class="field-error">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="hint">
            Нова session створюється у статусі <code>created</code>.
            Щоб відкрити gateway endpoint і виділити порт, на сторінці session натисни <code>Start session</code>.
        </div>

        <div class="submit-row">
            <button class="button primary" type="submit">
                Створити session
            </button>

            <a class="button" href="{{ route('admin.sessions.index') }}">
                Скасувати
            </a>
        </div>
    </form>
@endif

<script>
(() => {
    const mode = document.getElementById('mode');
    const ttlWrap = document.getElementById('ttl-wrap');
    const ttl = document.getElementById('ttl_seconds');
    const note = document.getElementById('mode-note');

    if (!mode || !ttlWrap || !ttl || !note) {
        return;
    }

    const syncMode = () => {
        const temporary = mode.value === 'temporary';

        ttlWrap.hidden = !temporary;
        ttl.required = temporary;

        note.textContent = temporary
            ? 'Temporary: сесія автоматично завершиться після TTL. Gateway, активні DB connections і запити будуть примусово закриті.'
            : 'Persistent: TTL не встановлюється. Сесія працює до ручного Terminate / Revoke.';
    };

    mode.addEventListener('change', syncMode);
    syncMode();
})();
</script>
@endsection
