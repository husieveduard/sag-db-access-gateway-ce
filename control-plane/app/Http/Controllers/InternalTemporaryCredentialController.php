<?php

namespace App\Http\Controllers;

use App\Models\DbAccessSession;
use App\Services\Credentials\TemporaryGatewayCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InternalTemporaryCredentialController extends Controller
{
    public function verify(Request $request, TemporaryGatewayCredentials $credentials): JsonResponse
    {
        $configured = (string) config('sag.internal_token', '');
        $provided = (string) $request->header('X-SAG-Internal-Token', '');

        if ($configured === '') {
            return response()->json(['ok' => false, 'allowed' => false], 503);
        }

        if ($provided === '' || !hash_equals($configured, $provided)) {
            return response()->json(['ok' => false, 'allowed' => false], 401);
        }

        $data = $request->validate([
            'session_uid' => ['required', 'string', 'max:80'],
            'username' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        $session = DbAccessSession::query()->with('resource')
            ->where('public_id', $data['session_uid'])->first();

        $allowed = $session !== null
            && $session->resource?->is_active === true
            && $credentials->verify($session, $data['username'], $data['password']);

        return response()->json(['ok' => true, 'allowed' => $allowed])
            ->header('Cache-Control', 'no-store');
    }
}
