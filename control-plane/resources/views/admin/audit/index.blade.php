@extends('layouts.app')

@section('title', __('ce.nav.audit'))

@section('content')
<h1 class="page-title">{{ __('ce.nav.audit') }}</h1>
<p class="subtitle">{{ auth()->user()->isSuperAdmin() ? 'Усі зареєстровані дії.' : 'Ваші зареєстровані дії.' }}</p>
<div class="card table-wrap"><table>
<thead><tr><th>Час</th><th>Користувач</th><th>Подія</th><th>Категорія</th><th>IP</th><th>Дані</th></tr></thead>
<tbody>
@forelse($events as $event)
<tr>
<td>{{ app(\App\Services\Settings\DisplayClock::class)->format($event->occurred_at) }}</td>
<td>{{ $event->actor?->email ?? 'Система' }}</td>
<td>{{ $event->event_type }}</td>
<td>{{ $event->category }}</td>
<td>{{ $event->ip_address }}</td>
<td><code>{{ \Illuminate\Support\Str::limit(json_encode($event->event_data, JSON_UNESCAPED_UNICODE), 200) }}</code></td>
</tr>
@empty
<tr><td colspan="6">Подій немає.</td></tr>
@endforelse
</tbody></table></div>
{{ $events->links() }}
@endsection
