<?php

namespace App\Services\DbAccess;

use App\Models\AuditEvent;
use App\Models\DbAccessSession;
use App\Models\DbConnection;
use App\Models\DbQueryEvent;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class DbGatewayLifecycleService
{
    public function start(DbAccessSession $session): array
    {
        $session->loadMissing('resource');

        $this->assertStartable($session);

        $resource = $session->resource;
        $binary = trim((string) config('sag.db_gateway.binary'));
        $listenHost = trim((string) config('sag.db_gateway.listen_host', '0.0.0.0'));
        $publicHost = trim((string) config('sag.db_gateway.public_host', ''));
        $controlPlaneUrl = trim((string) config('sag.db_gateway.control_plane_url'));
        $internalToken = trim((string) config('sag.internal_token', ''));
        $logDir = rtrim(
            (string) config('sag.db_gateway.log_dir', '/var/lib/sag/db-gateway/logs'),
            '/'
        );

        if ($binary === '' || !is_executable($binary)) {
            throw new RuntimeException("DB Gateway binary is not executable: {$binary}");
        }

        if ($publicHost === '' || strtoupper($publicHost) === 'CHANGE_ME') {
            throw new RuntimeException('SAG_DB_GATEWAY_PUBLIC_HOST is not configured.');
        }

        if ($controlPlaneUrl === '') {
            throw new RuntimeException('SAG_GATEWAY_CONTROL_PLANE_URL is not configured.');
        }

        if ($internalToken === '' || strtoupper($internalToken) === 'CHANGE_ME') {
            throw new RuntimeException('SAG_INTERNAL_TOKEN is not configured.');
        }

        if (!is_dir($logDir) && !mkdir($logDir, 0750, true) && !is_dir($logDir)) {
            throw new RuntimeException("Cannot create DB Gateway log directory: {$logDir}");
        }

        $port = $this->allocatePort($session, $listenHost);
        $target = $this->hostPort(
            (string) $resource->target_host,
            (int) $resource->target_port
        );

        $listen = $this->hostPort($listenHost, $port);
        $logFile = $logDir.'/'.$session->public_id.'.log';
        $now = now();

        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $metadata['db'] = array_merge(
            is_array(data_get($metadata, 'db')) ? data_get($metadata, 'db') : [],
            [
                'engine' => $resource->engine,
                'gateway_pid' => null,
                'gateway_log_path' => $logFile,
                'gateway_started_at' => $now->toIso8601String(),
            ],
        );

        $session->forceFill([
            'status' => 'starting',
            'gateway_host' => $publicHost,
            'gateway_port' => $port,
            'metadata' => $metadata,
            'last_activity_at' => $now,
            'termination_reason' => null,
        ])->save();

        $env = [
            'SAG_INTERNAL_TOKEN' => $internalToken,
            'SAG_CONTROL_PLANE_URL' => $controlPlaneUrl,
            'SAG_DB_SESSION_UID' => $session->public_id,
            'SAG_DB_ENGINE' => (string) $resource->engine,
            'SAG_DB_LISTEN' => $listen,
            'SAG_DB_TARGET' => $target,
            'SAG_DB_NAME' => (string) ($resource->target_database ?? ''),
            'SAG_DB_USER' => (string) ($session->target_db_username ?? ''),
            'SAG_DB_ALLOWED_SOURCE_CIDR' => (string) ($session->allowed_source_cidr ?? ''),
        ];

        $environment = [];

        foreach ($env as $key => $value) {
            $environment[] = $key.'='.escapeshellarg($value);
        }

        $command = sprintf(
            'umask 027; %s nohup %s >> %s 2>&1 & echo $!',
            implode(' ', $environment),
            escapeshellarg($binary),
            escapeshellarg($logFile),
        );

        $pidRaw = trim((string) shell_exec(
            '/bin/sh -c '.escapeshellarg($command)
        ));

        if (!preg_match('/^\d+$/', $pidRaw)) {
            $this->markStartFailed($session, 'gateway_pid_not_returned');

            throw new RuntimeException('DB Gateway process PID was not returned.');
        }

        $pid = (int) $pidRaw;
        usleep(300000);

        if (!$this->isProcessRunning($pid)) {
            $this->markStartFailed($session, 'gateway_process_not_running');

            throw new RuntimeException(
                "DB Gateway process {$pid} stopped immediately. Check {$logFile}"
            );
        }

        $session->refresh();

        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $metadata['db'] = array_merge(
            is_array(data_get($metadata, 'db')) ? data_get($metadata, 'db') : [],
            [
                'gateway_pid' => $pid,
                'gateway_proc_start_ticks' => $this->processStartTicks($pid),
                'gateway_log_path' => $logFile,
                'gateway_started_at' => now()->toIso8601String(),
            ],
        );

        $session->forceFill([
            'metadata' => $metadata,
            'last_activity_at' => now(),
        ])->save();

        $this->writeAudit(
            $session,
            null,
            'access.db.gateway.started',
            'info',
            [
                'gateway_pid' => $pid,
                'gateway_host' => $publicHost,
                'gateway_port' => $port,
                'target' => $target,
                'engine' => $resource->engine,
            ],
        );

        return [
            'session_uid' => $session->public_id,
            'gateway_pid' => $pid,
            'gateway_host' => $publicHost,
            'gateway_port' => $port,
            'log_file' => $logFile,
        ];
    }

    public function terminate(
        DbAccessSession $session,
        ?int $actorUserId = null,
        string $reason = 'manual_revoke',
    ): array {
        if (in_array($session->status, ['terminated', 'expired'], true)) {
            return [
                'already_terminal' => true,
                'status' => $session->status,
            ];
        }

        // Deny new gateway connections before signalling the process.
        $session->forceFill([
            'status' => 'stopping',
            'termination_reason' => $reason,
            'terminated_by_user_id' => $actorUserId,
            'last_activity_at' => now(),
        ])->save();

        $gateway = $this->stopGateway($session);
        $confirmed = $this->gatewayStopConfirmed($session, $gateway);

        if (!$confirmed) {
            $this->writeAudit(
                $session, null, 'access.db.gateway.stop_failed', 'high',
                ['reason' => $reason, 'gateway_state' => $gateway['state']],
                $actorUserId,
            );
            throw new RuntimeException(
                'Gateway stop was not confirmed; session remains stopping and can be retried.'
            );
        }

        $now = now();
        $activity = $this->closeOpenActivity($session, 'session_terminated');
        $session->forceFill([
            'status' => 'terminated',
            'ended_at' => $now,
            'terminated_at' => $now,
            'terminated_by_user_id' => $actorUserId,
            'last_activity_at' => $now,
        ])->save();

        $this->writeAudit(
            $session, null, 'access.db.session.terminated', 'high',
            [
                'reason' => $reason,
                'gateway_stop_state' => $gateway['state'],
                'gateway_pid' => $gateway['pid'],
                'closed_connections' => $activity['closed_connections'],
                'interrupted_queries' => $activity['interrupted_queries'],
            ], $actorUserId,
        );

        return [
            'session_uid' => $session->public_id,
            'status' => 'terminated',
            'gateway' => $gateway,
            'activity' => $activity,
        ];
    }

    public function expire(DbAccessSession $session): array
    {
        if ($session->status === 'expired') {
            return [
                'session_uid' => $session->public_id,
                'status' => 'expired',
                'already_terminal' => true,
                'gateway' => ['state' => 'already_expired'],
                'activity' => ['closed_connections' => 0, 'interrupted_queries' => 0],
            ];
        }

        $session->forceFill([
            'status' => 'stopping',
            'termination_reason' => 'session_expired',
            'last_activity_at' => now(),
        ])->save();

        $gateway = $this->stopGateway($session);
        if (!$this->gatewayStopConfirmed($session, $gateway)) {
            $this->writeAudit(
                $session, null, 'access.db.gateway.stop_failed', 'high',
                ['reason' => 'session_expired', 'gateway_state' => $gateway['state']],
            );
            throw new RuntimeException(
                'Expired gateway stop was not confirmed; cleanup must retry.'
            );
        }

        $now = now();
        $activity = $this->closeOpenActivity($session, 'session_expired');
        $session->forceFill([
            'status' => 'expired',
            'ended_at' => $now,
            'last_activity_at' => $now,
        ])->save();

        $this->writeAudit(
            $session, null, 'access.session.expired_by_cleanup', 'info',
            [
                'expires_at' => $session->expires_at?->toIso8601String(),
                'closed_connections' => $activity['closed_connections'],
                'interrupted_queries' => $activity['interrupted_queries'],
            ],
        );

        return [
            'session_uid' => $session->public_id,
            'status' => 'expired',
            'gateway' => $gateway,
            'activity' => $activity,
        ];
    }

    private function gatewayStopConfirmed(DbAccessSession $session, array $gateway): bool
    {
        if ($gateway['stopped'] || $gateway['state'] === 'gateway_not_running') {
            return true;
        }

        // Sessions that have never started do not have a gateway process.
        // A missing PID on a started/stopping session is NOT evidence of shutdown.
        return $gateway['state'] === 'gateway_pid_not_recorded'
            && $session->started_at === null
            && $session->gateway_port === null;
    }

    private function closeOpenActivity(
        DbAccessSession $session,
        string $reason,
    ): array {
        $now = now();

        $closedConnections = DbConnection::query()
            ->where('session_id', $session->id)
            ->where('status', 'opened')
            ->update([
                'status' => 'closed',
                'closed_at' => $now,
                'close_reason' => $reason,
                'updated_at' => $now,
            ]);

        $interruptedQueries = 0;

        DbQueryEvent::query()
            ->where('session_id', $session->id)
            ->where('query_status', 'running')
            ->orderBy('id')
            ->get()
            ->each(function (DbQueryEvent $query) use (
                $now,
                $reason,
                &$interruptedQueries,
            ): void {
                $metadata = is_array($query->metadata) ? $query->metadata : [];
                $metadata['lifecycle'] = [
                    'reason' => $reason,
                    'interrupted_at' => $now->toIso8601String(),
                ];

                $duration = $query->duration_ms;

                if ($query->occurred_at) {
                    $duration = max(
                        0,
                        (int) Carbon::parse($query->occurred_at)
                            ->diffInMilliseconds($now)
                    );
                }

                $query->forceFill([
                    'query_status' => 'interrupted',
                    'ended_at' => $now,
                    'duration_ms' => $duration,
                    'error_code' => strtoupper($reason),
                    'error_message' => "Query interrupted because DB session {$reason}.",
                    'metadata' => $metadata,
                ])->save();

                $interruptedQueries++;
            });

        if ($interruptedQueries > 0) {
            $this->writeAudit(
                $session,
                null,
                'access.db.query.interrupted_by_lifecycle',
                'medium',
                [
                    'reason' => $reason,
                    'interrupted_queries' => $interruptedQueries,
                ],
            );
        }

        return [
            'closed_connections' => $closedConnections,
            'interrupted_queries' => $interruptedQueries,
        ];
    }

    protected function stopGateway(DbAccessSession $session): array
    {
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $pid = (int) data_get($metadata, 'db.gateway_pid', 0);

        if ($pid < 2) {
            return [
                'pid' => $pid ?: null,
                'state' => 'gateway_pid_not_recorded',
                'stopped' => false,
            ];
        }

        if (!$this->isProcessRunning($pid)) {
            return [
                'pid' => $pid,
                'state' => 'gateway_not_running',
                'stopped' => false,
            ];
        }

        if (!$this->isExpectedGatewayProcess($pid, $session)) {
            return [
                'pid' => $pid,
                'state' => 'gateway_pid_identity_mismatch',
                'stopped' => false,
            ];
        }

        $termSent = $this->sendSignal($pid, 15);
        $stoppedAfterTerm = $this->waitForExit($pid, 30, 100000);
        $killSent = false;

        if (!$stoppedAfterTerm && $this->isProcessRunning($pid)) {
            // Recheck identity before escalating: the original PID could have
            // exited and been reassigned while waiting for SIGTERM.
            if ($this->isExpectedGatewayProcess($pid, $session)) {
                $killSent = $this->sendSignal($pid, 9);
                $this->waitForExit($pid, 30, 100000);
            }
        }

        $stopped = !$this->isProcessRunning($pid);

        $state = match (true) {
            $stopped && $killSent => 'stopped_after_sigkill',
            $stopped => 'stopped_after_sigterm',
            !$termSent && !$killSent => 'signal_not_sent',
            default => 'stop_failed',
        };

        $metadata['db'] = array_merge(
            is_array(data_get($metadata, 'db')) ? data_get($metadata, 'db') : [],
            [
                'gateway_stopped_at' => $stopped ? now()->toIso8601String() : null,
                'gateway_stop_state' => $state,
                'gateway_stop_signal' => $killSent
                    ? 'SIGKILL'
                    : ($termSent ? 'SIGTERM' : null),
            ],
        );

        $session->forceFill([
            'metadata' => $metadata,
        ])->save();

        return [
            'pid' => $pid,
            'state' => $state,
            'stopped' => $stopped,
        ];
    }

    private function allocatePort(
        DbAccessSession $session,
        string $listenHost,
    ): int {
        if ($session->gateway_port) {
            return (int) $session->gateway_port;
        }

        $min = max(1, (int) config('sag.db_gateway.port_min', 16000));
        $max = min(65535, (int) config('sag.db_gateway.port_max', 17999));

        for ($port = $min; $port <= $max; $port++) {
            $reserved = DbAccessSession::query()
                ->where('gateway_port', $port)
                ->whereKeyNot($session->id)
                ->whereIn('status', ['created', 'starting', 'started'])
                ->exists();

            if (!$reserved && $this->portCanBind($listenHost, $port)) {
                return $port;
            }
        }

        throw new RuntimeException('No free DB Gateway port is available.');
    }

    private function assertStartable(DbAccessSession $session): void
    {
        if (!in_array($session->status, ['created', 'failed'], true)) {
            throw new RuntimeException(
                "Session {$session->public_id} cannot be started from status {$session->status}."
            );
        }

        if (!$session->resource || !$session->resource->is_active) {
            throw new RuntimeException('Неможливо запустити gateway: DB resource неактивний або недоступний.');
        }

        if (
            $session->mode === 'temporary'
            && !$session->expires_at
        ) {
            throw new RuntimeException('Temporary DB session requires expires_at.');
        }

        if ($session->expires_at?->isPast()) {
            throw new RuntimeException('DB session is already expired.');
        }

        if (
            empty($session->resource->target_host)
            || empty($session->resource->target_port)
        ) {
            throw new RuntimeException('Database resource target is incomplete.');
        }
    }

    private function markStartFailed(
        DbAccessSession $session,
        string $reason,
    ): void {
        $session->forceFill([
            'status' => 'failed',
            'ended_at' => now(),
            'termination_reason' => $reason,
            'last_activity_at' => now(),
        ])->save();

        $this->writeAudit(
            $session,
            null,
            'access.db.gateway.start_failed',
            'high',
            ['reason' => $reason],
        );
    }

    private function portCanBind(string $host, int $port): bool
    {
        $address = $this->hostPort($host, $port);
        $socket = @stream_socket_server(
            'tcp://'.$address,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function hostPort(string $host, int $port): string
    {
        $host = trim($host);

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '['.$host.']:'.$port;
        }

        return $host.':'.$port;
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 1) {
            return false;
        }

        $statusPath = '/proc/'.$pid.'/status';

        if (!is_readable($statusPath)) {
            return false;
        }

        $status = (string) file_get_contents($statusPath);

        return !preg_match('/^State:\\s+Z/m', $status);
    }

    /**
     * Reject recycled PIDs and unrelated processes even if their arguments
     * happen to mention sag-db-gateway. Linux /proc is required by CE worker.
     */
    private function isExpectedGatewayProcess(int $pid, DbAccessSession $session): bool
    {
        $binary = realpath((string) config('sag.db_gateway.binary', ''));
        $executable = @readlink('/proc/'.$pid.'/exe');

        if ($binary === false || $executable === false || $executable !== $binary) {
            return false;
        }

        $environment = @file_get_contents('/proc/'.$pid.'/environ');
        if ($environment === false) {
            return false;
        }

        // Compare the exact NUL-terminated environment entry. Never log
        // /proc/environ: the process also holds the internal API token.
        $entry = 'SAG_DB_SESSION_UID='.$session->public_id."\0";
        if (!str_contains($environment, "\0".$entry) && !str_starts_with($environment, $entry)) {
            return false;
        }

        $storedTicks = data_get($session->metadata, 'db.gateway_proc_start_ticks');
        if ($storedTicks !== null) {
            $liveTicks = $this->processStartTicks($pid);
            if ($liveTicks === null || !hash_equals((string) $storedTicks, $liveTicks)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Linux /proc/[pid]/stat field 22, counted after the command field.
     * Save on launch to detect PID reuse. A legacy session without ticks
     * still must pass exact executable and session UID checks.
     */
    private function processStartTicks(int $pid): ?string
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if ($stat === false) {
            return null;
        }

        $endCommand = strrpos($stat, ') ');
        if ($endCommand === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $endCommand + 2)));

        return is_array($fields) && isset($fields[19]) && ctype_digit($fields[19])
            ? $fields[19]
            : null;
    }

    private function waitForExit(
        int $pid,
        int $attempts,
        int $sleepMicroseconds,
    ): bool {
        for ($i = 0; $i < $attempts; $i++) {
            if (!$this->isProcessRunning($pid)) {
                return true;
            }

            usleep($sleepMicroseconds);
        }

        return !$this->isProcessRunning($pid);
    }

    private function sendSignal(int $pid, int $signal): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, $signal);
        }

        exec(
            '/bin/kill -'.(int) $signal.' '.(int) $pid.' 2>/dev/null',
            $ignored,
            $exitCode,
        );

        return $exitCode === 0;
    }

    private function writeAudit(
        DbAccessSession $session,
        ?DbConnection $connection,
        string $eventType,
        string $severity,
        array $eventData,
        ?int $actorUserId = null,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $actorUserId,
            'resource_id' => $session->resource_id,
            'session_id' => $session->id,
            'db_connection_id' => $connection?->id,
            'category' => 'db_access',
            'event_type' => $eventType,
            'severity' => $severity,
            'ip_address' => $connection?->client_address,
            'event_data' => $eventData,
            'occurred_at' => now(),
        ]);
    }
}
