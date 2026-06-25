@extends('layouts.app')

@section('title', 'Sessions · SAG DB Access Gateway CE')

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
    .filters {
        display: flex;
        gap: 12px;
        align-items: end;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    label {
        display: block;
        margin-bottom: 6px;
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
    }
    select,
    .filter-button {
        min-height: 38px;
        padding: 8px 10px;
        color: var(--text);
        background: #0d1726;
        border: 1px solid var(--line);
        border-radius: 8px;
    }
    .filter-button { cursor: pointer; }
    .filter-button:hover { border-color: var(--blue); }
    .session-link { color: var(--blue); }
    .endpoint {
        color: #c6d7eb;
        font-family: ui-monospace, Consolas, monospace;
    }
    .pagination {
        display: flex;
        gap: 12px;
        align-items: center;
        justify-content: space-between;
        margin-top: 16px;
        color: var(--muted);
    }
    .pagination a {
        padding: 8px 11px;
        border: 1px solid var(--line);
        border-radius: 8px;
        color: var(--text);
    }
</style>
@endpush

@section('content')
<div class="page-head">
    <div>
        <h1 class="page-title">Gateway Sessions</h1>
        <p class="subtitle">
            Створені temporary та persistent DB access sessions.
        </p>
    </div>

    <a class="button primary" href="{{ route('admin.sessions.create') }}">
        + Створити session
    </a>
</div>

<form class="filters" method="GET" action="{{ route('admin.sessions.index') }}">
    <div>
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Усі</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>
                    {{ $status }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="mode">Mode</label>
        <select id="mode" name="mode">
            <option value="">Усі</option>
            @foreach ($modes as $mode)
                <option value="{{ $mode }}" @selected(request('mode') === $mode)>
                    {{ $mode }}
                </option>
            @endforeach
        </select>
    </div>

    <button class="filter-button" type="submit">Фільтрувати</button>
    <a class="filter-button" href="{{ route('admin.sessions.index') }}">Скинути</a>
</form>

<div class="card table-wrap">
    <table>
        <thead>
            <tr>
                <th>Session</th>
                <th>Resource</th>
                <th>Owner</th>
                <th>Mode / status</th>
                <th>Gateway</th>
                <th>Source CIDR</th>
                <th>Connections</th>
                <th>Operations</th>
                <th>TTL / завершення</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($sessions as $session)
                @php
                    $statusClass = match ($session->status) {
                        'started' => 'green',
                        'starting', 'created' => 'yellow',
                        'terminated', 'expired', 'failed', 'ended' => 'red',
                        default => '',
                    };
                @endphp
                <tr>
                    <td>
                        <a
                            class="session-link"
                            href="{{ route('admin.sessions.show', $session) }}"
                        >
                            <code title="{{ $session->public_id }}">{{ $session->public_id }}</code>
                        </a>
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
                    <td>
                        @if ($session->pending_operations_count)
                            <span class="badge yellow">
                                pending: {{ $session->pending_operations_count }}
                            </span>
                        @elseif ($session->last_failed_operation_uid)
                            <span class="badge red">failed</span>

                            <div
                                class="muted"
                                title="{{ $session->last_failed_operation_error }}"
                            >
                                <code>{{ $session->last_failed_operation_uid }}</code><br>

                                {{ \Illuminate\Support\Str::limit(
                                    $session->last_failed_operation_error
                                        ?: 'Operation failed without error text.',
                                    90
                                ) }}
                            </div>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if ($session->mode === 'temporary')
                            {{ $session->expires_at?->format('d.m.Y H:i:s') ?? '—' }}
                        @else
                            {{ $session->ended_at?->format('d.m.Y H:i:s') ?? 'manual lifecycle' }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="muted">DB sessions ще не створювались.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($sessions->hasPages())
    <div class="pagination">
        <span>
            Показано {{ $sessions->firstItem() }}–{{ $sessions->lastItem() }}
            з {{ $sessions->total() }}
        </span>

        <span>
            @if ($sessions->onFirstPage())
                <span class="muted">← Попередня</span>
            @else
                <a href="{{ $sessions->previousPageUrl() }}">← Попередня</a>
            @endif

            @if ($sessions->hasMorePages())
                <a href="{{ $sessions->nextPageUrl() }}">Наступна →</a>
            @else
                <span class="muted">Наступна →</span>
            @endif
        </span>
    </div>
@endif
@endsection
