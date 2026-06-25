<?php

namespace App\Console\Commands;

use App\Models\User;
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

        if (!$user->is_active || $user->role !== 'admin') {
            $this->error('Password can be set only for an active administrator.');

            return self::FAILURE;
        }

        $password = (string) $this->secret(
            'New password (minimum 14 characters)'
        );

        $confirmation = (string) $this->secret(
            'Confirm new password'
        );

        if (strlen($password) < 14) {
            $this->error('Password must contain at least 14 characters.');

            return self::FAILURE;
        }

        if (!hash_equals($password, $confirmation)) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => Hash::make($password),
        ])->save();

        $this->info("Password updated for {$user->email}.");

        return self::SUCCESS;
    }
}
