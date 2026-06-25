<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withErrors([
                    'email' => "Забагато спроб входу. Повторіть через {$seconds} с.",
                ])
                ->onlyInput('email');
        }

        $email = Str::lower($credentials['email']);

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->where('role', 'admin')
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            $this->writeAuthAudit(
                request: $request,
                user: $user,
                eventType: 'auth.login.failed',
                severity: 'medium',
                eventData: [
                    'email' => $email,
                    'reason' => 'invalid_credentials',
                ],
            );

            return back()
                ->withErrors([
                    'email' => 'Невірна email-адреса або пароль.',
                ])
                ->onlyInput('email');
        }

        RateLimiter::clear($key);

        $request->session()->regenerate();
        $request->session()->forget('ce_mfa');

        $request->session()->put([
            'ce_mfa.pending_user_id' => $user->id,
            'ce_mfa.pending_started_at' => now()->timestamp,
        ]);

        $this->writeAuthAudit(
            request: $request,
            user: $user,
            eventType: 'auth.password.verified',
            severity: 'info',
            eventData: [
                'role' => $user->role,
                'mfa_configured' => $user->mfa_enabled_at !== null,
            ],
        );

        $mfaConfigured = $user->mfa_enabled_at !== null
            && filled($user->mfa_totp_secret);

        return redirect()->route(
            $mfaConfigured ? 'mfa.challenge' : 'mfa.setup'
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            $this->writeAuthAudit(
                request: $request,
                user: $user,
                eventType: 'auth.logout',
                severity: 'info',
                eventData: [],
            );
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function throttleKey(Request $request): string
    {
        return Str::lower((string) $request->input('email'))
            .'|'.$request->ip();
    }

    private function writeAuthAudit(
        Request $request,
        ?User $user,
        string $eventType,
        string $severity,
        array $eventData,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $user?->id,
            'category' => 'authentication',
            'event_type' => $eventType,
            'severity' => $severity,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit(
                (string) $request->userAgent(),
                500,
                '',
            ),
            'event_data' => $eventData,
            'occurred_at' => now(),
        ]);
    }
}
