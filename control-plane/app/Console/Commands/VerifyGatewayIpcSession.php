<?php

namespace App\Console\Commands;

use App\Services\Credentials\AuthorizedGatewayVault;
use Illuminate\Console\Command;

// Non-secret worker-side authorization check. This command must only be
// invoked from a trusted local worker; the Unix server verifies peer UID.
// Never return Vault passwords, session metadata, or diagnostics to stdout.
final class VerifyGatewayIpcSession extends Command
{
    protected $signature = 'sag:verify-gateway-ipc-session {session_uid} {resource_id}';
    protected $description = 'Check a live gateway session/resource binding (non-secret)';

    public function handle(AuthorizedGatewayVault $authorization): int
    {
        $uid = (string) $this->argument('session_uid');
        $rawId = (string) $this->argument('resource_id');

        if (strlen($uid) > 128 || $uid === '' || !ctype_digit($rawId) || strlen($rawId) > 18) {
            $this->line('DENY');
            return self::FAILURE;
        }

        $allowed = $authorization->isActiveSessionResource($uid, (int) $rawId);
        $this->line($allowed ? 'ALLOW' : 'DENY');

        return $allowed ? self::SUCCESS : self::FAILURE;
    }
}
