<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('db_query_events', function (Blueprint $table) {
            $table->string('query_uid', 80)->nullable();
            $table->unique('query_uid', 'db_query_events_query_uid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('db_query_events', function (Blueprint $table) {
            $table->dropUnique('db_query_events_query_uid_unique');
            $table->dropColumn('query_uid');
        });
    }
};
