<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('auth_source', 16)->default('local')->index();
            $table->string('ldap_guid', 32)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['ldap_guid']);
            $table->dropIndex(['auth_source']);
            $table->dropColumn(['auth_source', 'ldap_guid']);
        });
    }
};
