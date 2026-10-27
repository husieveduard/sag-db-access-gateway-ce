<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DbAccessSession;
use App\Services\Credentials\TemporaryGatewayCredentials;
use Illuminate\Http\Request;

final class IssueTemporaryCredentialController extends Controller
{
    public function __invoke(Request $request, DbAccessSession $session, TemporaryGatewayCredentials $credentials)
    {
        abort_unless($request->user()->isSuperAdmin() || $session->owner_user_id === $request->user()->id, 404);
        abort_unless($session->mode === 'temporary' && $session->status === 'created'
            && $session->expires_at?->isFuture() && $session->resource?->is_active, 409);

        $issued = $credentials->issue($session);

        return response()->view('admin.sessions.temporary-credential-issued', [
            'session' => $session,
            'credential' => $issued,
        ])->header('Cache-Control', 'no-store, private')
          ->header('Referrer-Policy', 'no-referrer');
    }
}
