<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('alert_channel_settings', function(Blueprint $t) {
   $t->string('channel',32)->primary();
   $t->boolean('enabled')->default(false);
   $t->longText('settings');
   $t->timestampsTz();
  });
 }
 public function down(): void { Schema::dropIfExists('alert_channel_settings'); }
};
