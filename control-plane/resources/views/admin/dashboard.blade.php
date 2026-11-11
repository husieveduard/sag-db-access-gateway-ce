@extends('layouts.app')

@section('title', __('dashboard.title').' · '.__('ce.app_title'))

@section('content')
@php
    $notAvailable = __('ce.common.not_available');

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

    $engineLabels = [
        'mysql' => __('resources.engine.mysql'),
        'postgresql' => __('resources.engine.postgresql'),
        'mssql' => __('resources.engine.mssql'),
    ];

    $riskLabels = [
        'critical' => __('dashboard.sql.risk.critical'),
        'high' => __('dashboard.sql.risk.high'),
        'medium' => __('dashboard.sql.risk.medium'),
        'low' => __('dashboard.sql.risk.low'),
    ];

    $queryStatusLabels = [
        'queued' => __('dashboard.sql.status.queued'),
        'running' => __('dashboard.sql.status.running'),
        'started' => __('dashboard.sql.status.started'),
        'completed' => __('dashboard.sql.status.completed'),
        'succeeded' => __('dashboard.sql.status.succeeded'),
        'failed' => __('dashboard.sql.status.failed'),
        'denied' => __('dashboard.sql.status.denied'),
        'closed' => __('dashboard.sql.status.closed'),
    ];
@endphp

<h1 class="page-title">{{ __('dashboard.heading') }}</h1>

<p class="subtitle">
    {{ __('dashboard.subtitle', ['timezone' => config('app.timezone')]) }}
</p>

<section class="stats">
    <div class="card stat">
        <div class="stat-label">{{ __('dashboard.stats.active_sessions') }}</div>
        <div class="stat-value">{{ $stats['active_sessions'] }}</div>
    </div>

    <div class="card stat">
        <div class="stat-label">{{ __('dashboard.stats.active_resources') }}</div>
        <div class="stat-value">{{ $stats['active_resources'] }}</div>
    </div>

    <div class="card stat">
        <div class="stat-label">{{ __('dashboard.stats.open_connections') }}</div>
        <div class="stat-value">{{ $stats['open_connections'] }}</div>
    </div>

    <div class="card stat {{ $stats['high_risk_24h'] > 0 ? 'ce-stat-attention' : '' }}">
        <div class="stat-label">{{ __('dashboard.stats.high_risk_24h') }}</div>
        <div class="stat-value">{{ $stats['high_risk_24h'] }}</div>
    </div>
</section>

<section class="section">
    <h2 class="section-title">{{ __('dashboard.sessions.heading') }}</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('dashboard.sessions.columns.session') }}</th>
                    <th>{{ __('dashboard.sessions.columns.resource') }}</th>
                    <th>{{ __('dashboard.sessions.columns.owner') }}</th>
                    <th>{{ __('dashboard.sessions.columns.mode_status') }}</th>
                    <th>{{ __('dashboard.sessions.columns.gateway') }}</th>
                    <th>{{ __('dashboard.sessions.columns.source_cidr') }}</th>
                    <th>{{ __('dashboard.sessions.columns.open_connections') }}</th>
                    <th>{{ __('dashboard.sessions.columns.lifecycle') }}</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($sessions as $session)
                    @php
                        $statusClass = match ($session->status) {
                            'started' => 'green',
                            'starting', 'created' => 'yellow',
                            'failed' => 'red',
                        'terminated', 'expired', 'ended' => '',
                            default => '',
                        };
                    @endphp

                    <tr>
                        <td>
                            <span class="ce-id-with-copy">
                            <code class="ce-compact-id" title="{{ $session->public_id }}">{{ \Illuminate\Support\Str::substr($session->public_id, 0, 14).'…'.\Illuminate\Support\Str::substr($session->public_id, -8) }}</code>
                            <button type="button" class="ce-copy-btn"
                                data-ce-copy="{{ $session->public_id }}"
                                data-ce-label-copied="{{ __('ce.sidebar.copied') }}"
                                data-ce-label-failed="{{ __('ce.sidebar.copy_failed') }}"
                                title="{{ __('ce.sidebar.copy_id') }}"
                                aria-label="{{ __('ce.sidebar.copy_id') }}">⧉</button>
                        </span>
                        </td>

                        <td>
                            <strong>{{ $session->resource?->name ?? $notAvailable }}</strong><br>

                            <span class="muted">
                                {{ $engineLabels[$session->resource?->engine ?? '']
                                    ?? ($session->resource?->engine ?? $notAvailable) }}
                            </span>
                        </td>

                        <td>
                            {{ $session->owner?->name ?? $notAvailable }}<br>
                            <span class="muted">{{ $session->owner?->email ?? '' }}</span>
                        </td>

                        <td>
                            <span class="badge">
                                {{ $sessionModeLabels[$session->mode] ?? $session->mode }}
                            </span>

                            <span class="badge {{ $statusClass }}">
                                {{ $sessionStatusLabels[$session->status] ?? $session->status }}
                            </span>
                        </td>

                        <td class="endpoint">
                            @if ($session->gateway_host && $session->gateway_port)
                                {{ $session->gateway_host }}:{{ $session->gateway_port }}
                            @else
                                {{ $notAvailable }}
                            @endif
                        </td>

                        <td>{{ $session->allowed_source_cidr ?? $notAvailable }}</td>
                        <td>{{ $session->open_connections_count }}</td>

                        <td>
                            @if ($session->mode === 'temporary')
                                {{ app(\App\Services\Settings\DisplayClock::class)->format($session->expires_at) ?? $notAvailable }}
                            @else
                                {{ app(\App\Services\Settings\DisplayClock::class)->format($session->ended_at)
                                    ?? __('dashboard.sessions.persistent_manual') }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="muted">
                            {{ __('dashboard.sessions.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="section">
    <h2 class="section-title">{{ __('dashboard.sql.heading') }}</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('dashboard.sql.columns.time') }}</th>
                    <th>{{ __('dashboard.sql.columns.resource_session') }}</th>
                    <th>{{ __('dashboard.sql.columns.type') }}</th>
                    <th>{{ __('dashboard.sql.columns.risk') }}</th>
                    <th>{{ __('dashboard.sql.columns.status') }}</th>
                    <th>{{ __('dashboard.sql.columns.duration') }}</th>
                    <th>{{ __('dashboard.sql.columns.sql') }}</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($queries as $query)
                    @php
                        $riskClass = match ($query->risk_level) {
                            'critical', 'high' => 'red',
                            'medium' => 'yellow',
                            'low' => 'green',
                            default => '',
                        };
                    @endphp

                    <tr>
                        <td>{{ app(\App\Services\Settings\DisplayClock::class)->format($query->occurred_at) ?? $notAvailable }}</td>

                        <td>
                            <strong>{{ $query->resource?->name ?? $notAvailable }}</strong><br>

                            @if ($query->session?->public_id)
                                <span class="ce-id-with-copy">
                            <code class="ce-compact-id" title="{{ $query->session->public_id }}">{{ \Illuminate\Support\Str::substr($query->session->public_id, 0, 14).'…'.\Illuminate\Support\Str::substr($query->session->public_id, -8) }}</code>
                            <button type="button" class="ce-copy-btn"
                                data-ce-copy="{{ $query->session->public_id }}"
                                data-ce-label-copied="{{ __('ce.sidebar.copied') }}"
                                data-ce-label-failed="{{ __('ce.sidebar.copy_failed') }}"
                                title="{{ __('ce.sidebar.copy_id') }}"
                                aria-label="{{ __('ce.sidebar.copy_id') }}">⧉</button>
                        </span>
                            @else
                                {{ $notAvailable }}
                            @endif
                        </td>

                        <td><span class="badge">{{ $query->statement_type }}</span></td>

                        <td>
                            <span class="badge {{ $riskClass }}">
                                {{ $riskLabels[$query->risk_level] ?? $query->risk_level }}
                            </span>

                            @if ($query->risk_reason)
                                <br><span class="muted">{{ $query->risk_reason }}</span>
                            @endif
                        </td>

                        <td>
                            <span class="badge {{ $query->query_status === 'failed' ? 'red' : '' }}">
                                {{ $queryStatusLabels[$query->query_status]
                                    ?? $query->query_status }}
                            </span>
                        </td>

                        <td>
                            {{ $query->duration_ms !== null
                                ? $query->duration_ms.' ms'
                                : $notAvailable }}
                        </td>

                        <td>
                            <code title="{{ $query->sql_text }}">
                                {{ \Illuminate\Support\Str::limit(
                                    (string) $query->sql_text,
                                    150
                                ) }}
                            </code>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">
                            {{ __('dashboard.sql.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
