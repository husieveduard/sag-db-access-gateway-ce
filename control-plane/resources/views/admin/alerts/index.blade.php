@extends('layouts.app')
@section('title', __('alerts.nav'))
@section('content')
<h1 class="page-title">{{ __('alerts.nav') }}</h1>
<p class="subtitle">{{ __('alerts.description') }}</p>
<form method="GET" action="{{ route('admin.alerts.index') }}">
<label for="status">{{ __('alerts.status') }}</label>
<select id="status" name="status">
<option value="">{{ __('alerts.all') }}</option>
@foreach(['new','acknowledged','investigating','resolved'] as $value)
<option value="{{ $value }}" @selected(request('status') === $value)>{{ __('alerts.states.'.$value) }}</option>
@endforeach
</select>
<button class="button" type="submit">{{ __('alerts.filter') }}</button>
</form>
<div class="card table-wrap"><table><thead><tr>
<th>ID</th><th>{{ __('alerts.rule') }}</th><th>{{ __('alerts.severity') }}</th>
<th>{{ __('alerts.resource') }}</th><th>{{ __('alerts.owner') }}</th>
<th>{{ __('alerts.count') }}</th><th>{{ __('alerts.status') }}</th><th>{{ __('alerts.actions') }}</th>
</tr></thead><tbody>
@forelse($alerts as $alert)
<tr>
<td>#{{ $alert->id }}</td>
<td>{{ $alert->rule?->title }}</td>
<td><span class="badge">{{ $alert->severity }}</span></td>
<td>{{ $alert->resource?->name ?? '—' }}</td>
<td>{{ $alert->owner?->email ?? '—' }}</td>
<td>{{ $alert->occurrence_count }} · {{ $alert->last_seen_at?->format('d.m.Y H:i:s') }}</td>
<td>{{ __('alerts.states.'.$alert->status) }}</td>
<td>
@if($alert->status !== 'resolved')
<form method="POST" action="{{ route('admin.alerts.status',$alert) }}">@csrf
<select name="status">
@foreach(['acknowledged','investigating','resolved'] as $value)
<option value="{{ $value }}">{{ __('alerts.states.'.$value) }}</option>
@endforeach
</select><button type="submit" class="button">{{ __('alerts.save') }}</button>
</form>
@endif
</td></tr>
@empty
<tr><td colspan="8">{{ __('alerts.empty') }}</td></tr>
@endforelse
</tbody></table></div>
{{ $alerts->links() }}
@endsection
