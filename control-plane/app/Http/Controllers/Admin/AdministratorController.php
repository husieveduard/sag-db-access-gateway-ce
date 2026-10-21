<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AdministratorController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('admin.administrators.index', [
            'users' => User::query()->whereIn('role', ['admin', 'superadmin'])
                ->orderBy('name')->paginate(50),
            'activeSuperadminCount' => User::query()
                ->where('role', 'superadmin')->where('is_active', true)->count(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('admin.administrators.create');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $request->merge([
            'name' => trim((string) $request->input('name')),
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['admin', 'superadmin'])],
            'password' => ['required', 'string', 'max:255', 'confirmed',
                Password::min(14)->mixedCase()->numbers()],
        ]);

        DB::transaction(function () use ($request, $data): void {
            // All local CE administrators require MFA enrollment at first sign-in.
            $user = new User();
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'password' => Hash::make($data['password']),
                'auth_source' => 'local',
                'is_active' => true,
                'is_service_account' => false,
                'email_verified_at' => now(),
            ])->save();

            AuditEvent::query()->create([
                'actor_user_id' => $request->user()->id,
                'category' => 'authorization',
                'event_type' => 'auth.admin.created',
                'severity' => 'high',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'event_data' => [
                    'subject_user_id' => $user->id,
                    'subject_user_email' => $user->email,
                    'role' => $user->role,
                    'auth_source' => 'local',
                    'source' => 'web_console',
                    'mfa_required_on_first_login' => true,
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('admin.administrators.index')
            ->with('success', 'Адміністратора створено. Під час першого входу потрібно налаштувати MFA.');
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'superadmin'])],
        ]);

        abort_unless($user->isCeAdministrator(), 404);

        DB::transaction(function () use ($request, $user, $data): void {
            // Lock a common row so concurrent changes cannot demote all superadmins.
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
            $target = User::query()->whereKey($user->id)->firstOrFail();
            $oldRole = $target->role;
            $newRole = $data['role'];

            if ($oldRole === $newRole) {
                return;
            }

            if ($oldRole === 'superadmin' && $newRole === 'admin'
                && $target->is_active
                && User::query()->where('role', 'superadmin')->where('is_active', true)->count() <= 1) {
                abort(422, 'Cannot demote the last active super administrator.');
            }

            $target->forceFill(['role' => $newRole])->save();

            AuditEvent::query()->create([
                'actor_user_id' => $request->user()->id,
                'category' => 'authorization',
                'event_type' => 'auth.admin.role_changed',
                'severity' => 'high',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'event_data' => [
                    'subject_user_id' => $target->id,
                    'subject_email' => $target->email,
                    'old_role' => $oldRole,
                    'new_role' => $newRole,
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('admin.administrators.index')
            ->with('success', 'Роль адміністратора змінено.');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'active' => ['required', 'boolean'],
        ]);
        abort_unless($user->isCeAdministrator(), 404);

        DB::transaction(function () use ($request, $user, $data): void {
            // Use the same lock order as role updates to preserve break-glass access.
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
            $target = User::query()->whereKey($user->id)->firstOrFail();
            $newActive = (bool) $data['active'];

            if ($target->is_active === $newActive) {
                return;
            }

            if (!$newActive && $target->id === $request->user()->id) {
                abort(422, 'Cannot disable your own administrator account.');
            }

            if (!$newActive && $target->role === 'superadmin'
                && User::query()->where('role', 'superadmin')->where('is_active', true)->count() <= 1) {
                abort(422, 'Cannot disable the last active super administrator.');
            }

            $oldActive = $target->is_active;
            $target->forceFill(['is_active' => $newActive])->save();

            AuditEvent::query()->create([
                'actor_user_id' => $request->user()->id,
                'category' => 'authorization',
                'event_type' => 'auth.admin.status_changed',
                'severity' => 'high',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'event_data' => [
                    'subject_user_id' => $target->id,
                    'subject_email' => $target->email,
                    'old_active' => $oldActive,
                    'new_active' => $newActive,
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('admin.administrators.index')
            ->with('success', 'Стан адміністратора змінено.');
    }
}
