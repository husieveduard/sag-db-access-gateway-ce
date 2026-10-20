@extends('layouts.app')

@section('title', __('ce.nav.administrators'))

@section('content')
<h1 class="page-title">{{ __('ce.nav.administrators') }}</h1>
<p class="subtitle">Призначати суперадміністраторів можна серед локальних і доменних адміністраторів, які вже увійшли в систему.</p>
<div class="card table-wrap">
<table><thead><tr><th>ID</th><th>Email</th><th>Тип входу</th><th>Роль</th><th>Стан</th><th>Дія</th></tr></thead><tbody>
@foreach ($users as $user)
<tr>
<td>{{ $user->id }}</td>
<td>{{ $user->name }}<br><small>{{ $user->email }}</small></td>
<td>{{ $user->auth_source }}</td>
<td>{{ $user->role }}</td>
<td>{{ $user->is_active ? 'Активний' : 'Вимкнений' }}</td>
<td>
@if ($user->role === 'superadmin' && \App\Models\User::query()->where('role', 'superadmin')->where('is_active', true)->count() <= 1 && $user->is_active)
    Останній активний суперадмін
@else
<form method="POST" action="{{ route('admin.administrators.role', $user) }}">
@csrf
<select name="role">
<option value="admin" @selected($user->role === 'admin')>Адміністратор</option>
<option value="superadmin" @selected($user->role === 'superadmin')>Суперадміністратор</option>
</select>
<button class="button" type="submit">Зберегти</button>
</form>
@endif
</td>
</tr>
@endforeach
</tbody></table>
</div>
{{ $users->links() }}
@endsection
