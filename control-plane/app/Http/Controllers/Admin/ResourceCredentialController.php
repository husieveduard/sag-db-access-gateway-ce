<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\DatabaseResource;
use App\Models\DatabaseResourceCredential;
use App\Models\DbAccessSession;
use App\Services\Credentials\ResourceCredentialVault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ResourceCredentialController extends Controller
{
    public function update(Request $request, DatabaseResource $resource, ResourceCredentialVault $vault): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        abort_unless(in_array($resource->engine, ['postgresql', 'mysql', 'mariadb', 'mssql'], true), 422);
        abort_unless($resource->usesPasswordBackendAuthentication(), 422, 'SQL password storage is unavailable for the selected backend authentication mode.');

        $data = $request->validate([
            'username' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        abort_if(
            DbAccessSession::query()->where('resource_id', $resource->id)
                ->whereIn('status', ['starting', 'started', 'stopping'])->exists(),
            409,
            'Cannot rotate credentials while gateway sessions are active.'
        );

        // Never audit or expose password contents.
        DB::transaction(function () use ($request, $resource, $data, $vault): void {
            $previous = DatabaseResourceCredential::query()
                ->where('resource_id', $resource->id)->first(['username']);
            $existing = $previous !== null;
            $usernameChanged = $existing && $previous->username !== trim($data['username']);

            $vault->replace(
                $resource->id,
                $data['username'],
                $data['password'],
                $request->user()->id
            );

            AuditEvent::query()->create([
                'actor_user_id' => $request->user()->id,
                'resource_id' => $resource->id,
                'category' => 'db_access',
                'event_type' => $existing
                    ? 'access.db.credentials.rotated'
                    : 'access.db.credentials.created',
                'severity' => 'high',
                'ip_address' => $request->ip(),
                'event_data' => [
                    'resource_id' => $resource->id,
                    'username_changed' => $usernameChanged,
                    'password_rotated' => $existing,
                    'credential_storage' => 'encrypted',
                    'password_exposed' => false,
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('admin.resources.edit', $resource)
            ->with('success', 'Database credentials saved securely.');
    }
}
