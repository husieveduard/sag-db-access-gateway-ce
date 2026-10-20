<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve existing CE installation: first active local admin becomes
        // superadmin. All other accounts keep their roles and ownership.
        DB::transaction(function (): void {
            if (DB::table('users')->where('role', 'superadmin')->exists()) {
                return;
            }

            $id = DB::table('users')
                ->where('role', 'admin')
                ->where('auth_source', 'local')
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');

            if ($id !== null) {
                DB::table('users')->where('id', $id)->update([
                    'role' => 'superadmin',
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Deliberately preserve security roles on rollback.
    }
};
