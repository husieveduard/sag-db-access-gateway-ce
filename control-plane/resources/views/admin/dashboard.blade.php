@extends('layouts.app')

@section('title', 'Dashboard · SAG DB Access Gateway CE')

@push('styles')
<style>
    .stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .stat { padding: 18px; }
    .stat-label {
        color: var(--muted);
        font-size: 13px;
        font-weight: 700;
    }
    .stat-value {
        margin-top: 10px;
        font-size: 30px;
        font-weight: 800;
    }
    .section { margin-top: 24px; }
    .section-title {
        margin: 0 0 11px;
        font-size: 18px;
    }
    .endpoint { color: #c6d7eb; font-family: ui-monospace, Consolas, monospace; }
    @media (max-width: 1000px) {
        .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 540px) {
        .stats { grid-template-columns: 1fr; }
    }
</style>
@endpush

@section('content')
<h1 class="page-title">DB Access Dashboard</h1>
<p class="subtitle">
    Стан gateway sessions, DB connections та SQL audit. Час відображається у часовій зоні застосунку: {{ config('app.timezone') }}.
</p>

<section class="stats">
    <div class="card stat">
        <div class="stat-label">Активні gateway sessions</div>
        <div class="stat-value">{{ $stats['active_sessions'] }}</div>
    </div>

    <div class="card stat">
        <div class="stat-label">Активні DB resources</div>
        <div class="stat-value">{{ $stats['active_resources'] }}</div>
    </div>

    <div class="card stat">
        <div class="stat-label">Відкриті DB connections</div>
        <div class="stat-value">{{ $stats['open_connections'] }}</div>
    </div>

    <div class="card stat">
        <div class="stat-label">High-risk SQL за 24 год</div>
        <div class="stat-value">{{ $stats['high_risk_24h'] }}</div>
    </div>
</section>

<section class="section">
    <h2 class="section-title">Останні DB sessions</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Session</th>
                    <th>Resource</th>
                    <th>Owner</th>
                    <th>Mode / status</th>
                    <th>Gateway endpoint</th>
                    <th>Source CIDR</th>
                    <th>Open connections</th>
                    <th>TTL / завершення</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sessions as $session)
                    @php
                        $statusClass = match ($session->status) {
                            'started' => 'green',
                            'starting', 'created' => 'yellow',
                            'terminated', 'expired', 'failed' => 'red',
                            default => '',
                        };
                    @endphp
                    <tr>
                        <td>
                            <code title="{{ $session->public_id }}">{{ $session->public_id }}</code>
                        </td>
                        <td>
                            <strong>{{ $session->resource?->name ?? '—' }}</strong><br>
                            <span class="muted">{{ $session->resource?->engine ?? '—' }}</span>
                        </td>
                        <td>
                            {{ $session->owner?->name ?? '—' }}<br>
                            <span class="muted">{{ $session->owner?->email ?? '' }}</span>
                        </td>
                        <td>
                            <span class="badge">{{ $session->mode }}</span>
                            <span class="badge {{ $statusClass }}">{{ $session->status }}</span>
                        </td>
                        <td class="endpoint">
                            @if ($session->gateway_host && $session->gateway_port)
                                {{ $session->gateway_host }}:{{ $session->gateway_port }}
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $session->allowed_source_cidr ?? '—' }}</td>
                        <td>{{ $session->open_connections_count }}</td>
                        <td>
                            @if ($session->mode === 'temporary')
                                {{ $session->expires_at?->format('d.m.Y H:i:s') ?? '—' }}
                            @else
                                {{ $session->ended_at?->format('d.m.Y H:i:s') ?? 'active / manual revoke' }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="muted">DB sessions ще не створювались.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="section">
    <h2 class="section-title">Останній SQL audit</h2>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Час</th>
                    <th>Resource / session</th>
                    <th>Тип</th>
                    <th>Risk</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>SQL</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($queries as $query)
                    @php
                        $riskClass = match ($query->risk_level) {
                            'high' => 'red',
                            'medium' => 'yellow',
                            'low' => 'green',
                            default => '',
                        };
                    @endphp
                    <tr>
                        <td>{{ $query->occurred_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                        <td>
                            <strong>{{ $query->resource?->name ?? '—' }}</strong><br>
                            <code title="{{ $query->session?->public_id }}">{{ $query->session?->public_id ?? '—' }}</code>
                        </td>
                        <td><span class="badge">{{ $query->statement_type }}</span></td>
                        <td>
                            <span class="badge {{ $riskClass }}">{{ $query->risk_level }}</span>
                            @if ($query->risk_reason)
                                <br><span class="muted">{{ $query->risk_reason }}</span>
                            @endif
                        </td>
                        <td><span class="badge">{{ $query->query_status }}</span></td>
                        <td>{{ $query->duration_ms !== null ? $query->duration_ms.' ms' : '—' }}</td>
                        <td>
                            <code title="{{ $query->sql_text }}">
                                {{ \Illuminate\Support\Str::limit((string) $query->sql_text, 150) }}
                            </code>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="muted">SQL audit events ще відсутні.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
