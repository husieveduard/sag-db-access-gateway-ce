<?php

namespace App\Services\DbAccess;

use App\Models\DbAccessSession;
use App\Models\GatewayTemporaryCredential;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Shared launch/identity/readiness lifecycle; each engine needs a native adapter. */
final class LabManagedBrokerLifecycleService extends DbGatewayLifecycleService
{
    public function start(DbAccessSession $session): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || ! app()->environment('local', 'testing') || config('sag.db_gateway.managed_lab.enabled') !== true) {
            throw new RuntimeException('Managed laboratory broker is disabled in this environment.');
        }
        $session->loadMissing('resource');
        // Never register an engine merely because it supports passthrough.
        // MySQL/MariaDB and MSSQL need their native adapters and SQL E2E first.
        if (! in_array($session->resource?->engine, ['postgresql', 'postgres'], true)) {
            throw new RuntimeException('Managed laboratory broker is not implemented for this engine.');
        }
        $binary = realpath((string) config('sag.db_gateway.managed_lab.postgresql_binary'));
        $socket = (string) config('sag.db_gateway.managed_lab.socket');
        $cert = (string) config('sag.db_gateway.managed_lab.cert');
        $key = (string) config('sag.db_gateway.managed_lab.key');
        $token = (string) config('sag.internal_token');
        $control = (string) config('sag.db_gateway.control_plane_url');
        $workerUid = config('sag.db_gateway.managed_lab.worker_uid') ?? posix_geteuid();
        if ($binary === false || ! is_executable($binary) || ! is_readable($cert) || ! is_readable($key)
            || @filetype($socket) !== 'socket' || $token === '' || strtoupper($token) === 'CHANGE_ME'
            || ! ctype_digit((string) $workerUid) || (float) $workerUid > 4294967295
            || ! in_array(parse_url($control, PHP_URL_HOST), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Managed laboratory broker configuration is invalid.');
        }
        $logDir = rtrim((string) config('sag.db_gateway.log_dir'), '/').'/managed-lab';
        if ((! is_dir($logDir) && ! mkdir($logDir, 0700, true)) || is_link($logDir)
            || (fileperms($logDir) & 0077) !== 0 || fileowner($logDir) !== posix_geteuid()) {
            throw new RuntimeException('Managed laboratory log directory must be private.');
        }
        $session = DB::transaction(function () use ($session, $logDir): DbAccessSession {
            $locked = DbAccessSession::query()->with('resource')->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertStartable($locked);
            if ($locked->mode !== 'temporary' || $locked->resource->auth_mode !== 'managed'
                || $locked->resource->target_tls_mode !== 'disable' || empty($locked->resource->target_database)
                || ! in_array($locked->resource->engine, ['postgresql', 'postgres'], true)
                || ! GatewayTemporaryCredential::query()->where('session_id', $locked->id)->exists()
                || $this->isProcessRunning((int) data_get($locked->metadata, 'db.gateway_pid', 0))) {
                throw new RuntimeException('Session is not eligible for managed laboratory launch.');
            }
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['db'] = array_merge(is_array($metadata['db'] ?? null) ? $metadata['db'] : [], [
                'managed_lab' => true, 'engine' => 'postgresql', 'gateway_pid' => null,
                'gateway_proc_start_ticks' => null, 'bootstrap_consumed_at' => null,
                'gateway_log_path' => $logDir.'/session-'.$locked->id.'.log',
            ]);
            $locked->forceFill(['status' => 'starting', 'metadata' => $metadata,
                'gateway_host' => '127.0.0.1', 'gateway_port' => $this->allocatePort($locked, '127.0.0.1'),
                'termination_reason' => null, 'last_activity_at' => now(),
            ])->save();

            return $locked;
        }, 3);
        $process = null;
        $pid = null;
        $bound = false;
        $phase = 'spawn';
        $pipes = [];
        try {
            $args = [$binary, '--supervised', '--listen=127.0.0.1:'.$session->gateway_port,
                '--target='.$this->hostPort($session->resource->target_host, $session->resource->target_port),
                '--database='.$session->resource->target_database, '--backend-tls=disable', '--control='.$control,
                '--ipc-socket='.$socket, '--worker-uid='.(string) $workerUid,
                '--session='.$session->public_id, '--resource='.(string) $session->resource_id,
                '--cert='.$cert, '--key='.$key];
            // Inherit only transport/locale variables. The broker must not get
            // Laravel DB passwords, Vault encryption keys or the internal token.
            $env = ['SAG_DB_SESSION_UID' => $session->public_id];
            foreach (['PATH', 'LANG', 'TZ', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'SSL_CERT_FILE', 'SSL_CERT_DIR'] as $name) {
                if (($value = getenv($name)) !== false) {
                    $env[$name] = $value;
                }
            }
            $log = data_get($session->metadata, 'db.gateway_log_path');
            $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'], 3 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new RuntimeException('spawn failed');
            }
            $phase = 'identity';
            $pid = (int) proc_get_status($process)['pid'];
            // proc_open executes the binary directly; wait for exec, then bind
            // before releasing the private start barrier on stdin.
            $until = microtime(true) + 2;
            do {
                if (@readlink('/proc/'.$pid.'/exe') === $binary) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $until && proc_get_status($process)['running']);
            $ticks = $this->processStartTicks($pid);
            if ($ticks === null || @readlink('/proc/'.$pid.'/exe') !== $binary) {
                throw new RuntimeException('identity unavailable');
            }
            DB::transaction(function () use ($session, $pid, $ticks): void {
                $locked = DbAccessSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'starting' || data_get($locked->metadata, 'db.gateway_pid') !== null) {
                    throw new RuntimeException('launch no longer authorized');
                }
                $metadata = $locked->metadata;
                $metadata['db']['gateway_pid'] = $pid;
                $metadata['db']['gateway_proc_start_ticks'] = $ticks;
                $locked->forceFill(['metadata' => $metadata])->save();
            });
            $bound = true;
            $phase = 'bootstrap';
            $frame = json_encode(['operation' => 'launch', 'engine' => 'postgresql',
                'session_uid' => $session->public_id, 'resource_id' => (int) $session->resource_id,
                'internal_token' => $token], JSON_THROW_ON_ERROR)."\n";
            if (strlen($frame) > 8192 || fwrite($pipes[0], $frame) !== strlen($frame)) {
                throw new RuntimeException('barrier failed');
            }
            $phase = 'backend_ready';
            stream_set_timeout($pipes[3], 25);
            $ready = json_decode((string) fgets($pipes[3], 1025), true, 8, JSON_THROW_ON_ERROR);
            if (($ready['phase'] ?? null) !== 'backend_authenticated' || ($ready['engine'] ?? null) !== 'postgresql'
                || ($ready['session_uid'] ?? null) !== $session->public_id
                || ($ready['resource_id'] ?? null) !== (int) $session->resource_id) {
                throw new RuntimeException('backend not ready');
            }
            $phase = 'activation';
            DB::transaction(function () use ($session, $pid): void {
                $locked = DbAccessSession::query()->with('resource')->whereKey($session->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'starting' || $locked->expires_at?->isFuture() !== true
                    || ! $locked->resource?->is_active || data_get($locked->metadata, 'db.bootstrap_consumed_at') === null
                    || ! $this->isExpectedGatewayProcess($pid, $locked)) {
                    throw new RuntimeException('activation denied');
                }
                $locked->forceFill(['status' => 'started', 'started_at' => now(), 'last_activity_at' => now()])->save();
            });
            $activate = json_encode(['operation' => 'activate', 'engine' => 'postgresql',
                'session_uid' => $session->public_id, 'resource_id' => $session->resource_id], JSON_THROW_ON_ERROR)."\n";
            if (fwrite($pipes[0], $activate) !== strlen($activate)) {
                throw new RuntimeException('activation failed');
            }
            $session->refresh();
            $this->writeAudit($session, null, 'access.db.gateway.managed_lab.started', 'info', ['gateway_pid' => $pid, 'engine' => 'postgresql']);
            // Releasing a proc_open handle is non-blocking; proc_close would wait
            // for this long-lived child. Existing lifecycle owns later termination.
            unset($process);

            return ['session_uid' => $session->public_id, 'gateway_pid' => $pid,
                'gateway_host' => $session->gateway_host, 'gateway_port' => $session->gateway_port];
        } catch (Throwable) {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 15);
                }
                $until = microtime(true) + 1;
                while (proc_get_status($process)['running'] && microtime(true) < $until) {
                    usleep(10000);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
            $session->refresh();
            $ownsLaunch = $bound ? (int) data_get($session->metadata, 'db.gateway_pid') === $pid
                : data_get($session->metadata, 'db.gateway_pid') === null;
            if ($ownsLaunch && $session->status === 'stopping') {
                // Reconcile a revoke that raced with startup only after our
                // owned child is confirmed stopped. Never overwrite a terminal
                // state or signal a PID that belongs to a replacement launch.
                if (! $bound && $session->started_at === null) {
                    $session->forceFill(['gateway_port' => null])->save();
                }
                $this->terminate($session, $session->terminated_by_user_id, $session->termination_reason ?? 'startup_revoked');
            } elseif ($ownsLaunch && in_array($session->status, ['starting', 'started'], true)) {
                $this->markStartFailed($session, 'managed_lab_'.$phase.'_failed');
            }
            throw new RuntimeException('Managed laboratory broker launch failed.');
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }
}
