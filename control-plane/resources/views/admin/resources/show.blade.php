@extends('layouts.app')

@section('title', __('resources.show.title').' · '.__('ce.app_title'))

@section('content')
@php
    $notAvailable = __('ce.common.not_available');

    $engineLabels = [
        'mysql' => __('resources.engine.mysql'),
        'postgresql' => __('resources.engine.postgresql'),
        'mssql' => __('resources.engine.mssql'),
    ];

    $tlsLabels = [
        'prefer' => __('resources.tls.prefer'),
        'require' => __('resources.tls.require'),
        'disable' => __('resources.tls.disable'),
        'verify_ca' => __('resources.tls.verify_ca'),
        'verify_full' => __('resources.tls.verify_full'),
    ];

    $authLabels = [
        'client_passthrough' => __('resources.auth.client_passthrough'),
        'managed' => __('resources.auth.managed'),
    ];

    $sessionModeLabels = [
        'temporary' => __('sessions.mode.temporary'),
        'persistent' => __('sessions.mode.persistent'),
    ];

    $sessionStatusLabels = [
        'created' => __('sessions.status.created'),
        'starting' => __('sessions.status.starting'),
        'started' => __('sessions.status.started'),
        'ended' => __('sessions.status.ended'),
        'terminated' => __('sessions.status.terminated'),
        'expired' => __('sessions.status.expired'),
        'failed' => __('sessions.status.failed'),
    ];

    $severityLabels = [
        'info' => __('resources.show.audit_events.severity.info'),
        'medium' => __('resources.show.audit_events.severity.medium'),
        'high' => __('resources.show.audit_events.severity.high'),
        'critical' => __('resources.show.audit_events.severity.critical'),
    ];
@endphp

<a class="back-link" href="{{ route('admin.resources.index') }}">
    {{ __('resources.show.back') }}
</a>

<h1 class="page-title">{{ $resource->name }}</h1>

<p class="subtitle">
    <span class="badge">
        {{ $engineLabels[$resource->engine] ?? $resource->engine }}
    </span>

    <span class="badge {{ $resource->is_active ? 'green' : 'red' }}">
        {{ $resource->is_active
            ? __('resources.status.active')
            : __('resources.status.inactive') }}
    </span>
</p>

@if ($errors->has('resource'))
    <div class="form-error">{{ $errors->first('resource') }}</div>
@endif

<section class="summary">
    <div class="card summary-card">
        <div class="summary-label">{{ __('resources.show.summary.target') }}</div>

        <div class="summary-value endpoint">
            {{ $resource->target_host }}:{{ $resource->target_port }}<br>

            <span class="muted">
                {{ $resource->target_database
                    ?: __('resources.show.summary.database_not_specified') }}
            </span>
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">
            {{ __('resources.show.summary.access_policy') }}
        </div>

        <div class="summary-value">
            {{ $authLabels[$resource->auth_mode] ?? $resource->auth_mode }}<br>

            <span class="muted">
                {{ __('resources.show.summary.tls') }}:
                {{ $tlsLabels[$resource->target_tls_mode]
                    ?? $resource->target_tls_mode }}
            </span>
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">
            {{ __('resources.show.summary.sql_audit') }}
        </div>

        <div class="summary-value">
            <span class="badge {{ $resource->query_audit_enabled ? 'green' : 'red' }}">
                {{ $resource->query_audit_enabled
                    ? __('resources.audit.enabled')
                    : __('resources.audit.disabled') }}
            </span>
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">{{ __('resources.show.summary.sessions') }}</div>
        <div class="summary-value">{{ $resource->sessions_count }}</div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">
            {{ __('resources.show.summary.active_gateways') }}
        </div>

        <div class="summary-value">{{ $resource->active_sessions_count }}</div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">
            {{ __('resources.show.summary.open_db_connections') }}
        </div>

        <div class="summary-value">{{ $resource->open_connections_count }}</div>
    </div>
</section>

<section class="card actions">
    <div class="action-block">
        <div class="action-title">{{ __('resources.show.edit.heading') }}</div>

        <a class="button primary" href="{{ route('admin.resources.edit', $resource) }}">
            {{ __('resources.show.edit.action') }}
        </a>

        <div class="notice">{{ __('resources.show.edit.notice') }}</div>
    </div>

    @if ($resource->is_active)
        <form
            class="action-block"
            method="POST"
            action="{{ route('admin.resources.deactivate', $resource) }}"
            onsubmit="return confirm(@js(__('resources.show.deactivate.confirm')));"
        >
            @csrf

            <div class="action-title">
                {{ __('resources.show.deactivate.heading') }}
            </div>

            <input
                name="reason"
                type="text"
                minlength="3"
                maxlength="255"
                placeholder="{{ __('resources.show.deactivate.placeholder') }}"
                required
            >

            <div class="mt-9">
                <button class="button deactivate" type="submit">
                    {{ __('resources.show.deactivate.action') }}
                </button>
            </div>

            <div class="notice">
                {{ __('resources.show.deactivate.notice') }}
            </div>
        </form>
    @else
        <form
            class="action-block"
            method="POST"
            action="{{ route('admin.resources.activate', $resource) }}"
            onsubmit="return confirm(@js(__('resources.show.activate.confirm')));"
        >
            @csrf

            <div class="action-title">
                {{ __('resources.show.activate.heading') }}
            </div>

            <input
                name="reason"
                type="text"
                minlength="3"
                maxlength="255"
                placeholder="{{ __('resources.show.activate.placeholder') }}"
                required
            >

            <div class="mt-9">
                <button class="button activate" type="submit">
                    {{ __('resources.show.activate.action') }}
                </button>
            </div>
        </form>
    @endif
</section>

<section class="section">
    <h2 class="section-title">{{ __('resources.show.sessions.heading') }}</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('resources.show.sessions.columns.session') }}</th>
                    <th>{{ __('resources.show.sessions.columns.owner') }}</th>
                    <th>{{ __('resources.show.sessions.columns.mode_status') }}</th>
                    <th>{{ __('resources.show.sessions.columns.gateway') }}</th>
                    <th>{{ __('resources.show.sessions.columns.source_cidr') }}</th>
                    <th>{{ __('resources.show.sessions.columns.connections') }}</th>
                    <th>{{ __('resources.show.sessions.columns.created') }}</th>
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

                        $statusClass = match ($session->status) {
                            'started' => 'green',
                            'starting', 'created' => 'yellow',
                            'terminated', 'expired', 'failed', 'ended' => 'red',
                            default => '',
                        };
                    @endphp

                    <tr>
                        <td>
                            <a href="{{ route('admin.sessions.show', $session) }}">
                                <code>{{ $session->public_id }}</code>
                            </a>
                        </td>

                        <td>{{ $session->owner?->name ?? $notAvailable }}</td>

                        <td>
                            <span class="badge">
                                {{ $sessionModeLabels[$session->mode] ?? $session->mode }}
                            </span>

                            <span class="badge {{ $statusClass }}">
                                {{ $sessionStatusLabels[$session->status]
                                    ?? $session->status }}
                            </span>
                        </td>

                        <td class="endpoint">
                            @if ($session->gateway_host && $session->gateway_port)
                                @if ($terminal)
                                    <span class="badge red">
                                        {{ __('resources.show.sessions.released') }}
                                    </span><br>

                                    <span class="muted">
                                        {{ $session->gateway_host }}:{{ $session->gateway_port }}
                                    </span>
                                @else
                                    {{ $session->gateway_host }}:{{ $session->gateway_port }}
                                @endif
                            @else
                                {{ $notAvailable }}
                            @endif
                        </td>

                        <td>{{ $session->allowed_source_cidr ?? $notAvailable }}</td>
                        <td>{{ $session->open_connections_count }}</td>
                        <td>{{ $session->created_at?->format('d.m.Y H:i:s') ?? $notAvailable }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">
                            {{ __('resources.show.sessions.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="section">
    <h2 class="section-title">{{ __('resources.show.audit_events.heading') }}</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('resources.show.audit_events.columns.time') }}</th>
                    <th>{{ __('resources.show.audit_events.columns.event') }}</th>
                    <th>{{ __('resources.show.audit_events.columns.severity') }}</th>
                    <th>{{ __('resources.show.audit_events.columns.actor') }}</th>
                    <th>{{ __('resources.show.audit_events.columns.ip') }}</th>
                    <th>{{ __('resources.show.audit_events.columns.data') }}</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($auditEvents as $event)
                    <tr>
                        <td>{{ $event->occurred_at?->format('d.m.Y H:i:s') ?? $notAvailable }}</td>
                        <td><code>{{ $event->event_type }}</code></td>

                        <td>
                            <span class="badge">
                                {{ $severityLabels[$event->severity] ?? $event->severity }}
                            </span>
                        </td>

                        <td>
                            {{ $event->actor?->name
                                ?? __('resources.show.audit_events.system') }}
                        </td>

                        <td>{{ $event->ip_address ?? $notAvailable }}</td>

                        <td>
                            <code title="{{ json_encode($event->event_data) }}">
                                {{ \Illuminate\Support\Str::limit(
                                    json_encode(
                                        $event->event_data,
                                        JSON_UNESCAPED_UNICODE
                                    ),
                                    200
                                ) }}
                            </code>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="muted">
                            {{ __('resources.show.audit_events.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
