<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_temporary_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->unique()
                ->constrained('db_access_sessions')->cascadeOnDelete();
            $table->string('username', 128)->unique();
            $table->string('password_hash', 255);
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_temporary_credentials');
    }
};
