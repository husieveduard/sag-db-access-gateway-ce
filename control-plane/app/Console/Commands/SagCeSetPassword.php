<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\AuditEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SagCeSetPassword extends Command
{
    protected $signature = 'sag:ce-set-password
        {email : Email address of an active CE administrator}';

    protected $description = 'Set a local password for an active CE administrator';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $user = User::query()
            ->where('email', $email)
            ->first();

        if (!$user) {
            $this->error('User was not found.');

            return self::FAILURE;
        }

        if (!$user->is_active || !$user->isCeAdministrator() || $user->auth_source !== 'local') {
            $this->error('Password can be set only for an active administrator.');

            return self::FAILURE;
        }

        $password = (string) $this->secret(
            'New password (minimum 14 characters)'
        );

        $confirmation = (string) $this->secret(
            'Confirm new password'
        );

        if (strlen($password) < 14 || !preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $this->error('Password must be at least 14 characters and include uppercase, lowercase and a digit.');

            return self::FAILURE;
        }

        if (!hash_equals($password, $confirmation)) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => Hash::make($password),
        ])->save();

        AuditEvent::query()->create([
            'actor_user_id' => null,
            'category' => 'authentication',
            'event_type' => 'auth.admin.password_reset',
            'severity' => 'high',
            'event_data' => ['subject_user_id' => $user->id, 'subject_user_email' => $user->email, 'source' => 'console_command'],
            'occurred_at' => now(),
        ]);

        $this->info("Password updated for {$user->email}.");

        return self::SUCCESS;
    }
}
