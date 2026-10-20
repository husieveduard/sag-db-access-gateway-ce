<?php

namespace Tests\Feature;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\DbAccess\DbGatewayLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LifecycleHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeGatewaySession(string $status = 'started', bool $expired = false): DbAccessSession
    {
        $user = User::factory()->create();
        $resource = DatabaseResource::query()->create([
            'name' => 'Lifecycle Test Resource',
            'engine' => 'postgresql',
            'target_host' => '127.0.0.1',
            'target_port' => 5432,
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);

        return DbAccessSession::query()->create([
            'public_id' => 'SAG-LIFECYCLE-TEST',
            'resource_id' => $resource->id,
            'owner_user_id' => $user->id,
            'mode' => 'temporary',
            'status' => $status,
            'gateway_port' => 16001,
            'started_at' => now()->subMinute(),
            'expires_at' => $expired ? now()->subSecond() : now()->addHour(),
            'metadata' => ['db' => ['gateway_pid' => 1001]],
        ]);
    }

    private function lifecycle(string $state, bool $stopped = false): DbGatewayLifecycleService
    {
        return new class($state, $stopped) extends DbGatewayLifecycleService {
            public function __construct(private string $testState, private bool $testStopped) {}

            protected function stopGateway(DbAccessSession $session): array
            {
                return ['pid' => 1001, 'state' => $this->testState, 'stopped' => $this->testStopped];
            }
        };
    }

    public function test_failed_termination_keeps_nonterminal_state_and_preserves_open_activity(): void
    {
        $session = $this->makeGatewaySession();
        $this->expectException(RuntimeException::class);
        try {
            $this->lifecycle('signal_not_sent')->terminate($session, null, 'security_revoke');
        } finally {
            $this->assertSame('stopping', $session->fresh()->status);
            $this->assertSame('security_revoke', $session->fresh()->termination_reason);
            $this->assertNull($session->fresh()->ended_at);
        }
    }

    public function test_retry_of_failed_termination_can_complete(): void
    {
        $session = $this->makeGatewaySession();
        try {
            $this->lifecycle('stop_failed')->terminate($session);
        } catch (RuntimeException) {}

        $result = $this->lifecycle('stopped_after_sigterm', true)->terminate($session->fresh());
        $this->assertSame('terminated', $result['status']);
        $this->assertSame('terminated', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->ended_at);
    }

    public function test_failed_expiry_is_retried_by_cleanup(): void
    {
        $session = $this->makeGatewaySession('started', true);
        $service = $this->lifecycle('gateway_pid_command_mismatch');

        $this->app->instance(DbGatewayLifecycleService::class, $service);
        $this->artisan('sag:cleanup-expired')->assertFailed();
        $this->assertSame('stopping', $session->fresh()->status);

        $this->app->instance(DbGatewayLifecycleService::class, $this->lifecycle('gateway_not_running'));
        $this->artisan('sag:cleanup-expired')->assertSuccessful();
        $this->assertSame('expired', $session->fresh()->status);
    }

    public function test_cleanup_retries_manual_termination_with_original_reason(): void
    {
        $session = $this->makeGatewaySession('stopping');
        $session->forceFill(['termination_reason' => 'manual_revoke'])->save();
        $this->app->instance(DbGatewayLifecycleService::class, $this->lifecycle('gateway_not_running'));

        $this->artisan('sag:cleanup-expired')->assertSuccessful();

        $this->assertSame('terminated', $session->fresh()->status);
        $this->assertSame('manual_revoke', $session->fresh()->termination_reason);
    }
    public function test_gateway_must_not_signal_an_unrelated_running_process(): void
    {
        if (!is_dir('/proc') || !function_exists('getmypid')) {
            $this->markTestSkipped('Linux /proc required.');
        }

        $session = $this->makeGatewaySession();
        // Use the live PHP test runner PID. A false positive could kill CI:
        // this regression must reject it before any SIGTERM is attempted.
        $session->forceFill([
            'metadata' => ['db' => ['gateway_pid' => getmypid()]],
        ])->save();

        config()->set('sag.db_gateway.binary', '/usr/local/bin/sag-db-gateway');

        try {
            (new DbGatewayLifecycleService())->terminate($session, null, 'security_test');
            $this->fail('Unrelated process PID must not be treated as a gateway.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('stop was not confirmed', $exception->getMessage());
        }

        $this->assertSame('stopping', $session->fresh()->status);
        $this->assertTrue(is_dir('/proc/'.getmypid()));
    }

}
