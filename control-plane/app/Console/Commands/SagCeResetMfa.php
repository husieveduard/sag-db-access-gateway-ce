<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Console\Command;
use RuntimeException;

class SagCeResetMfa extends Command
{
    protected $signature = 'sag:ce-reset-mfa
        {user : User email or numeric ID}
        {--reason=Emergency CLI MFA reset : Audit reason}
        {--confirm : Required confirmation flag}';

    protected $description = 'Emergency reset of CE operator MFA';

    public function handle(): int
    {
        if (!(bool) $this->option('confirm')) {
            $this->error(
                'MFA reset not executed. Run again with --confirm.'
            );

            return self::FAILURE;
        }

        $reference = trim((string) $this->argument('user'));

        $user = ctype_digit($reference)
            ? User::query()->find((int) $reference)
            : User::query()->where('email', $reference)->first();

        if (!$user) {
            throw new RuntimeException("User not found: {$reference}");
        }

        $reason = trim((string) $this->option('reason'))
            ?: 'Emergency CLI MFA reset';

        $user->forceFill([
            'mfa_totp_secret' => null,
            'mfa_enabled_at' => null,
            'mfa_last_used_at' => null,
            'mfa_recovery_codes' => null,
            'mfa_reset_at' => now(),
        ])->save();

        AuditEvent::query()->create([
            'actor_user_id' => null,
            'category' => 'authentication',
            'event_type' => 'auth.mfa.reset',
            'severity' => 'high',
            'event_data' => [
                'subject_user_id' => $user->id,
                'subject_user_email' => $user->email,
                'reason' => $reason,
                'source' => 'console_command',
            ],
            'occurred_at' => now(),
        ]);

        $this->info(
            "MFA reset completed for {$user->email}. "
            .'The next login will require new QR enrollment.'
        );

        return self::SUCCESS;
    }
}
