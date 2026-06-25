@extends('layouts.app')

@section('title', 'Session · SAG DB Access Gateway CE')

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
    .section { margin-top: 24px; }
    .section-title { margin: 0 0 11px; font-size: 18px; }
    .actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: end;
        padding: 18px;
    }
    .action-block { min-width: 270px; }
    .action-title { margin-bottom: 9px; font-weight: 750; }
    input {
        width: 100%;
        min-height: 38px;
        padding: 8px 10px;
        color: var(--text);
        background: #0d1726;
        border: 1px solid var(--line);
        border-radius: 8px;
    }
    .button {
        min-height: 38px;
        padding: 8px 12px;
        border: 1px solid var(--line);
        border-radius: 8px;
        color: var(--text);
        background: transparent;
        cursor: pointer;
        font-weight: 700;
    }
    .button:hover { border-color: var(--blue); }
    .button.start {
        border-color: rgba(54,198,145,.45);
        color: var(--green);
    }
    .button.terminate {
        border-color: rgba(243,107,107,.5);
        color: var(--red);
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
        && !$hasPendingOperation;

    $canTerminate = !in_array(
        $session->status,
        ['terminated', 'expired', 'ended'],
        true
    ) && !$hasPendingOperation;
@endphp

<a class="back-link" href="{{ route('admin.sessions.index') }}">← До списку sessions</a>

<h1 class="page-title">
    <code title="{{ $session->public_id }}">{{ $session->public_id }}</code>
</h1>

<p class="subtitle">
    <span class="badge">{{ $session->mode }}</span>
    <span class="badge {{ $statusClass }}">{{ $session->status }}</span>

    @if ($hasPendingOperation)
        <span class="badge yellow">operation pending</span>
    @endif
</p>

<section class="summary">
    <div class="card summary-card">
        <div class="summary-label">Resource</div>
        <div class="summary-value">
            {{ $session->resource?->name ?? '—' }}<br>
            <span class="muted">
                {{ $session->resource?->engine ?? '—' }} ·
                {{ $session->resource?->target_host ?? '—' }}:{{ $session->resource?->target_port ?? '—' }}
            </span>
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">Owner / DB user</div>
        <div class="summary-value">
            {{ $session->owner?->name ?? '—' }}<br>
            <span class="muted">{{ $session->target_db_username ?? '—' }}</span>
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">Gateway endpoint</div>
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
                    <span class="badge red">released</span><br>
                    <span class="muted">
                        {{ $session->gateway_host }}:{{ $session->gateway_port }}
                        · historical endpoint
                    </span>
                @else
                    {{ $session->gateway_host }}:{{ $session->gateway_port }}
                @endif
            @else
                not started
            @endif
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">Source CIDR</div>
        <div class="summary-value">{{ $session->allowed_source_cidr ?? '—' }}</div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">TTL / expiry</div>
        <div class="summary-value">
            {{ $session->expires_at?->format('d.m.Y H:i:s') ?? 'persistent / service' }}
        </div>
    </div>

    <div class="card summary-card">
        <div class="summary-label">Lifecycle</div>
        <div class="summary-value">
            Start: {{ $session->started_at?->format('d.m.Y H:i:s') ?? '—' }}<br>
            End: {{ $session->ended_at?->format('d.m.Y H:i:s') ?? '—' }}
        </div>
    </div>
</section>

<section class="card actions">
    @if ($canStart)
        <form
            method="POST"
            action="{{ route('admin.sessions.start', $session) }}"
            onsubmit="return confirm('Поставити запуск gateway у чергу?');"
        >
            @csrf
            <div class="action-title">Start gateway</div>
            <button class="button start" type="submit">Start session</button>
        </form>
    @endif

    @if ($canTerminate)
        <form
            class="action-block"
            method="POST"
            action="{{ route('admin.sessions.terminate', $session) }}"
            onsubmit="return confirm('Завершити DB session і примусово розірвати активні підключення?');"
        >
            @csrf
            <div class="action-title">Terminate / revoke</div>
            <input
                name="reason"
                type="text"
                minlength="3"
                maxlength="255"
                placeholder="Причина завершення"
                required
            >
            <div style="margin-top: 9px">
                <button class="button terminate" type="submit">Terminate session</button>
            </div>
        </form>
    @endif

    @if (!$canStart && !$canTerminate && !$hasPendingOperation)
        <span class="muted">Для цього статусу lifecycle-дії недоступні.</span>
    @endif

    @if ($hasPendingOperation)
        <span class="muted">
            Операція вже поставлена в чергу. Root-worker виконає її автоматично.
        </span>
    @endif
</section>

<section class="section">
    <h2 class="section-title">Lifecycle operations</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Operation</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Requested by</th>
                    <th>Reason</th>
                    <th>Requested</th>
                    <th>Completed</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($operations as $operation)
                    <tr>
                        <td><code>{{ $operation->operation_uid }}</code></td>
                        <td><span class="badge">{{ $operation->operation_type }}</span></td>
                        <td>
                            <span class="badge {{
                                match ($operation->status) {
                                    'succeeded' => 'green',
                                    'queued', 'running' => 'yellow',
                                    'failed' => 'red',
                                    default => '',
                                }
                            }}">{{ $operation->status }}</span>
                        </td>
                        <td>{{ $operation->requestedBy?->name ?? 'system' }}</td>
                        <td>{{ $operation->reason ?? '—' }}</td>
                        <td>{{ $operation->requested_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                        <td>{{ $operation->completed_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                        <td class="muted">{{ $operation->error_message ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="muted">Lifecycle operations відсутні.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="section">
    <h2 class="section-title">DB connections</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Connection</th>
                    <th>Client</th>
                    <th>DB user</th>
                    <th>Status</th>
                    <th>Opened</th>
                    <th>Closed</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($connections as $connection)
                    <tr>
                        <td><code>{{ $connection->public_id }}</code></td>
                        <td>{{ $connection->client_address }}:{{ $connection->client_port }}</td>
                        <td>{{ $connection->db_username ?? '—' }}</td>
                        <td><span class="badge">{{ $connection->status }}</span></td>
                        <td>{{ $connection->opened_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                        <td>{{ $connection->closed_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                        <td>{{ $connection->close_reason ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">DB connections відсутні.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="section">
    <h2 class="section-title">SQL audit</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Query</th>
                    <th>Type</th>
                    <th>Risk</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>Ended</th>
                    <th>SQL</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($queries as $query)
                    <tr>
                        <td><code>{{ $query->query_uid }}</code></td>
                        <td><span class="badge">{{ $query->statement_type }}</span></td>
                        <td>
                            <span class="badge {{
                                match ($query->risk_level) {
                                    'high' => 'red',
                                    'medium' => 'yellow',
                                    'low' => 'green',
                                    default => '',
                                }
                            }}">{{ $query->risk_level }}</span>
                            @if ($query->risk_reason)
                                <br><span class="muted">{{ $query->risk_reason }}</span>
                            @endif
                        </td>
                        <td><span class="badge">{{ $query->query_status }}</span></td>
                        <td>{{ $query->duration_ms !== null ? $query->duration_ms.' ms' : '—' }}</td>
                        <td>{{ $query->ended_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                        <td><code title="{{ $query->sql_text }}">{{ \Illuminate\Support\Str::limit((string) $query->sql_text, 180) }}</code></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">SQL audit events відсутні.</td>
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
                        <td><code title="{{ json_encode($event->event_data) }}">{{ \Illuminate\Support\Str::limit(json_encode($event->event_data, JSON_UNESCAPED_UNICODE), 180) }}</code></td>
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
