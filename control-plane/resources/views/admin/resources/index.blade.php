@extends('layouts.app')

@section('title', 'Resources · SAG DB Access Gateway CE')

@push('styles')
<style>
    .page-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 24px;
    }

    .page-head .subtitle { margin-bottom: 0; }

    .button {
        display: inline-block;
        min-height: 38px;
        padding: 9px 13px;
        border: 1px solid var(--line);
        border-radius: 8px;
        color: var(--text);
        background: transparent;
        font-weight: 700;
    }

    .button.primary {
        border-color: rgba(90,169,255,.55);
        color: #d9ebff;
        background: rgba(90,169,255,.12);
    }

    .button:hover { border-color: var(--blue); }

    .resource-name {
        color: var(--blue);
        font-weight: 750;
    }

    .endpoint {
        color: #c6d7eb;
        font-family: ui-monospace, Consolas, monospace;
    }

    @media (max-width: 650px) {
        .page-head { flex-direction: column; }
    }
</style>
@endpush

@section('content')
<div class="page-head">
    <div>
        <h1 class="page-title">DB Resources</h1>
        <p class="subtitle">
            Цільові БД, доступні для створення gateway sessions.
        </p>
    </div>

    <a class="button primary" href="{{ route('admin.resources.create') }}">
        + Створити resource
    </a>
</div>

<div class="card table-wrap">
    <table>
        <thead>
            <tr>
                <th>Resource</th>
                <th>Engine</th>
                <th>Target</th>
                <th>TLS / Auth</th>
                <th>SQL audit</th>
                <th>Стан</th>
                <th>Sessions</th>
                <th>Active gateways</th>
                <th>Open connections</th>
                <th>Створив</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($resources as $resource)
                <tr>
                    <td>
                        <a
                            class="resource-name"
                            href="{{ route('admin.resources.show', $resource) }}"
                        >
                            {{ $resource->name }}
                        </a>

                        <div class="muted">
                            {{ $resource->description ?: '—' }}
                        </div>
                    </td>

                    <td>
                        <span class="badge">{{ $resource->engine }}</span>
                    </td>

                    <td class="endpoint">
                        {{ $resource->target_host }}:{{ $resource->target_port }}

                        @if ($resource->target_database)
                            <br>
                            <span class="muted">
                                {{ $resource->target_database }}
                            </span>
                        @endif
                    </td>

                    <td>
                        {{ $resource->target_tls_mode }}<br>
                        <span class="muted">
                            {{ $resource->auth_mode }}
                        </span>
                    </td>

                    <td>
                        <span class="badge {{ $resource->query_audit_enabled ? 'green' : 'red' }}">
                            {{ $resource->query_audit_enabled ? 'enabled' : 'disabled' }}
                        </span>
                    </td>

                    <td>
                        <span class="badge {{ $resource->is_active ? 'green' : 'red' }}">
                            {{ $resource->is_active ? 'active' : 'inactive' }}
                        </span>
                    </td>

                    <td>{{ $resource->sessions_count }}</td>
                    <td>{{ $resource->active_sessions_count }}</td>
                    <td>{{ $resource->open_connections_count }}</td>

                    <td>
                        {{ $resource->createdBy?->name ?? '—' }}<br>
                        <span class="muted">
                            {{ $resource->createdBy?->email ?? '' }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="muted">
                        DB resources ще не створювались.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
