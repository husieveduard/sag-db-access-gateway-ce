<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('alert_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::create('alert_channel_tests', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->string('result_code', 80);
            $table->timestampTz('created_at')->useCurrent();
        });
    }
    public function down(): void {
        Schema::dropIfExists('alert_channel_tests');
        Schema::dropIfExists('alert_history');
    }
};
