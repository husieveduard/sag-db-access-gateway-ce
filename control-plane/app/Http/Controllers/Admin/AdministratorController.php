<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdministratorController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return view('admin.administrators.index', [
            'users' => User::query()->whereIn('role', ['admin', 'superadmin'])
                ->orderBy('name')->paginate(50),
        ]);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'superadmin'])],
        ]);

        abort_unless($user->isCeAdministrator(), 404);

        DB::transaction(function () use ($request, $user, $data): void {
            $target = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $oldRole = $target->role;
            $newRole = $data['role'];

            if ($oldRole === $newRole) {
                return;
            }

            // Serialise role changes and protect the final active superadmin.
            DB::table('users')->where('role', 'superadmin')->lockForUpdate()->get(['id']);

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
            ->with('success', 'Administrator role updated.');
    }
}
