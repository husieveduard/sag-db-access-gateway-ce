@extends('layouts.app')

@section('title', __('session_show.title').' · '.__('ce.app_title'))

@section('content')
@php
    $statusClass = match ($session->status) {
        'started' => 'green',
        'starting', 'created' => 'yellow',
        'terminated', 'expired', 'failed', 'ended' => 'red',
        default => '',
    };

    $hasPendingOperation = $operations
        ->whereIn('status', ['queued', 'running'])
        ->isNotEmpty();

    $canStart = in_array($session->status, ['created', 'failed'], true)
        && ! $hasPendingOperation;

    $canTerminate = ! in_array(
        $session->status,
        ['terminated', 'expired', 'ended'],
        true
    ) && ! $hasPendingOperation;

    $notAvailable = __('ce.common.not_available');

    $modeLabels = [
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

    $operationTypeLabels = [
        'start_session' => __('session_show.operations.type.start_session'),
        'terminate_session' => __('session_show.operations.type.terminate_session'),
    ];

    $operationStatusLabels = [
        'queued' => __('session_show.operations.status.queued'),
        'running' => __('session_show.operations.status.running'),
        'succeeded' => __('session_show.operations.status.succeeded'),
        'failed' => __('session_show.operations.status.failed'),
    ];

    $connectionStatusLabels = [
        'opened' => __('session_show.connections.status.opened'),
        'closed' => __('session_show.connections.status.closed'),
        'denied' => __('session_show.connections.status.denied'),
        'failed' => __('session_show.connections.status.failed'),
    ];

    $queryStatusLabels = [
        'queued' => __('session_show.sql_audit.status.queued'),
        'running' => __('session_show.sql_audit.status.running'),
        'started' => __('session_show.sql_audit.status.started'),
        'completed' => __('session_show.sql_audit.status.completed'),
        'succeeded' => __('session_show.sql_audit.status.succeeded'),
        'failed' => __('session_show.sql_audit.status.failed'),
        'denied' => __('session_show.sql_audit.status.denied'),
        'closed' => __('session_show.sql_audit.status.closed'),
    ];

    $riskLabels = [
        'all' => __('session_show.sql_audit.risk.all'),
        'critical' => __('session_show.sql_audit.risk.critical'),
        'high' => __('session_show.sql_audit.risk.high'),
        'medium' => __('session_show.sql_audit.risk.medium'),
        'low' => __('session_show.sql_audit.risk.low'),
    ];

    $severityLabels = [
        'info' => __('session_show.common.info'),
        'medium' => __('session_show.common.medium'),
        'high' => __('session_show.common.high'),
        'critical' => __('session_show.common.critical'),
    ];
@endphp

<a class="back-link" href="{{ route('admin.sessions.index') }}">
    {{ __('session_show.back') }}
</a>

<h1 class="page-title">
    <code title="{{ $session->public_id }}">{{ $session->public_id }}</code>
</h1>

<p class="subtitle">
    <span class="badge">
        {{ $modeLabels[$session->mode] ?? $session->mode }}
    </span>

    <span class="badge {{ $statusClass }}">
        {{ $sessionStatusLabels[$session->status] ?? $session->status }}
    </span>

    @if ($hasPendingOperation)
        <span class="badge yellow">{{ __('session_show.pending_operation') }}</span>
    @endif
</p>

<section class="summary">
    <div class="card summary-card">
        <div class="summary-label">{{ __('session_show.summary.resource') }}</div>
        <div class="summary-value">
            {{ $session->resource?->name ?? $notAvailable }}<br>
            <span class="muted">
                {{ $session->resource?->engine ?? $notAvailable }} ·
                {{ $session->resource?->target_host ?? $notAvailable }}:{{ $session->resource?->target_port ?? $notAvailable }}
            </span>
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">{{ __('session_show.summary.owner_db_user') }}</div>
        <div class="summary-value">
            {{ $session->owner?->name ?? $notAvailable }}<br>
            <span class="muted">{{ $session->target_db_username ?? $notAvailable }}</span>
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">{{ __('session_show.summary.gateway_endpoint') }}</div>
        <div class="summary-value endpoint">
            @php
                $endpointReleased = in_array(
                    $session->status,
                    ['terminated', 'expired', 'ended', 'failed'],
                    true
                );
            @endphp

            @if ($session->gateway_host && $session->gateway_port)
                @if ($endpointReleased)
                    <span class="badge red">{{ __('session_show.summary.released') }}</span><br>
                    <span class="muted">
                        {{ $session->gateway_host }}:{{ $session->gateway_port }}
                        · {{ __('session_show.summary.historical_endpoint') }}
                    </span>
                @else
                    {{ $session->gateway_host }}:{{ $session->gateway_port }}
                @endif
            @else
                {{ __('session_show.summary.not_started') }}
            @endif
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">{{ __('session_show.summary.source_cidr') }}</div>
        <div class="summary-value">{{ $session->allowed_source_cidr ?? $notAvailable }}</div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">{{ __('session_show.summary.ttl_expiry') }}</div>
        <div class="summary-value">
            {{ $session->expires_at?->format('d.m.Y H:i:s')
                ?? __('session_show.summary.persistent_service') }}
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">{{ __('session_show.summary.lifecycle') }}</div>
        <div class="summary-value">
            {{ __('session_show.summary.started') }}:
            {{ $session->started_at?->format('d.m.Y H:i:s') ?? $notAvailable }}<br>

            {{ __('session_show.summary.ended') }}:
            {{ $session->ended_at?->format('d.m.Y H:i:s') ?? $notAvailable }}
        </div>
    </div>
</section>

<section class="card actions">
    @if ($canStart)
        <form
            method="POST"
            action="{{ route('admin.sessions.start', $session) }}"
            onsubmit="return confirm('{{ __('session_show.actions.confirm_start') }}');"
        >
            @csrf

            <div class="action-title">{{ __('session_show.actions.start_gateway') }}</div>

            <button class="button start" type="submit">
                {{ __('session_show.actions.start_session') }}
            </button>
        </form>
    @endif

    @if ($canTerminate)
        <form
            class="action-block"
            method="POST"
            action="{{ route('admin.sessions.terminate', $session) }}"
            onsubmit="return confirm('{{ __('session_show.actions.confirm_terminate') }}');"
        >
            @csrf

            <div class="action-title">
                {{ __('session_show.actions.terminate_revoke') }}
            </div>

            <input
                name="reason"
                type="text"
                minlength="3"
                maxlength="255"
                placeholder="{{ __('session_show.actions.reason_placeholder') }}"
                required
            >

            <div class="mt-9">
                <button class="button terminate" type="submit">
                    {{ __('session_show.actions.terminate_session') }}
                </button>
            </div>
        </form>
    @endif

    @if (! $canStart && ! $canTerminate && ! $hasPendingOperation)
        <span class="muted">{{ __('session_show.actions.unavailable') }}</span>
    @endif

    @if ($hasPendingOperation)
        <span class="muted">{{ __('session_show.actions.pending') }}</span>
    @endif
</section>

<section class="section">
    <h2 class="section-title">{{ __('session_show.operations.heading') }}</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('session_show.operations.columns.operation') }}</th>
                    <th>{{ __('session_show.operations.columns.type') }}</th>
                    <th>{{ __('session_show.operations.columns.status') }}</th>
                    <th>{{ __('session_show.operations.columns.requested_by') }}</th>
                    <th>{{ __('session_show.operations.columns.reason') }}</th>
                    <th>{{ __('session_show.operations.columns.requested') }}</th>
                    <th>{{ __('session_show.operations.columns.completed') }}</th>
                    <th>{{ __('session_show.operations.columns.error') }}</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($operations as $operation)
                    <tr>
                        <td><code>{{ $operation->operation_uid }}</code></td>

                        <td>
                            <span class="badge">
                                {{ $operationTypeLabels[$operation->operation_type]
                                    ?? $operation->operation_type }}
                            </span>
                        </td>

                        <td>
                            <span class="badge {{
                                match ($operation->status) {
                                    'succeeded' => 'green',
                                    'queued', 'running' => 'yellow',
                                    'failed' => 'red',
                                    default => '',
                                }
                            }}">
                                {{ $operationStatusLabels[$operation->status]
                                    ?? $operation->status }}
                            </span>
                        </td>

                        <td>{{ $operation->requestedBy?->name ?? __('session_show.common.system') }}</td>
                        <td>{{ $operation->reason ?? $notAvailable }}</td>
                        <td>{{ $operation->requested_at?->format('d.m.Y H:i:s') ?? $notAvailable }}</td>
                        <td>{{ $operation->completed_at?->format('d.m.Y H:i:s') ?? $notAvailable }}</td>
                        <td class="muted">{{ $operation->error_message ?? $notAvailable }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="muted">
                            {{ __('session_show.operations.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="section">
    <h2 class="section-title">{{ __('session_show.connections.heading') }}</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('session_show.connections.columns.connection') }}</th>
                    <th>{{ __('session_show.connections.columns.client') }}</th>
                    <th>{{ __('session_show.connections.columns.db_user') }}</th>
                    <th>{{ __('session_show.connections.columns.status') }}</th>
                    <th>{{ __('session_show.connections.columns.opened') }}</th>
                    <th>{{ __('session_show.connections.columns.closed') }}</th>
                    <th>{{ __('session_show.connections.columns.reason') }}</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($connections as $connection)
                    <tr>
                        <td><code>{{ $connection->public_id }}</code></td>
                        <td>{{ $connection->client_address }}:{{ $connection->client_port }}</td>
                        <td>{{ $connection->db_username ?? $notAvailable }}</td>
                        <td>
                            <span class="badge">
                                {{ $connectionStatusLabels[$connection->status]
                                    ?? $connection->status }}
                            </span>
                        </td>
                        <td>{{ $connection->opened_at?->format('d.m.Y H:i:s') ?? $notAvailable }}</td>
                        <td>{{ $connection->closed_at?->format('d.m.Y H:i:s') ?? $notAvailable }}</td>
                        <td>{{ $connection->close_reason ?? $notAvailable }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">
                            {{ __('session_show.connections.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="section">
    <h2 class="section-title">{{ __('session_show.sql_audit.heading') }}</h2>

    <div class="risk-summary-grid">
        @foreach ([
            'all' => [
                'label' => $riskLabels['all'],
                'count' => $riskSummary['total'],
                'class' => '',
            ],
            'critical' => [
                'label' => $riskLabels['critical'],
                'count' => $riskSummary['critical'],
                'class' => 'red',
            ],
            'high' => [
                'label' => $riskLabels['high'],
                'count' => $riskSummary['high'],
                'class' => 'red',
            ],
            'medium' => [
                'label' => $riskLabels['medium'],
                'count' => $riskSummary['medium'],
                'class' => 'yellow',
            ],
            'low' => [
                'label' => $riskLabels['low'],
                'count' => $riskSummary['low'],
                'class' => 'green',
            ],
        ] as $filter => $summary)
            <a
                href="{{ route('admin.sessions.show', [
                    'session' => $session,
                    'risk' => $filter,
                ]) }}"
                class="card risk-summary-card {{ $riskFilter === $filter ? 'is-active' : '' }}"
            >
                <div class="muted">{{ $summary['label'] }}</div>

                <div class="risk-summary-value">
                    {{ $summary['count'] }}
                </div>

                @if ($summary['class'] !== '')
                    <div class="mt-8">
                        <span class="badge {{ $summary['class'] }}">
                            {{ $summary['label'] }}
                        </span>
                    </div>
                @endif
            </a>
        @endforeach
    </div>

    <p class="muted mb-12">{{ __('session_show.sql_audit.sorting') }}</p>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('session_show.sql_audit.columns.query') }}</th>
                    <th>{{ __('session_show.sql_audit.columns.type') }}</th>
                    <th>{{ __('session_show.sql_audit.columns.risk') }}</th>
                    <th>{{ __('session_show.sql_audit.columns.status') }}</th>
                    <th>{{ __('session_show.sql_audit.columns.duration') }}</th>
                    <th>{{ __('session_show.sql_audit.columns.ended') }}</th>
                    <th>{{ __('session_show.sql_audit.columns.sql') }}</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($queries as $query)
                    @php
                        $riskBadgeClass = match ($query->risk_level) {
                            'critical', 'high' => 'red',
                            'medium' => 'yellow',
                            'low' => 'green',
                            default => '',
                        };
                    @endphp

                    <tr>
                        <td><code>{{ $query->query_uid }}</code></td>
                        <td><span class="badge">{{ $query->statement_type }}</span></td>

                        <td>
                            <span class="badge {{ $riskBadgeClass }}">
                                {{ $riskLabels[$query->risk_level] ?? $query->risk_level }}
                            </span>

                            @if ($query->risk_reason)
                                <br><span class="muted">{{ $query->risk_reason }}</span>
                            @endif
                        </td>

                        <td>
                            <span class="badge">
                                {{ $queryStatusLabels[$query->query_status]
                                    ?? $query->query_status }}
                            </span>
                        </td>

                        <td>
                            {{ $query->duration_ms !== null
                                ? $query->duration_ms.' ms'
                                : $notAvailable }}
                        </td>

                        <td>{{ $query->ended_at?->format('d.m.Y H:i:s') ?? $notAvailable }}</td>

                        <td>
                            <code title="{{ $query->sql_text }}">
                                {{ \Illuminate\Support\Str::limit((string) $query->sql_text, 180) }}
                            </code>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">
                            {{ __('session_show.sql_audit.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="section">
    <h2 class="section-title">{{ __('session_show.audit.heading') }}</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('session_show.audit.columns.time') }}</th>
                    <th>{{ __('session_show.audit.columns.event') }}</th>
                    <th>{{ __('session_show.audit.columns.severity') }}</th>
                    <th>{{ __('session_show.audit.columns.actor') }}</th>
                    <th>{{ __('session_show.audit.columns.ip') }}</th>
                    <th>{{ __('session_show.audit.columns.data') }}</th>
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

                        <td>{{ $event->actor?->name ?? __('session_show.common.system') }}</td>
                        <td>{{ $event->ip_address ?? $notAvailable }}</td>

                        <td>
                            <code title="{{ json_encode($event->event_data) }}">
                                {{ \Illuminate\Support\Str::limit(
                                    json_encode(
                                        $event->event_data,
                                        JSON_UNESCAPED_UNICODE
                                    ),
                                    180
                                ) }}
                            </code>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="muted">
                            {{ __('session_show.audit.empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
