<?php

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\Credentials\ResourceCredentialVault;
use App\Services\Credentials\TemporaryGatewayCredentials;
use App\Services\DbAccess\LabManagedBrokerLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Isolated E2E fixture only. Secrets arrive on stdin and temporary frontend
// credentials return through a captured pipe. No backend secret output.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || config('database.default') !== 'sqlite') {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (isset($input['launcher'])) {
    $lab = $input['launcher'];
    config()->set('sag.db_gateway.managed_lab', ['enabled' => true, 'postgresql_binary' => $lab['binary'], 'mssql_binary' => $lab['binary'], 'mssql_ca' => $lab['backend_ca'] ?? '', 'mysql_binary' => $lab['binary'], 'mysql_ca' => $lab['backend_ca'] ?? '',
        'socket' => $lab['socket'], 'cert' => $lab['cert'], 'key' => $lab['key'], 'worker_uid' => $lab['worker_uid']]);
    config()->set('sag.db_gateway.log_dir', $lab['log_dir']);
    config()->set('sag.db_gateway.control_plane_url', $lab['control']);
    config()->set('sag.db_gateway.port_min', $lab['port']);
    config()->set('sag.db_gateway.port_max', $lab['port']);
}

if ($input['action'] === 'setup') {
    Artisan::call('migrate:fresh', ['--force' => true]);
    $user = User::factory()->create();
    $resource = DatabaseResource::query()->create([
        'name' => 'isolated E2E', 'engine' => $input['engine'] ?? 'postgresql', 'target_host' => $input['target_host'] ?? 'postgres',
        'auth_mode' => ($input['managed'] ?? false) ? 'managed' : 'client_passthrough',
        'target_tls_mode' => $input['tls_mode'] ?? 'disable',
        'target_port' => $input['target_port'] ?? 5432, 'target_database' => $input['database'] ?? 'sag_e2e', 'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);
    $session = DbAccessSession::query()->create([
        'public_id' => 'SAG-IPC-E2E', 'resource_id' => $resource->id,
        'owner_user_id' => $user->id, 'mode' => 'temporary', 'status' => 'created',
        'expires_at' => now()->addHour(),
    ]);
    app(ResourceCredentialVault::class)->replace(
        $resource->id, $input['backend_username'] ?? 'ipc_e2e_backend', $input['password'], $user->id
    );
    $temporary = app(TemporaryGatewayCredentials::class)->issue($session);
    if (! ($input['managed'] ?? false)) {
        $session->update(['status' => 'starting', 'metadata' => ['db' => [
            'gateway_pid' => $input['pid'], 'gateway_proc_start_ticks' => $input['ticks'],
        ]]]);
    }
    echo json_encode(['resource' => $resource->id, 'temporary' => $temporary], JSON_THROW_ON_ERROR);
} else {
    $session = DbAccessSession::query()->where('public_id', 'SAG-IPC-E2E')->firstOrFail();
    if ($input['action'] === 'launch') {
        try {
            $result = app(LabManagedBrokerLifecycleService::class)->start($session);
            echo json_encode($result + ['status' => $session->fresh()->status,
                'bootstrap_consumed' => data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at') !== null]);
        } catch (Throwable $e) {
            echo json_encode(['failed' => true, 'status' => $session->fresh()->status, 'reason' => $session->fresh()->termination_reason,
                'pid' => data_get($session->fresh()->metadata, 'db.gateway_pid'),
                'bootstrap_consumed' => data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at') !== null, 'error' => $e->getMessage()]);
        }
        exit;
    }
    if ($input['action'] === 'terminate') {
        echo json_encode(app(LabManagedBrokerLifecycleService::class)->terminate($session));
        exit;
    }
    match ($input['action']) {
        'activate' => $session->update(['status' => 'started']),
        'revoke' => $session->update(['status' => 'terminated']),
        'stop_requested' => $session->update(['status' => 'stopping', 'termination_reason' => 'test_startup_revoke']),
        'expire' => $session->update(['expires_at' => now()->subMinute()]),
        'deactivate' => $session->resource->update(['is_active' => false]),
        'replace_pid', 'replace_ticks' => $session->update(['metadata' => ['db' => array_merge(
            $session->metadata['db'], $input['action'] === 'replace_pid'
                ? ['gateway_pid' => 99999999] : ['gateway_proc_start_ticks' => '0']
        )]]),
        default => throw new RuntimeException('Unknown test scenario.'),
    };
}
