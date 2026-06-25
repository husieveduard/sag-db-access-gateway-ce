<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_totp_secret')->nullable();
            $table->timestampTz('mfa_enabled_at')->nullable();
            $table->timestampTz('mfa_last_used_at')->nullable();
            $table->timestampTz('mfa_reset_at')->nullable();
            $table->json('mfa_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'mfa_totp_secret',
                'mfa_enabled_at',
                'mfa_last_used_at',
                'mfa_reset_at',
                'mfa_recovery_codes',
            ]);
        });
    }
};
