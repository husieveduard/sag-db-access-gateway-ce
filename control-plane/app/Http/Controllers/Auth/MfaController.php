<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class MfaController extends Controller
{
    private const PENDING_TTL_SECONDS = 600;
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 60;

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUserOrRedirect($request);

        if ($user instanceof RedirectResponse) {
            return $user;
        }

        if ($this->hasMfaConfigured($user)) {
            return redirect()->route('mfa.challenge');
        }

        $secret = (string) $request->session()
            ->get('ce_mfa.pending_totp_secret', '');

        if ($secret === '') {
            $secret = app(Google2FA::class)->generateSecretKey(32);

            $request->session()->put(
                'ce_mfa.pending_totp_secret',
                $secret,
            );
        }

        $issuer = 'SAG DB Access Gateway CE';

        $otpAuthUrl = app(Google2FA::class)->getQRCodeUrl(
            $issuer,
            $user->email,
            $secret,
        );

        $qrDataUri = (new Builder(
            writer: new SvgWriter(),
            writerOptions: [],
            validateResult: false,
            data: $otpAuthUrl,
            size: 280,
            margin: 10,
        ))->build()->getDataUri();

        return view('auth.mfa-setup', [
            'user' => $user,
            'secret' => $secret,
            'qrDataUri' => $qrDataUri,
        ]);
    }

    public function confirmSetup(Request $request): RedirectResponse
    {
        $user = $this->pendingUserOrRedirect($request);

        if ($user instanceof RedirectResponse) {
            return $user;
        }

        if ($this->hasMfaConfigured($user)) {
            return redirect()->route('mfa.challenge');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $secret = (string) $request->session()
            ->get('ce_mfa.pending_totp_secret', '');

        if ($secret === '') {
            return redirect()
                ->route('mfa.setup')
                ->withErrors([
                    'code' => __('messages.auth.mfa.setup_session_refreshed'),
                ]);
        }

        $key = $this->throttleKey($request, $user->id, 'setup');

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors([
                'code' => __('messages.auth.mfa.too_many_invalid_codes', ['seconds' => $seconds]),
            ]);
        }

        $code = trim((string) $data['code']);

        if (!app(Google2FA::class)->verifyKey($secret, $code, 1)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            $this->writeAudit(
                request: $request,
                user: $user,
                eventType: 'auth.mfa.setup.failed',
                severity: 'medium',
                eventData: ['reason' => 'invalid_totp_code'],
            );

            return back()->withErrors([
                'code' => __('messages.auth.mfa.invalid_totp'),
            ]);
        }

        RateLimiter::clear($key);

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'mfa_totp_secret' => $secret,
            'mfa_enabled_at' => now(),
            'mfa_last_used_at' => now(),
            'mfa_reset_at' => null,
            'mfa_recovery_codes' => array_map(
                fn (string $recoveryCode): string => Hash::make($recoveryCode),
                $recoveryCodes,
            ),
        ])->save();

        $this->writeAudit(
            request: $request,
            user: $user,
            eventType: 'auth.mfa.enabled',
            severity: 'medium',
            eventData: [
                'method' => 'totp',
                'recovery_codes_count' => count($recoveryCodes),
            ],
        );

        $this->completeLogin($request, $user, 'totp_enrollment');

        $request->session()->put(
            'ce_mfa.recovery_codes_once',
            $recoveryCodes,
        );

        return redirect()->route('mfa.recovery.show');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUserOrRedirect($request);

        if ($user instanceof RedirectResponse) {
            return $user;
        }

        if (!$this->hasMfaConfigured($user)) {
            return redirect()->route('mfa.setup');
        }

        return view('auth.mfa-challenge', [
            'user' => $user,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->pendingUserOrRedirect($request);

        if ($user instanceof RedirectResponse) {
            return $user;
        }

        if (!$this->hasMfaConfigured($user)) {
            return redirect()->route('mfa.setup');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $key = $this->throttleKey($request, $user->id, 'challenge');

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors([
                'code' => __('messages.auth.mfa.too_many_invalid_codes', ['seconds' => $seconds]),
            ]);
        }

        $code = $this->normalizeCode((string) $data['code']);
        $method = null;
        $valid = false;

        if (preg_match('/^\d{6}$/', $code)) {
            $method = 'totp';

            $valid = app(Google2FA::class)->verifyKey(
                (string) $user->mfa_totp_secret,
                $code,
                1,
            );
        } else {
            $method = 'recovery_code';
            $valid = $this->consumeRecoveryCode($user, $code);
        }

        if (!$valid) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            $this->writeAudit(
                request: $request,
                user: $user,
                eventType: 'auth.mfa.challenge.failed',
                severity: 'medium',
                eventData: [
                    'reason' => 'invalid_code',
                    'attempted_method' => $method,
                ],
            );

            return back()->withErrors([
                'code' => __('messages.auth.mfa.invalid_mfa_or_recovery'),
            ]);
        }

        RateLimiter::clear($key);

        if ($method === 'recovery_code') {
            $this->writeAudit(
                request: $request,
                user: $user,
                eventType: 'auth.mfa.recovery_code.used',
                severity: 'high',
                eventData: [],
            );
        }

        $this->completeLogin($request, $user, $method);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function recoveryCodes(Request $request): View|RedirectResponse
    {
        $codes = $request->session()->get('ce_mfa.recovery_codes_once');

        if (!is_array($codes) || $codes === []) {
            return redirect()
                ->route('admin.dashboard')
                ->with('success', __('messages.auth.mfa.recovery_codes_unavailable'));
        }

        return view('auth.mfa-recovery-codes', [
            'codes' => $codes,
        ]);
    }

    public function acknowledgeRecoveryCodes(
        Request $request,
    ): RedirectResponse {
        $request->session()->forget('ce_mfa.recovery_codes_once');

        $user = $request->user();

        if ($user) {
            $this->writeAudit(
                request: $request,
                user: $user,
                eventType: 'auth.mfa.recovery_codes.acknowledged',
                severity: 'info',
                eventData: [],
            );
        }

        return redirect()
            ->route('admin.dashboard')
            ->with('success', __('messages.auth.mfa.setup_completed'));
    }

    private function pendingUserOrRedirect(
        Request $request,
    ): User|RedirectResponse {
        $userId = (int) $request->session()
            ->get('ce_mfa.pending_user_id', 0);

        $startedAt = (int) $request->session()
            ->get('ce_mfa.pending_started_at', 0);

        $expired = $startedAt <= 0
            || (now()->timestamp - $startedAt) > self::PENDING_TTL_SECONDS;

        if ($userId < 1 || $expired) {
            $this->forgetPending($request);

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => __('messages.auth.mfa.pending_expired'),
                ]);
        }

        $user = User::query()
            ->whereKey($userId)
            ->where('is_active', true)
            ->where('role', 'admin')
            ->first();

        if (!$user) {
            $this->forgetPending($request);

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => __('messages.auth.mfa.pending_user_unavailable'),
                ]);
        }

        return $user;
    }

    private function hasMfaConfigured(User $user): bool
    {
        return $user->mfa_enabled_at !== null
            && filled($user->mfa_totp_secret);
    }

    private function completeLogin(
        Request $request,
        User $user,
        string $method,
    ): void {
        Auth::login($user);
        $request->session()->regenerate();

        $request->session()->put([
            'ce_mfa.verified_user_id' => $user->id,
            'ce_mfa.verified_at' => now()->timestamp,
        ]);

        $this->forgetPending($request);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'mfa_last_used_at' => now(),
        ])->save();

        $this->writeAudit(
            request: $request,
            user: $user,
            eventType: 'auth.login.succeeded',
            severity: 'info',
            eventData: [
                'mfa_method' => $method,
            ],
        );
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = is_array($user->mfa_recovery_codes)
            ? $user->mfa_recovery_codes
            : [];

        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && Hash::check($code, $hash)) {
                unset($hashes[$index]);

                $user->forceFill([
                    'mfa_recovery_codes' => array_values($hashes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    private function generateRecoveryCodes(): array
    {
        return collect(range(1, 10))
            ->map(function (): string {
                $hex = strtoupper(bin2hex(random_bytes(6)));

                return implode('-', str_split($hex, 4));
            })
            ->all();
    }

    private function normalizeCode(string $code): string
    {
        return Str::upper(
            preg_replace('/\s+/', '', trim($code)) ?? ''
        );
    }

    private function forgetPending(Request $request): void
    {
        $request->session()->forget([
            'ce_mfa.pending_user_id',
            'ce_mfa.pending_started_at',
            'ce_mfa.pending_totp_secret',
        ]);
    }

    private function throttleKey(
        Request $request,
        int $userId,
        string $purpose,
    ): string {
        return "ce-mfa:{$purpose}:{$userId}|".$request->ip();
    }

    private function writeAudit(
        Request $request,
        User $user,
        string $eventType,
        string $severity,
        array $eventData,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $user->id,
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
