@extends('layouts.app')

@section('title', 'Resource · SAG DB Access Gateway CE')

@push('styles')
<style>
    .back-link {
        display: inline-block;
        margin-bottom: 18px;
        color: var(--blue);
    }

    .summary {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .summary-card { padding: 16px; }

    .summary-label {
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .summary-value {
        margin-top: 8px;
        overflow-wrap: anywhere;
        font-size: 15px;
        font-weight: 700;
    }

    .actions {
        display: flex;
        gap: 14px;
        flex-wrap: wrap;
        align-items: flex-start;
        padding: 18px;
    }

    .action-block { min-width: 290px; }

    .action-title {
        margin-bottom: 9px;
        font-weight: 750;
    }

    .section { margin-top: 24px; }

    .section-title {
        margin: 0 0 11px;
        font-size: 18px;
    }

    .button {
        display: inline-block;
        min-height: 38px;
        padding: 9px 13px;
        border: 1px solid var(--line);
        border-radius: 8px;
        color: var(--text);
        background: transparent;
        cursor: pointer;
        font-weight: 700;
    }

    .button:hover { border-color: var(--blue); }

    .button.primary {
        border-color: rgba(90,169,255,.55);
        color: #d9ebff;
        background: rgba(90,169,255,.12);
    }

    .button.deactivate {
        border-color: rgba(243,107,107,.55);
        color: var(--red);
    }

    .button.activate {
        border-color: rgba(54,198,145,.55);
        color: var(--green);
    }

    input {
        width: 100%;
        min-height: 38px;
        padding: 8px 10px;
        color: var(--text);
        background: #0d1726;
        border: 1px solid var(--line);
        border-radius: 8px;
    }

    .notice {
        margin-top: 8px;
        color: var(--muted);
        font-size: 13px;
        line-height: 1.45;
    }

    .endpoint {
        color: #c6d7eb;
        font-family: ui-monospace, Consolas, monospace;
    }

    @media (max-width: 900px) {
        .summary { grid-template-columns: 1fr; }
    }
</style>
@endpush

@section('content')
<a class="back-link" href="{{ route('admin.resources.index') }}">
    ← До списку resources
</a>

<h1 class="page-title">{{ $resource->name }}</h1>

<p class="subtitle">
    <span class="badge">{{ $resource->engine }}</span>
    <span class="badge {{ $resource->is_active ? 'green' : 'red' }}">
        {{ $resource->is_active ? 'active' : 'inactive' }}
    </span>
</p>

@if ($errors->has('resource'))
    <div class="form-error">{{ $errors->first('resource') }}</div>
@endif

<section class="summary">
    <div class="card summary-card">
        <div class="summary-label">Target</div>
        <div class="summary-value endpoint">
            {{ $resource->target_host }}:{{ $resource->target_port }}<br>
            <span class="muted">
                {{ $resource->target_database ?: 'database not specified' }}
            </span>
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">Access policy</div>
        <div class="summary-value">
            {{ $resource->auth_mode }}<br>
            <span class="muted">TLS: {{ $resource->target_tls_mode }}</span>
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">SQL audit</div>
        <div class="summary-value">
            <span class="badge {{ $resource->query_audit_enabled ? 'green' : 'red' }}">
                {{ $resource->query_audit_enabled ? 'enabled' : 'disabled' }}
            </span>
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">Sessions</div>
        <div class="summary-value">{{ $resource->sessions_count }}</div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">Active gateways</div>
        <div class="summary-value">{{ $resource->active_sessions_count }}</div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">Open DB connections</div>
        <div class="summary-value">{{ $resource->open_connections_count }}</div>
    </div>
</section>

<section class="card actions">
    <div class="action-block">
        <div class="action-title">Редагування resource</div>

        <a class="button primary" href="{{ route('admin.resources.edit', $resource) }}">
            Редагувати
        </a>

        <div class="notice">
            Зміна engine або target заблокована, поки існує active gateway session.
        </div>
    </div>

    @if ($resource->is_active)
        <form
            class="action-block"
            method="POST"
            action="{{ route('admin.resources.deactivate', $resource) }}"
            onsubmit="return confirm('Деактивувати resource? Нові sessions і Start для created sessions будуть заблоковані.');"
        >
            @csrf

            <div class="action-title">Deactivate resource</div>

            <input
                name="reason"
                type="text"
                minlength="3"
                maxlength="255"
                placeholder="Причина деактивації"
                required
            >

            <div style="margin-top:9px">
                <button class="button deactivate" type="submit">
                    Deactivate
                </button>
            </div>

            <div class="notice">
                Уже запущені gateway sessions не обриваються автоматично.
            </div>
        </form>
    @else
        <form
            class="action-block"
            method="POST"
            action="{{ route('admin.resources.activate', $resource) }}"
            onsubmit="return confirm('Активувати resource і дозволити нові sessions?');"
        >
            @csrf

            <div class="action-title">Activate resource</div>

            <input
                name="reason"
                type="text"
                minlength="3"
                maxlength="255"
                placeholder="Причина активації"
                required
            >

            <div style="margin-top:9px">
                <button class="button activate" type="submit">
                    Activate
                </button>
            </div>
        </form>
    @endif
</section>

<section class="section">
    <h2 class="section-title">Останні gateway sessions</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Session</th>
                    <th>Owner</th>
                    <th>Mode / status</th>
                    <th>Gateway</th>
                    <th>Source CIDR</th>
                    <th>Connections</th>
                    <th>Created</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($sessions as $session)
                    @php
                        $terminal = in_array(
                            $session->status,
                            ['terminated', 'expired', 'ended', 'failed'],
                            true
                        );
                    @endphp

                    <tr>
                        <td>
                            <a href="{{ route('admin.sessions.show', $session) }}">
                                <code>{{ $session->public_id }}</code>
                            </a>
                        </td>
                        <td>{{ $session->owner?->name ?? '—' }}</td>
                        <td>
                            <span class="badge">{{ $session->mode }}</span>
                            <span class="badge {{ $terminal ? 'red' : ($session->status === 'started' ? 'green' : 'yellow') }}">
                                {{ $session->status }}
                            </span>
                        </td>
                        <td class="endpoint">
                            @if ($session->gateway_host && $session->gateway_port)
                                @if ($terminal)
                                    <span class="badge red">released</span><br>
                                    <span class="muted">
                                        {{ $session->gateway_host }}:{{ $session->gateway_port }}
                                    </span>
                                @else
                                    {{ $session->gateway_host }}:{{ $session->gateway_port }}
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $session->allowed_source_cidr ?? '—' }}</td>
                        <td>{{ $session->open_connections_count }}</td>
                        <td>{{ $session->created_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">
                            Sessions для цього resource відсутні.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="section">
    <h2 class="section-title">Audit events</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Event</th>
                    <th>Severity</th>
                    <th>Actor</th>
                    <th>IP</th>
                    <th>Data</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($auditEvents as $event)
                    <tr>
                        <td>{{ $event->occurred_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                        <td><code>{{ $event->event_type }}</code></td>
                        <td><span class="badge">{{ $event->severity }}</span></td>
                        <td>{{ $event->actor?->name ?? 'system' }}</td>
                        <td>{{ $event->ip_address ?? '—' }}</td>
                        <td>
                            <code title="{{ json_encode($event->event_data) }}">
                                {{ \Illuminate\Support\Str::limit(json_encode($event->event_data, JSON_UNESCAPED_UNICODE), 200) }}
                            </code>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="muted">Audit events відсутні.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
