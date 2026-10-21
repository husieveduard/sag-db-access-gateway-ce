@extends('layouts.app')

@section('title', __('ce.nav.administrators'))

@section('content')
<div class="page-head">
    <div>
        <h1 class="page-title">{{ __('ce.nav.administrators') }}</h1>
        <p class="subtitle">Локальних адміністраторів можна створювати тут; доменні з’являються після першого успішного входу через AD.</p>
    </div>
    <a class="button primary" href="{{ route('admin.administrators.create') }}">+ Додати адміністратора</a>
</div>
<div class="card table-wrap">
    <table>
        <thead><tr><th>ID</th><th>Адміністратор</th><th>Тип входу</th><th>Роль</th><th>Стан</th><th>Дії</th></tr></thead>
        <tbody>
        @foreach ($users as $user)
            <tr>
                <td>{{ $user->id }}</td>
                <td>{{ $user->name }}<br><small>{{ $user->email }}</small></td>
                <td>{{ $user->auth_source === 'ldap' ? 'Active Directory' : 'Локальний' }}</td>
                <td>{{ $user->role }}</td>
                <td>{{ $user->is_active ? 'Активний' : 'Вимкнений' }}</td>
                <td>
                    @if ($user->role === 'superadmin' && $user->is_active && $activeSuperadminCount <= 1)
                        <span class="muted">Останній активний суперадміністратор</span>
                    @else
                        <form method="POST" action="{{ route('admin.administrators.role', $user) }}" class="admin-action-form">
                            @csrf
                            <label for="role-{{ $user->id }}" class="sr-only">Роль</label>
                            <select id="role-{{ $user->id }}" name="role">
                                <option value="admin" @selected($user->role === 'admin')>Адміністратор</option>
                                <option value="superadmin" @selected($user->role === 'superadmin')>Суперадміністратор</option>
                            </select>
                            <button class="button" type="submit">Змінити роль</button>
                        </form>
                    @endif
                    @if ($user->id !== auth()->id() && !($user->role === 'superadmin' && $user->is_active && $activeSuperadminCount <= 1))
                        <form method="POST" action="{{ route('admin.administrators.status', $user) }}" class="admin-action-form">
                            @csrf
                            <input type="hidden" name="active" value="{{ $user->is_active ? '0' : '1' }}">
                            <button class="button" type="submit">{{ $user->is_active ? 'Вимкнути' : 'Активувати' }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
{{ $users->links() }}
@endsection
