<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_resources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160)->unique();
            $table->text('description')->nullable();

            // postgresql, mysql, mssql
            $table->string('engine', 32);
            $table->string('target_host', 253);
            $table->unsignedInteger('target_port');
            $table->string('target_database', 128)->nullable();

            // disable, prefer, require, verify_ca, verify_full
            $table->string('target_tls_mode', 32)->default('prefer');

            // Stage 1: client_passthrough / user_credentials
            $table->string('auth_mode', 32)->default('client_passthrough');

            $table->boolean('query_audit_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('target_options')->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampsTz();

            $table->index(['engine', 'is_active'], 'db_resources_engine_active_idx');
            $table->index(['target_host', 'target_port'], 'db_resources_target_idx');
        });

        Schema::create('db_access_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();

            $table->foreignId('resource_id')
                ->constrained('database_resources')
                ->restrictOnDelete();

            $table->foreignId('owner_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('terminated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // temporary, persistent, service
            $table->string('mode', 16)->default('temporary');

            // created, starting, started, ended, terminated, expired, failed
            $table->string('status', 16)->default('created');

            $table->string('gateway_host', 253)->nullable();
            $table->unsignedInteger('gateway_port')->nullable();
            $table->string('gateway_username', 128)->nullable();

            // Only hash; plaintext session token is never persisted.
            $table->string('gateway_token_hash', 255)->nullable();

            // IPv4, IPv6 or CIDR.
            $table->string('allowed_source_cidr', 128)->nullable();

            // Audit metadata only. Password is not stored.
            $table->string('target_db_username', 128)->nullable();

            $table->boolean('query_audit_enabled')->default(true);

            // NULL is allowed only for persistent/service sessions.
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('terminated_at')->nullable();
            $table->timestampTz('last_activity_at')->nullable();

            $table->string('termination_reason', 255)->nullable();
            $table->json('metadata')->nullable();

            $table->timestampsTz();

            $table->index(['owner_user_id', 'status'], 'db_sessions_owner_status_idx');
            $table->index(['resource_id', 'status'], 'db_sessions_resource_status_idx');
            $table->index(['status', 'expires_at'], 'db_sessions_status_expires_idx');
            $table->index('gateway_port', 'db_sessions_gateway_port_idx');
        });

        Schema::create('db_connections', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 64)->unique();

            $table->foreignId('session_id')
                ->constrained('db_access_sessions')
                ->restrictOnDelete();

            $table->foreignId('resource_id')
                ->constrained('database_resources')
                ->restrictOnDelete();

            $table->foreignId('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('engine', 32);
            $table->string('client_address', 128)->nullable();
            $table->unsignedInteger('client_port')->nullable();

            $table->string('target_host', 253)->nullable();
            $table->unsignedInteger('target_port')->nullable();
            $table->string('target_database', 128)->nullable();
            $table->string('db_username', 128)->nullable();

            // opened, closed, failed, denied
            $table->string('status', 16)->default('opened');

            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->string('close_reason', 255)->nullable();

            $table->unsignedBigInteger('bytes_in')->nullable();
            $table->unsignedBigInteger('bytes_out')->nullable();
            $table->json('metadata')->nullable();

            $table->timestampsTz();

            $table->index(['session_id', 'opened_at'], 'db_connections_session_opened_idx');
            $table->index(['resource_id', 'opened_at'], 'db_connections_resource_opened_idx');
            $table->index(['status', 'opened_at'], 'db_connections_status_opened_idx');
        });

        Schema::create('db_query_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('connection_id')
                ->constrained('db_connections')
                ->restrictOnDelete();

            $table->foreignId('session_id')
                ->constrained('db_access_sessions')
                ->restrictOnDelete();

            $table->foreignId('resource_id')
                ->constrained('database_resources')
                ->restrictOnDelete();

            $table->foreignId('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unsignedBigInteger('event_sequence')->nullable();

            // SELECT, INSERT, UPDATE, DELETE, DDL, TXN, UNKNOWN
            $table->string('statement_type', 32)->nullable();

            // low, medium, high
            $table->string('risk_level', 16)->default('low');
            $table->string('risk_reason', 128)->nullable();

            // Gateway/analyzer must redact values where policy requires it.
            $table->longText('sql_text')->nullable();
            $table->string('sql_hash', 64)->nullable();
            $table->boolean('sql_redacted')->default(false);

            // executed, failed, denied
            $table->string('query_status', 16)->default('executed');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->bigInteger('rows_affected')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->timestampTz('occurred_at');
            $table->json('metadata')->nullable();

            $table->timestampsTz();

            $table->index([');

            // executed, failed, denied
            $table->string('query_status', 16)->default('executed');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->bigInteger('rows_affected')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->timestampTz('occurred_at');
            $table->json('metadata')->nullable();

            $table->timestampsTz();

            $table->index(['connection_id', 'occurred_at'], 'db_query_events_connection_time_idx');
            $table->index(['session_id', 'risk_level', 'occurred_at'], 'db_query_events_session_risk_time_idx');
            $table->index(['resource_id', 'occurred_at'], 'db_query_events_resource_time_idx');
            $table->index('sql_hash', 'db_query_events_sql_hash_idx');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('resource_id')
                ->nullable()
                ->constrained('database_resources')
                ->nullOnDelete();

            $table->foreignId('session_id')
                ->nullable()
                ->constrained('db_access_sessions')
                ->nullOnDelete();

            $table->foreignId('db_connection_id')
                ->nullable()
                ->constrained('db_connections')
                ->nullOnDelete();

            $table->string('category', 64);
            $table->string('event_type', 128);

            // info, low, medium, high, critical
            $table->string('severity', 16)->default('info');

            $table->string('ip_address', 128)->nullable();
            $table->string('user_agent', 1024)->nullable();

            $table->json('event_data')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->timestampsTz();

            $table->index(['category', 'occurred_at'], 'audit_events_category_time_idx');
            $table->index(['event_type', 'occurred_at'], 'audit_events_type_time_idx');
            $table->index(['resource_id', 'occurred_at'], 'audit_events_resource_time_idx');
            $table->index(['session_id', 'occurred_at'], 'audit_events_session_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('db_query_events');
        Schema::dropIfExists('db_connections');
        Schema::dropIfExists('db_access_sessions');
        Schema::dropIfExists('database_resources');
    }
};
