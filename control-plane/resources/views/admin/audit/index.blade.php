@extends('layouts.app')

@section('title', __('ce.nav.audit'))

@section('content')
@php
    $clock = app(\App\Services\Settings\DisplayClock::class);
    $presenter = app(\App\Services\Audit\SafeAuditPayload::class);
@endphp
<div class="ce-audit-page">
    <div class="page-head">
        <div>
            <h1 class="page-title">{{ __('ce.nav.audit') }}</h1>
            <p class="subtitle">{{ auth()->user()->isSuperAdmin() ? __('audit.description_all') : __('audit.description_own') }}</p>
        </div>
        <span class="ce-alert-count">{{ $events->total() }} {{ __('audit.records') }}</span>
    </div>

    <form method="GET" action="{{ route('admin.audit.index') }}" class="ce-audit-filters">
        <div class="ce-audit-field ce-audit-search">
            <label for="audit-q">{{ __('audit.search') }}</label>
            <input id="audit-q" name="q" type="search" value="{{ request('q') }}" maxlength="120"
                placeholder="{{ __('audit.search_placeholder') }}">
        </div>
        <div class="ce-audit-field">
            <label for="audit-from">{{ __('audit.from') }}</label>
            <input id="audit-from" name="from" type="date" value="{{ request('from') }}">
        </div>
        <div class="ce-audit-field">
            <label for="audit-to">{{ __('audit.to') }}</label>
            <input id="audit-to" name="to" type="date" value="{{ request('to') }}">
        </div>
        <div class="ce-audit-field">
            <label for="audit-category">{{ __('audit.category') }}</label>
            <select id="audit-category" name="category">
                <option value="">{{ __('audit.all') }}</option>
                @foreach($categories as $category)
                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </div>
        <div class="ce-audit-field">
            <label for="audit-event-type">{{ __('audit.event_type') }}</label>
            <select id="audit-event-type" name="event_type">
                <option value="">{{ __('audit.all') }}</option>
                @foreach($eventTypes as $type)
                    <option value="{{ $type }}" @selected(request('event_type') === $type)>{{ $type }}</option>
                @endforeach
            </select>
        </div>
        @if(auth()->user()->isSuperAdmin())
            <div class="ce-audit-field">
                <label for="audit-user">{{ __('audit.user') }}</label>
                <input id="audit-user" name="user" type="text" value="{{ request('user') }}" maxlength="255"
                    placeholder="name@example.com">
            </div>
        @endif
        <div class="ce-audit-field">
            <label for="audit-ip">{{ __('audit.ip') }}</label>
            <input id="audit-ip" name="ip" type="text" value="{{ request('ip') }}" placeholder="192.0.2.1">
        </div>
        <div class="ce-audit-filter-actions">
            <button class="button primary" type="submit">{{ __('audit.filter') }}</button>
            <a class="button" href="{{ route('admin.audit.index') }}">{{ __('audit.reset') }}</a>
        </div>
    </form>

    <p class="ce-audit-zone">{{ __('audit.timezone') }}: {{ $clock->timezone() }} · {{ __('audit.readonly_note') }}</p>

    <div class="card table-wrap ce-audit-table">
        <table>
            <thead><tr>
                <th>ID</th>
                <th>{{ __('audit.time') }}</th>
                <th>{{ __('audit.user') }}</th>
                <th>{{ __('audit.event_type') }}</th>
                <th>{{ __('audit.category') }}</th>
                <th>{{ __('audit.severity') }}</th>
                <th>{{ __('audit.ip') }}</th>
                <th>{{ __('audit.details') }}</th>
            </tr></thead>
            <tbody>
                @forelse($events as $event)
                <tr id="audit-{{ $event->id }}">
                    <td><code class="ce-audit-id">#{{ $event->id }}</code></td>
                    <td class="ce-audit-time">{{ $clock->format($event->occurred_at) }}</td>
                    <td class="ce-audit-actor">{{ $event->actor?->email ?? __('audit.system') }}</td>
                    <td><code class="ce-audit-event-code">{{ $event->event_type }}</code></td>
                    <td>{{ $event->category }}</td>
                    <td>
                        <span class="badge {{ in_array($event->severity, ['critical','high'], true) ? 'red' : ($event->severity === 'medium' ? 'yellow' : '') }}">
                            {{ __('ce.common.'.$event->severity) }}
                        </span>
                    </td>
                    <td><code class="ce-audit-ip">{{ $event->ip_address ?? '—' }}</code></td>
                    <td>
                        <button class="button ce-audit-open" type="button"
                            data-ce-audit-open="audit-payload-{{ $event->id }}"
                            data-ce-audit-title="#{{ $event->id }} · {{ $event->event_type }}"
                            data-ce-audit-time="{{ $clock->format($event->occurred_at) }}">
                            {{ __('audit.view') }}
                        </button>
                        <template id="audit-payload-{{ $event->id }}">{{ $presenter->json($event->event_data ?? []) }}</template>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="ce-audit-empty">{{ __('audit.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="ce-audit-pagination">{{ $events->links() }}</div>
</div>

<dialog id="ce-audit-dialog" class="ce-audit-dialog" aria-labelledby="ce-audit-dialog-title">
    <div class="ce-audit-dialog-top">
        <div>
            <h2 id="ce-audit-dialog-title">{{ __('audit.details') }}</h2>
            <p id="ce-audit-dialog-time" class="muted"></p>
        </div>
        <form method="dialog">
            <button class="ce-audit-close" type="submit" aria-label="{{ __('audit.close') }}">✕</button>
        </form>
    </div>
    <div class="ce-audit-dialog-body">
        <div class="ce-audit-json-header">
            <strong>{{ __('audit.safe_json') }}</strong>
            <button class="button" id="ce-audit-copy" type="button"
                data-label-copied="{{ __('ce.sidebar.copied') }}"
                data-label-failed="{{ __('ce.sidebar.copy_failed') }}">
                {{ __('audit.copy') }}
            </button>
        </div>
        <pre id="ce-audit-json" tabindex="0"></pre>
        <p class="ce-audit-redaction-note">{{ __('audit.redaction') }}</p>
    </div>
</dialog>
@endsection
