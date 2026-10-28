<?php

namespace App\Console\Commands;

use App\Services\Credentials\AuthorizedGatewayVault;
use App\Services\Credentials\ResourceCredentialVault;
use Illuminate\Console\Command;
use Throwable;

final class TakeGatewayIpcCredentials extends Command
{
    protected $signature = 'sag:take-gateway-ipc-credentials {session_uid} {resource_id} {--peer-pid=} {--peer-start-ticks=}';
    protected $description = 'Private IPC worker bootstrap through inherited pipe FD 3';
    protected $hidden = true;

    public function handle(AuthorizedGatewayVault $authorization, ResourceCredentialVault $vault): int
    {
        // Refuse a terminal, regular file, or absent descriptor before consuming
        // bootstrap. The trusted worker alone supplies the anonymous output pipe.
        $pipe = @fopen('php://fd/3', 'wb');
        $stat = $pipe === false ? false : fstat($pipe);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0010000) {
            if (is_resource($pipe)) { fclose($pipe); }
            return self::FAILURE;
        }
        try {
            $uid = (string) $this->argument('session_uid');
            $resource = (string) $this->argument('resource_id');
            $pid = (string) $this->option('peer-pid');
            $ticks = (string) $this->option('peer-start-ticks');
            if ($uid === '' || strlen($uid) > 128 || !ctype_digit($resource) || strlen($resource) > 18
                || !ctype_digit($pid) || strlen($pid) > 10 || !ctype_digit($ticks) || strlen($ticks) > 24) {
                return self::FAILURE;
            }
            $credentials = $authorization->takeBootstrapCredentials($uid, (int) $resource, (int) $pid, $ticks, $vault);
            $frame = json_encode($credentials, JSON_THROW_ON_ERROR)."\n";
            if (strlen($frame) > 8192 || fwrite($pipe, $frame) !== strlen($frame)) {
                return self::FAILURE;
            }
            return self::SUCCESS;
        } catch (Throwable) {
            // Exception messages and credentials must never reach console/logs.
            return self::FAILURE;
        } finally {
            fclose($pipe);
        }
    }
}
