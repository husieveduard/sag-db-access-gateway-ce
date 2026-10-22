<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_resource_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->unique()
                ->constrained('database_resources')
                ->restrictOnDelete();
            $table->string('username', 128);
            $table->text('password_encrypted');
            $table->unsignedInteger('encryption_version')->default(1);
            $table->foreignId('created_by_user_id')
                ->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')
                ->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_resource_credentials');
    }
};
