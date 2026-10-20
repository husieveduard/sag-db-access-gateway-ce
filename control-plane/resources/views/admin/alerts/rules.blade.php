@extends('layouts.app')
@section('title', __('alerts.rules_nav'))
@section('content')
<h1 class="page-title">{{ __('alerts.rules_nav') }}</h1>
<p class="subtitle">{{ __('alerts.rules_description') }}</p>
<div class="card table-wrap"><table><thead><tr><th>{{ __('alerts.rule') }}</th><th>{{ __('alerts.event') }}</th><th>{{ __('alerts.actions') }}</th></tr></thead><tbody>
@foreach($rules as $rule)
<tr><td>{{ $rule->title }}</td><td><code>{{ $rule->event_type }}</code></td><td>
<form method="POST" action="{{ route('admin.alert-rules.update', $rule) }}">@csrf
<select name="enabled">
<option value="1" @selected($rule->enabled)>{{ __('alerts.enabled') }}</option>
<option value="0" @selected(!$rule->enabled)>{{ __('alerts.disabled') }}</option>
</select>
<select name="severity">
@foreach(['info','low','medium','high','critical'] as $level)
<option value="{{ $level }}" @selected($rule->severity === $level)>{{ $level }}</option>
@endforeach
</select>
<input type="number" name="cooldown_minutes" min="1" max="1440" value="{{ $rule->cooldown_minutes }}" required>
<button type="submit" class="button">{{ __('alerts.save') }}</button>
</form>
</td></tr>
@endforeach
</tbody></table></div>
@endsection
