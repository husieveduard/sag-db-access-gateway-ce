@extends('layouts.app')

@section('title', __('sessions.title').' · '.__('ce.app_title'))

@section('content')
<div class="page-head">
    <div>
        <h1 class="page-title">{{ __('sessions.heading') }}</h1>
        <p class="subtitle">{{ __('sessions.subtitle') }}</p>
    </div>

    <a class="button primary" href="{{ route('admin.sessions.create') }}">
        + {{ __('sessions.create') }}
    </a>
</div>

<form class="filters" method="GET" action="{{ route('admin.sessions.index') }}">
    <div>
        <label for="status">{{ __('sessions.filters.status') }}</label>
        <select id="status" name="status">
            <option value="">{{ __('sessions.filters.all') }}</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>
                    {{ __('sessions.status.'.$status) }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="mode">{{ __('sessions.filters.mode') }}</label>
        <select id="mode" name="mode">
            <option value="">{{ __('sessions.filters.all') }}</option>
            @foreach ($modes as $mode)
                <option value="{{ $mode }}" @selected(request('mode') === $mode)>
                    {{ __('sessions.mode.'.$mode) }}
                </option>
            @endforeach
        </select>
    </div>

    <button class="filter-button" type="submit">
        {{ __('sessions.filters.apply') }}
    </button>

    <a class="filter-button" href="{{ route('admin.sessions.index') }}">
        {{ __('sessions.filters.reset') }}
    </a>
</form>

<div class="card table-wrap">
    <table>
        <thead>
            <tr>
                <th>{{ __('sessions.columns.session') }}</th>
                <th>{{ __('sessions.columns.resource') }}</th>
                <th>{{ __('sessions.columns.owner') }}</th>
                <th>{{ __('sessions.columns.mode_status') }}</th>
                <th>{{ __('sessions.columns.gateway') }}</th>
                <th>{{ __('sessions.columns.source_cidr') }}</th>
                <th>{{ __('sessions.columns.connections') }}</th>
                <th>{{ __('sessions.columns.operations') }}</th>
                <th>{{ __('sessions.columns.lifecycle') }}</th>
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

                    $endpointReleased = in_array(
                        $session->status,
                        ['terminated', 'expired', 'ended', 'failed'],
                        true
                    );
                @endphp

                <tr>
                    <td>
                        <a
                            class="session-link"
                            href="{{ route('admin.sessions.show', $session) }}"
                        >
                            <code title="{{ $session->public_id }}">
                                {{ $session->public_id }}
                            </code>
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
                        <span class="badge">
                            {{ __('sessions.mode.'.$session->mode) }}
                        </span>

                        <span class="badge {{ $statusClass }}">
                            {{ __('sessions.status.'.$session->status) }}
                        </span>
                    </td>

                    <td class="endpoint">
                        @if ($session->gateway_host && $session->gateway_port)
                            @if ($endpointReleased)
                                <span class="badge red">
                                    {{ __('sessions.gateway.released') }}
                                </span><br>

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
                                {{ __('sessions.operations.pending', [
                                    'count' => $session->pending_operations_count,
                                ]) }}
                            </span>
                        @elseif ($session->last_failed_operation_uid)
                            <span class="badge red">
                                {{ __('sessions.operations.failed') }}
                            </span>

                            <div
                                class="muted"
                                title="{{ $session->last_failed_operation_error }}"
                            >
                                <code>{{ $session->last_failed_operation_uid }}</code><br>

                                {{ \Illuminate\Support\Str::limit(
                                    $session->last_failed_operation_error
                                        ?: __('sessions.operations.failed_without_text'),
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
                            {{ $session->ended_at?->format('d.m.Y H:i:s')
                                ?? __('sessions.lifecycle.manual') }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="muted">{{ __('sessions.empty') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($sessions->hasPages())
    <div class="pagination">
        <span>
            {{ __('sessions.pagination.showing', [
                'from' => $sessions->firstItem(),
                'to' => $sessions->lastItem(),
                'total' => $sessions->total(),
            ]) }}
        </span>

        <span>
            @if ($sessions->onFirstPage())
                <span class="muted">{{ __('sessions.pagination.previous') }}</span>
            @else
                <a href="{{ $sessions->previousPageUrl() }}">
                    {{ __('sessions.pagination.previous') }}
                </a>
            @endif

            @if ($sessions->hasMorePages())
                <a href="{{ $sessions->nextPageUrl() }}">
                    {{ __('sessions.pagination.next') }}
                </a>
            @else
                <span class="muted">{{ __('sessions.pagination.next') }}</span>
            @endif
        </span>
    </div>
@endif
@endsection
