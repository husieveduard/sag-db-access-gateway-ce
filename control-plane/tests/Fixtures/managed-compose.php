<?php

// CLI-only fixture for disposable Compose acceptance databases.
use App\Models\DatabaseResource;
use App\Models\DbAccessOperation;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\Credentials\ResourceCredentialVault;
use App\Services\Credentials\TemporaryGatewayCredentials;
use Illuminate\Contracts\Console\Kernel;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || getenv('SAG_COMPOSE_ACCEPTANCE') !== '1'
    || config('database.default') !== 'pgsql'
    || ! str_starts_with((string) config('database.connections.pgsql.database'), 'sag_ce_accept_')) {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$action = $input['action'] ?? '';
if ($action === 'setup') {
    $engine = $input['engine'];
    if (! in_array($engine, ['mysql', 'mariadb', 'mssql'], true)) {
        exit(2);
    }
    $suffix = bin2hex(random_bytes(6));
    $user = User::query()->create(['name' => 'Compose acceptance',
        'email' => "compose-{$suffix}@example.invalid", 'password' => bin2hex(random_bytes(24))]);
    $resource = DatabaseResource::query()->create([
        'name' => "Compose {$engine} {$suffix}", 'engine' => $engine,
        'target_host' => 'target', 'target_port' => $engine === 'mssql' ? 1433 : 3306,
        'target_database' => 'sag_compose_e2e', 'target_tls_mode' => 'verify-full',
        'auth_mode' => 'managed', 'is_active' => true, 'created_by_user_id' => $user->id,
    ]);
    app(ResourceCredentialVault::class)->replace($resource->id, 'sag_compose_backend', $input['backend_password'], $user->id);
    $session = DbAccessSession::query()->create([
        'public_id' => 'SAG-COMPOSE-'.strtoupper($engine).'-'.$suffix,
        'resource_id' => $resource->id, 'owner_user_id' => $user->id,
        'mode' => 'temporary', 'status' => 'created', 'expires_at' => now()->addHour(),
    ]);
    $temporary = app(TemporaryGatewayCredentials::class)->issue($session);
    $session->update(['target_db_username' => $temporary['username']]);
    DbAccessOperation::query()->create(['operation_uid' => 'OP-'.$suffix,
        'session_id' => $session->id, 'requested_by_user_id' => $user->id,
        'operation_type' => 'start_session', 'status' => 'queued', 'requested_at' => now()]);
    // Only temporary frontend credentials return through the captured pipe.
    echo json_encode(['public_id' => $session->public_id, 'temporary' => $temporary], JSON_THROW_ON_ERROR);
    exit;
}
$session = DbAccessSession::query()->where('public_id', $input['public_id'])->firstOrFail();
if (! str_starts_with($session->public_id, 'SAG-COMPOSE-')) {
    exit(2);
}
if ($action === 'status') {
    $operation = $session->operations()->latest('id')->firstOrFail();
    $pid = (int) data_get($session->metadata, 'db.gateway_pid');
    $env = @file_get_contents('/proc/'.$pid.'/environ');
    $argv = @file_get_contents('/proc/'.$pid.'/cmdline');
    $stat = @file_get_contents('/proc/'.$pid.'/stat');
    $fields = $stat === false ? [] : preg_split('/\s+/', substr($stat, strrpos($stat, ')') + 2));
    $adapter = $session->resource->engine === 'mssql' ? 'mssql' : 'mysql';
    $binary = realpath((string) config('sag.db_gateway.managed_runtime.'.$adapter.'_binary'));
    $bound = $binary !== false && @readlink('/proc/'.$pid.'/exe') === $binary
        && ($fields[19] ?? null) === data_get($session->metadata, 'db.gateway_proc_start_ticks');
    $argvSafe = $argv !== false && isset($input['backend_password'])
        && ! str_contains($argv, $input['backend_password']);
    $safe = $env !== false;
    foreach (['DB_PASSWORD=', 'APP_KEY=', 'SAG_CE_CREDENTIAL_KEY=', 'SAG_INTERNAL_TOKEN=',
        'HTTP_PROXY=', 'HTTPS_PROXY=', 'http_proxy=', 'https_proxy='] as $forbidden) {
        $safe = $safe && ! str_contains((string) $env, $forbidden);
    }
    echo json_encode(['session' => $session->status, 'operation' => $operation->status,
        'reason' => $session->termination_reason,
        'port' => $session->gateway_port, 'pid' => $pid, 'environment_safe' => $safe,
        'process_bound' => $bound, 'argv_safe' => $argvSafe,
        'bootstrap_consumed' => data_get($session->metadata, 'db.bootstrap_consumed_at') !== null], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'foreign_bootstrap') {
    $socket = stream_socket_client('unix://'.config('sag.db_gateway.managed_runtime.socket'), $errno, $error, 3);
    stream_set_timeout($socket, 3);
    fwrite($socket, json_encode(['operation' => 'bootstrap_credentials',
        'session_uid' => $session->public_id, 'resource_id' => $session->resource_id])."\n");
    $reply = json_decode((string) fgets($socket, 8193), true);
    fclose($socket);
    echo json_encode(['denied' => ! isset($reply['credential'])], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'revoke') {
    DbAccessOperation::query()->create(['operation_uid' => 'OP-STOP-'.bin2hex(random_bytes(6)),
        'session_id' => $session->id, 'requested_by_user_id' => $session->owner_user_id,
        'operation_type' => 'terminate_session', 'status' => 'queued',
        'reason' => 'compose_acceptance', 'requested_at' => now()]);
    echo json_encode(['queued' => true], JSON_THROW_ON_ERROR);
    exit;
}
if ($action === 'audit') {
    foreach (['/var/lib/sag/db-gateway/logs', storage_path('logs')] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_contains(file_get_contents($file->getPathname()), $input['backend_password'])) {
                exit(1);
            }
        }
    }
    $listener = @fsockopen('127.0.0.1', $session->gateway_port, $errno, $error, 2);
    if (is_resource($listener)) {
        fclose($listener);
        exit(1);
    }
    echo json_encode(['logs_safe' => true, 'listener_closed' => true], JSON_THROW_ON_ERROR);
    exit;
}
exit(2);
