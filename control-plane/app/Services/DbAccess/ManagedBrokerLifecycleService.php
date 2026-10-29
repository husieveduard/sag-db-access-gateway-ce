<?php

namespace App\Services\DbAccess;

use App\Models\DbAccessSession;
use RuntimeException;

/** Explicitly enabled native runtime; shares the audited laboratory launcher. */
final class ManagedBrokerLifecycleService extends LabManagedBrokerLifecycleService
{
    public function start(DbAccessSession $session): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid')
            || config('sag.db_gateway.managed_runtime.enabled') !== true) {
            throw new RuntimeException('Managed runtime broker is disabled in this environment.');
        }

        $listenHost = trim((string) config('sag.db_gateway.managed_runtime.listen_host'));
        $publicHost = trim((string) config('sag.db_gateway.managed_runtime.public_host'));
        if (! in_array($listenHost, ['0.0.0.0', '127.0.0.1'], true)
            || ($listenHost === '127.0.0.1' && app()->environment('production'))
            || $publicHost === '' || strtoupper($publicHost) === 'CHANGE_ME') {
            throw new RuntimeException('Managed runtime listener configuration is invalid.');
        }

        return $this->startNative($session, 'managed_runtime', $listenHost, $publicHost, false);
    }
}
