<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Auth\ActiveDirectoryAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class LoginController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('auth.login');
    }

    public function store(Request $request, ActiveDirectoryAuthenticator $directory): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return back()
                ->withErrors([
                    'email' => __('messages.auth.login.too_many_attempts', ['seconds' => $seconds]),
                ])
                ->onlyInput('email');
        }

        $identity = Str::lower(trim($credentials['email']));
        $user = null;
        $reason = 'invalid_credentials';

        // Local login has an explicit email identity and never falls back to LDAP.
        if (filter_var($identity, FILTER_VALIDATE_EMAIL)) {
            $local = User::query()
                ->where('email', $identity)
                ->where('auth_source', 'local')
                ->first();

            if ($local) {
                if ($local->is_active && $local->isCeAdministrator()
                    && Hash::check($credentials['password'], $local->password)) {
                    $user = $local;
                }
                $reason = 'local_credentials_invalid';
            }
        }

        if (!$user && $reason === 'invalid_credentials' && config('sag.ldap.enabled', false)) {
            try {
                $ad = $directory->authenticate($identity, $credentials['password']);
            } catch (RuntimeException $exception) {
                report($exception);
                $ad = null;
                $reason = 'ldap_unavailable';
            }

            if ($ad !== null) {
                // Never convert existing local users into directory users.
                $existing = User::query()->where('ldap_guid', $ad['guid'])->first();
                $emailOwner = User::query()->where('email', $ad['email'])->first();

                if (($existing && ($existing->auth_source !== 'ldap' || !$existing->is_active))
                    || ($emailOwner && ($emailOwner->auth_source !== 'ldap'
                        || ($existing && $existing->id !== $emailOwner->id)
                        || !$emailOwner->is_active
                        || ($emailOwner->ldap_guid !== null && $emailOwner->ldap_guid !== $ad['guid'])))) {
                    $reason = 'directory_identity_conflict';
                } elseif ($existing && $emailOwner && $existing->id !== $emailOwner->id) {
                    $reason = 'directory_identity_conflict';
                } else {
                    try {
                        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($ad, $existing, $emailOwner): User {
                            $profile = $existing ?? $emailOwner;

                            if (!$profile) {
                                $profile = new User();
                                $profile->forceFill([
                                    'password' => Hash::make(Str::random(64)),
                                    'auth_source' => 'ldap',
                                    'is_active' => true,
                                    'is_service_account' => false,
                                ]);
                            }

                            $profile->forceFill([
                                'name' => $ad['name'],
                                'email' => $ad['email'],
                                'ldap_guid' => $ad['guid'],
                                'role' => $profile->exists
                                    ? $profile->role
                                    : 'admin',
                            ])->save();

                            return $profile;
                        });
                    } catch (\Throwable $exception) {
                        report($exception);
                        $reason = 'directory_provisioning_failed';
                    }
                }
            }
        }

        if (!$user) {
            RateLimiter::hit($key, 60);

            $this->writeAuthAudit(
                request: $request,
                user: null,
                eventType: 'auth.login.failed',
                severity: 'medium',
                eventData: [
                    'identity' => Str::limit($identity, 255, ''),
                    'reason' => $reason,
                ],
            );

            return back()
                ->withErrors(['email' => __('messages.auth.login.invalid_credentials')])
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
                'auth_source' => $user->auth_source,
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
