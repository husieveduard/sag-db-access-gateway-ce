<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('db_access_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_uid', 80)->unique();

            $table->foreignId('session_id')
                ->constrained('db_access_sessions')
                ->restrictOnDelete();

            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('operation_type', 40);
            $table->string('status', 40)->default('queued');
            $table->string('reason', 255)->nullable();

            $table->json('request_payload')->nullable();
            $table->json('result_payload')->nullable();
            $table->text('error_message')->nullable();

            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'id'], 'db_access_operations_status_id_idx');
            $table->index(
                ['session_id', 'status'],
                'db_access_operations_session_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('db_access_operations');
    }
};
