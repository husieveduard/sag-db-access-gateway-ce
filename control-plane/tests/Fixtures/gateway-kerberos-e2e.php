<?php

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\Credentials\ResourceCredentialVault;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Disposable SQLite only. This fixture never returns keytab/config/password data.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || config('database.default') !== 'sqlite') {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
if ($input['action'] === 'setup') {
    Artisan::call('migrate:fresh', ['--force' => true]);
    $user = User::factory()->create();
    $resource = DatabaseResource::query()->create([
        'name' => 'disposable Kerberos IPC E2E', 'engine' => 'mssql', 'target_host' => 'sql.sag.test',
        'target_port' => 1433, 'target_database' => 'isolated', 'auth_mode' => 'managed',
        'target_tls_mode' => 'verify-full', 'is_active' => true, 'created_by_user_id' => $user->id,
        'target_options' => ['backend_auth_mode' => 'mssql_sql_auth'],
    ]);
    app(ResourceCredentialVault::class)->replace($resource->id, 'stale_sql_user', 'stale-sql-fixture', $user->id);
    $resource->update(['target_options' => ['backend_auth_mode' => 'mssql_kerberos_service', 'kerberos_profile' => 'isolated-ad']]);
    $session = DbAccessSession::query()->create([
        'public_id' => 'SAG-KERBEROS-IPC-E2E', 'resource_id' => $resource->id, 'owner_user_id' => $user->id,
        'mode' => 'temporary', 'status' => 'starting', 'expires_at' => now()->addHour(),
        'metadata' => ['db' => ['gateway_pid' => $input['pid'], 'gateway_proc_start_ticks' => $input['ticks'],
            'backend_auth_mode' => 'mssql_kerberos_service', 'kerberos_profile' => 'isolated-ad']],
    ]);
} else {
    $session = DbAccessSession::query()->where('public_id', 'SAG-KERBEROS-IPC-E2E')->firstOrFail();
    match ($input['action']) {
        'activate' => $session->update(['status' => 'started']),
        'revoke' => $session->update(['status' => 'terminated']),
        'change_auth' => $session->resource->update(['target_options' => ['backend_auth_mode' => 'mssql_sql_auth']]),
        'state' => null,
        default => throw new RuntimeException('Unknown test action.'),
    };
}
echo json_encode(['resource' => $session->resource_id,
    'consumed' => data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at') !== null], JSON_THROW_ON_ERROR);
