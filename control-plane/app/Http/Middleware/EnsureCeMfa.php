<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCeMfa
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        $verifiedUserId = (int) $request->session()
            ->get('ce_mfa.verified_user_id', 0);

        $verifiedAt = (int) $request->session()
            ->get('ce_mfa.verified_at', 0);

        $resetAt = $user?->mfa_reset_at?->getTimestamp() ?? 0;

        $valid = $user
            && $user->is_active
            && $user->role === 'admin'
            && $user->mfa_enabled_at !== null
            && filled($user->mfa_totp_secret)
            && $verifiedUserId === $user->id
            && $verifiedAt > 0
            && $verifiedAt > $resetAt;

        if ($valid) {
            return $next($request);
        }

        return $this->logoutAndRedirect($request);
    }

    private function logoutAndRedirect(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->withErrors([
                'email' => 'Для доступу до консолі потрібне MFA-підтвердження.',
            ]);
    }
}
