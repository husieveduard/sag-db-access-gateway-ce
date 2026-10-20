@extends('layouts.app')
@section('title', 'Alert Health')
@section('content')
<h1 class="page-title">Alert Health & Channels</h1>
<div class="card"><p>Audit backlog: {{ $lag }} events</p>
<p>Pending deliveries: {{ $pending }}</p><p>Failed deliveries: {{ $failed }}</p>
<p>Last processor update: {{ $lastRun ?? '—' }}</p></div>
<div class="card table-wrap"><table><thead><tr><th>Channel</th><th>Configured</th><th>Latest test</th><th>Action</th></tr></thead>
<tbody>@foreach($channels as $entry)
<tr><td>{{ $entry['name'] }}</td><td>{{ $entry['configured'] ? 'Yes' : 'No' }}</td>
<td>{{ $entry['last']?->status ?? 'Never' }}</td><td>
@if($entry['configured'])<form method="POST" action="{{ route('admin.alert-health.test') }}">
@csrf<input type="hidden" name="channel" value="{{ $entry['name'] }}">
<button class="button" type="submit">Send test</button></form>@endif
</td></tr>
@endforeach</tbody></table></div>
@endsection
