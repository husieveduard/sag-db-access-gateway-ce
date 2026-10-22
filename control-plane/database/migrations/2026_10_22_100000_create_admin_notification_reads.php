<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Source records stay authoritative; only per-admin read state is stored.
        Schema::create('admin_notification_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_id');
            $table->timestampTz('read_at');
            $table->unique(['user_id', 'source_type', 'source_id'], 'admin_notif_read_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_reads');
    }
};
