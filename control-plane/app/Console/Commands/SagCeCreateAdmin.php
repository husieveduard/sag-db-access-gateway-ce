<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SagCeCreateAdmin extends Command
{
    protected $signature = 'sag:ce-create-admin
        {email? : Administrator email}
        {--name= : Display name of the CE Operator}';

    protected $description = 'Create the initial local CE Operator account';

    public function handle(): int
    {
        $email = Str::lower(trim((string) (
            $this->argument('email')
            ?: $this->ask('Administrator email')
        )));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid administrator email address.');

            return self::FAILURE;
        }

        $name = trim((string) (
            $this->option('name')
            ?: $this->ask('Operator display name')
        ));

        if (Str::length($name) < 2 || Str::length($name) > 120) {
            $this->error(
                'Operator display name must contain from 2 to 120 characters.'
            );

            return self::FAILURE;
        }

        $activeAdmin = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->first();

        if ($activeAdmin) {
            $this->error(
                'An active CE Operator already exists: '
                .$activeAdmin->email
            );

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error(
                "A user with email {$email} already exists. "
                .'Use sag:ce-set-password or create a different account.'
            );

            return self::FAILURE;
        }

        $password = (string) $this->secret(
            'Initial password (minimum 14 characters, upper/lowercase and digit)'
        );

        $confirmation = (string) $this->secret(
            'Confirm initial password'
        );

        $validPassword = strlen($password) >= 14
            && preg_match('/[a-z]/', $password)
            && preg_match('/[A-Z]/', $password)
            && preg_match('/\d/', $password);

        if (!$validPassword) {
            $this->error(
                'Password must be at least 14 characters and contain '
                .'uppercase, lowercase and a digit.'
            );

            return self::FAILURE;
        }

        if (!hash_equals($password, $confirmation)) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use (
            $email,
            $name,
            $password,
        ): User {
            $user = new User();

            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'admin',
                'is_active' => true,
                'is_service_account' => false,
                'email_verified_at' => now(),
            ])->save();

            return $user;
        });

        AuditEvent::query()->create([
            'actor_user_id' => null,
            'category' => 'authentication',
            'event_type' => 'auth.admin.created',
            'severity' => 'high',
            'event_data' => [
                'subject_user_id' => $user->id,
                'subject_user_email' => $user->email,
                'source' => 'console_command',
                'mfa_required_on_first_login' => true,
            ],
            'occurred_at' => now(),
        ]);

        $this->info("CE Operator {$user->email} created.");
        $this->line(
            'At first web login, MFA enrollment through QR/TOTP is mandatory.'
        );

        return self::SUCCESS;
    }
}
