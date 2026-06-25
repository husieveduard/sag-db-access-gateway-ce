<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('db_query_events', function (Blueprint $table): void {
            $table->timestampTz('ended_at')->nullable();
            $table->index(
                ['query_status', 'ended_at'],
                'db_query_events_status_ended_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('db_query_events', function (Blueprint $table): void {
            $table->dropIndex('db_query_events_status_ended_idx');
            $table->dropColumn('ended_at');
        });
    }
};
