@extends('layouts.app')
@section('title', __('alerts.nav'))
@section('content')
<div class="ce-alerts-page">
    <div class="page-head">
        <div><h1 class="page-title">{{ __('alerts.nav') }}</h1><p class="subtitle">{{ __('alerts.description') }}</p></div>
        <span class="ce-alert-count">{{ $alerts->total() }} {{ __('alerts.total') }}</span>
    </div>
    <form class="ce-alert-filters" method="GET" action="{{ route('admin.alerts.index') }}">
        <div><label for="status">{{ __('alerts.status') }}</label>
        <select id="status" name="status"><option value="">{{ __('alerts.all') }}</option>
        @foreach(['new','acknowledged','investigating','resolved'] as $value)
        <option value="{{ $value }}" @selected(request('status') === $value)>{{ __('alerts.states.'.$value) }}</option>
        @endforeach</select></div>
        <button class="button primary" type="submit">{{ __('alerts.filter') }}</button>
        @if(request()->filled('status'))<a class="button" href="{{ route('admin.alerts.index') }}">{{ __('alerts.reset') }}</a>@endif
    </form>
    <div class="card table-wrap ce-alert-table"><table>
    <thead><tr>
        <th>ID</th><th>{{ __('alerts.rule') }}</th><th>{{ __('alerts.severity') }}</th>
        <th>{{ __('alerts.resource') }}</th><th>{{ __('alerts.owner') }}</th>
        <th>{{ __('alerts.count') }}</th><th>{{ __('alerts.status') }}</th><th>{{ __('alerts.actions') }}</th>
    </tr></thead>
    <tbody>
        @forelse($alerts as $alert)
        @php
            $severityClass = in_array($alert->severity, ['critical','high'], true) ? 'red' : ($alert->severity === 'medium' ? 'yellow' : '');
            $statusClass = match ($alert->status) { 'new' => 'yellow', 'resolved' => 'green', default => '' };
        @endphp
        <tr id="alert-{{ $alert->id }}">
            <td><span class="ce-alert-identifier">#{{ $alert->id }}</span></td>
            <td><strong>{{ $alert->rule?->title }}</strong></td>
            <td><span class="badge {{ $severityClass }}">{{ __('ce.common.'.$alert->severity) }}</span></td>
            <td>{{ $alert->resource?->name ?? '—' }}</td>
            <td><span class="ce-alert-email">{{ $alert->owner?->email ?? '—' }}</span></td>
            <td><strong>{{ $alert->occurrence_count }}</strong>
                <small class="ce-alert-subtime">{{ app(\App\Services\Settings\DisplayClock::class)->format($alert->last_seen_at) }}</small>
            </td>
            <td><span class="badge {{ $statusClass }}">{{ __('alerts.states.'.$alert->status) }}</span></td>
            <td>
                @if($alert->status !== 'resolved')
                <form method="POST" class="ce-alert-action" action="{{ route('admin.alerts.status', $alert) }}">
                    @csrf
                    <select name="status" aria-label="{{ __('alerts.status') }}">
                        @foreach(['acknowledged','investigating','resolved'] as $value)
                            <option value="{{ $value }}" @selected(($alert->status === 'new' ? 'acknowledged' : $alert->status) === $value)>{{ __('alerts.states.'.$value) }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="button primary">{{ __('alerts.save') }}</button>
                </form>
                @else <span class="muted">—</span> @endif
            </td>
        </tr>
        @empty
        <tr><td colspan="8" class="muted">{{ __('alerts.empty') }}</td></tr>
        @endforelse
    </tbody></table></div>
    {{ $alerts->links() }}
</div>
@endsection
