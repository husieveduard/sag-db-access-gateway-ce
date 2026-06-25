<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('user')->index('users_role_idx');
            $table->boolean('is_active')->default(true)->index('users_is_active_idx');
            $table->boolean('is_service_account')->default(false)->index('users_is_service_account_idx');
            $table->timestampTz('last_login_at')->nullable();
            $table->string('last_login_ip', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_idx');
            $table->dropIndex('users_is_active_idx');
            $table->dropIndex('users_is_service_account_idx');

            $table->dropColumn([
                'role',
                'is_active',
                'is_service_account',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
