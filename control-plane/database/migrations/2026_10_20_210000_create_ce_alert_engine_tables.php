<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('event_type', 128)->unique();
            $table->string('title', 160);
            $table->string('severity', 16);
            $table->unsignedSmallInteger('cooldown_minutes')->default(5);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')->constrained('alert_rules')->restrictOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained('database_resources')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('db_access_sessions')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('last_audit_event_id');
            $table->string('fingerprint', 64);
            $table->string('severity', 16);
            $table->string('status', 24)->default('new');
            $table->string('summary', 255);
            $table->unsignedBigInteger('occurrence_count')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['fingerprint', 'status', 'last_seen_at'], 'alerts_dedupe_idx');
            $table->index(['owner_user_id', 'status', 'last_seen_at'], 'alerts_owner_status_idx');
            $table->index(['severity', 'last_seen_at'], 'alerts_severity_idx');
        });

        Schema::create('alert_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')->constrained('alert_rules')->cascadeOnDelete();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();
            $table->foreignId('audit_event_id')->constrained('audit_events')->restrictOnDelete();
            $table->string('source_key', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['rule_id', 'source_key'], 'alert_matches_source_unique');
        });

        Schema::create('alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->useCurrent();
            $table->timestampTz('delivered_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestampsTz();
            $table->unique(['alert_id', 'channel'], 'alert_delivery_once_idx');
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('alert_processor_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_audit_event_id')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        foreach ([
            ['high_risk_query', 'access.db.query.high_risk', 'Небезпечний SQL-запит', 'high', 5],
            ['failed_query', 'access.db.query.failed', 'SQL-помилка', 'medium', 10],
            ['denied_connection', 'access.db.connection.denied', 'Відхилене DB-підключення', 'high', 5],
            ['failed_operation', 'access.db.operation.failed', 'Помилка керування gateway', 'high', 5],
            ['gateway_stop_failed', 'access.db.gateway.stop_failed', 'Gateway не зупинився', 'critical', 5],
        ] as [$code, $eventType, $title, $severity, $cooldown]) {
            DB::table('alert_rules')->insert([
                'code' => $code,
                'event_type' => $eventType,
                'title' => $title,
                'severity' => $severity,
                'cooldown_minutes' => $cooldown,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Do not send alert storms for historical audit rows on deployment.
        DB::table('alert_processor_state')->insert([
            'id' => 1,
            'last_audit_event_id' => (int) (DB::table('audit_events')->max('id') ?? 0),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_processor_state');
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alert_matches');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_rules');
    }
};
