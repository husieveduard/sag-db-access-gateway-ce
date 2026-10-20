<?php

namespace Tests\Feature;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\DbAccess\DbGatewayLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LiveGatewayProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_linux_gateway_starts_and_is_stopped_with_verified_identity(): void
    {
        $binary = (string) getenv('SAG_CI_LIVE_GATEWAY_BINARY');
        if ($binary === '' || !is_executable($binary) || !is_dir('/proc')) {
            $this->markTestSkipped('Linux gateway binary is only built for CI live-process job.');
        }

        // Choose a port on loopback, never publish to the network.
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$probe) {
            $this->fail('Cannot reserve a free local TCP port for the gateway test.');
        }
        $address = stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr(strrchr($address, ':'), 1);

        $user = User::factory()->create();
        $resource = DatabaseResource::query()->create([
            'name' => 'CI real gateway resource',
            'engine' => 'postgresql',
            'target_host' => '127.0.0.1',
            'target_port' => 5432,
            'target_database' => 'sag_ci_live_integration',
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);
        $session = DbAccessSession::query()->create([
            'public_id' => 'SAG-LIVE-'.Str::upper(Str::random(10)),
            'resource_id' => $resource->id,
            'owner_user_id' => $user->id,
            'status' => 'created',
            'mode' => 'temporary',
            'expires_at' => now()->addMinutes(10),
            'allowed_source_cidr' => '127.0.0.1/32',
        ]);
        $logDir = sys_get_temp_dir().'/sag-live-'.Str::random(10);
        config()->set('sag.db_gateway.binary', $binary);
        config()->set('sag.db_gateway.listen_host', '127.0.0.1');
        config()->set('sag.db_gateway.public_host', '127.0.0.1');
        config()->set('sag.db_gateway.port_min', $port);
        config()->set('sag.db_gateway.port_max', $port);
        config()->set('sag.db_gateway.log_dir', $logDir);
        config()->set('sag.db_gateway.control_plane_url', 'http://127.0.0.1:1');
        config()->set('sag.internal_token', 'ci-only-internal-token');

        $pid = null;
        try {
            $result = (new DbGatewayLifecycleService())->start($session);
            $pid = (int) $result['gateway_pid'];
            $this->assertGreaterThan(1, $pid);
            $this->assertTrue(is_dir('/proc/'.$pid));
            $this->assertNotEmpty(data_get($session->fresh()->metadata, 'db.gateway_proc_start_ticks'));

            $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $connectErr, $connectMsg, 3);
            $this->assertNotFalse($socket, 'Gateway failed to listen on the allocated loopback port.');
            if (is_resource($socket)) {
                fclose($socket);
            }

            $ended = (new DbGatewayLifecycleService())->terminate(
                $session->fresh(), $user->id, 'ci_live_process_revoke'
            );
            $this->assertSame('terminated', $ended['status']);
            $this->assertTrue($ended['gateway']['stopped']);
            $this->assertSame('terminated', $session->fresh()->status);
            $this->assertNotNull($session->fresh()->terminated_at);
        } finally {
            // Fail-safe only for an owned test binary. Never signal any other
            // process that might reuse the PID after a test failure.
            if ($pid && @readlink('/proc/'.$pid.'/exe') === realpath($binary)) {
                @posix_kill($pid, 9);
            }
            foreach (glob($logDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($logDir);
        }
    }
}
