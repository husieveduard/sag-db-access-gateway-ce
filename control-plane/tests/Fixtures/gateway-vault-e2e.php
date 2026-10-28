<?php

// Isolated E2E fixture only. Secrets arrive on stdin and temporary frontend
// credentials return through a captured pipe. No backend secret output.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || config('database.default') !== 'sqlite') {
    exit(2);
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if ($input['action'] === 'setup') {
    Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
    $user = App\Models\User::factory()->create();
    $resource = App\Models\DatabaseResource::query()->create([
        'name' => 'isolated E2E', 'engine' => 'postgresql', 'target_host' => 'postgres',
        'target_port' => 5432, 'target_database' => 'sag_e2e', 'is_active' => true,
        'created_by_user_id' => $user->id,
    ]);
    $session = App\Models\DbAccessSession::query()->create([
        'public_id' => 'SAG-IPC-E2E', 'resource_id' => $resource->id,
        'owner_user_id' => $user->id, 'mode' => 'temporary', 'status' => 'created',
        'expires_at' => now()->addHour(),
    ]);
    app(App\Services\Credentials\ResourceCredentialVault::class)->replace(
        $resource->id, 'ipc_e2e_backend', $input['password'], $user->id
    );
    $temporary = app(App\Services\Credentials\TemporaryGatewayCredentials::class)->issue($session);
    $session->update(['status' => 'starting', 'metadata' => ['db' => [
        'gateway_pid' => $input['pid'], 'gateway_proc_start_ticks' => $input['ticks'],
    ]]]);
    echo json_encode(['resource' => $resource->id, 'temporary' => $temporary], JSON_THROW_ON_ERROR);
} else {
    $session = App\Models\DbAccessSession::query()->where('public_id', 'SAG-IPC-E2E')->firstOrFail();
    $session->update(['status' => $input['action'] === 'activate' ? 'started' : 'terminated']);
}
