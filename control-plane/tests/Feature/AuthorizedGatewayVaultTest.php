<?php

namespace Tests\Feature;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\Credentials\AuthorizedGatewayVault;
use App\Services\Credentials\ResourceCredentialVault;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthorizedGatewayVaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sag_credentials.encryption_key', 'base64:'.base64_encode(random_bytes(32)));
    }

    private function makeVaultSession(): DbAccessSession
    {
        $user = User::factory()->create();
        $resource = DatabaseResource::query()->create([
            'name' => 'Vault boundary test '.bin2hex(random_bytes(8)),
            'engine' => 'postgresql',
            'target_host' => 'db.example',
            'target_port' => 5432,
            'target_database' => 'safe_db',
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);
        return DbAccessSession::query()->create([
            'public_id' => 'SAG-VAULT-'.bin2hex(random_bytes(8)),
            'resource_id' => $resource->id,
            'owner_user_id' => $user->id,
            'mode' => 'temporary',
            'status' => 'started',
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_active_session_is_bound_to_its_resource(): void
    {
        $session = $this->makeVaultSession();
        $vault = app(ResourceCredentialVault::class);
        $vault->replace((int) $session->resource_id, 'gateway_service', 'protected-secret', (int) $session->owner_user_id);
        $credentials = app(AuthorizedGatewayVault::class)->forActiveSession($session->public_id, $vault);
        $this->assertSame('gateway_service', $credentials['username']);
        $this->assertSame('protected-secret', $credentials['password']);
    }

    public function test_bound_vault_access_rejects_wrong_resource_and_revocation(): void
    {
        $session = $this->makeVaultSession();
        $vault = app(ResourceCredentialVault::class);
        $vault->replace((int) $session->resource_id, 'broker_user', 'vault-fixture', (int) $session->owner_user_id);
        $authorization = app(AuthorizedGatewayVault::class);

        $credentials = $authorization->forBoundActiveSession($session->public_id, (int) $session->resource_id, $vault);
        $this->assertSame('broker_user', $credentials['username']);

        try {
            $authorization->forBoundActiveSession($session->public_id, (int) $session->resource_id + 1, $vault);
            $this->fail('Wrong resource was authorized.');
        } catch (DomainException $exception) {
            $this->assertSame('Gateway session is not authorized.', $exception->getMessage());
        }

        $session->update(['status' => 'terminated']);
        $this->expectException(DomainException::class);
        $authorization->forBoundActiveSession($session->public_id, (int) $session->resource_id, $vault);
    }

    public function test_revoked_expired_or_inactive_resource_denies_before_decryption(): void
    {
        foreach (['terminated', 'expired', 'created', 'persistent', 'resource_inactive'] as $case) {
            $session = $this->makeVaultSession();
            if ($case === 'expired') {
                $session->update(['expires_at' => now()->subMinute()]);
            } elseif ($case === 'persistent') {
                $session->update(['mode' => 'persistent']);
            } elseif ($case === 'resource_inactive') {
                $session->resource->update(['is_active' => false]);
            } else {
                $session->update(['status' => $case]);
            }
            $vault = app(ResourceCredentialVault::class);
            try {
                app(AuthorizedGatewayVault::class)->forActiveSession($session->public_id, $vault);
                $this->fail('Unauthorized session accepted: '.$case);
            } catch (DomainException $exception) {
                $this->assertSame('Gateway session is not authorized.', $exception->getMessage());
            }
        }
    }

    public function test_ipc_probe_requires_exact_live_session_and_resource(): void
    {
        $session = $this->makeVaultSession();
        $service = app(AuthorizedGatewayVault::class);
        $this->assertTrue($service->isActiveSessionResource($session->public_id, (int) $session->resource_id));
        $this->assertFalse($service->isActiveSessionResource($session->public_id, (int) $session->resource_id + 1));
        $this->assertFalse($service->isActiveSessionResource('invalid', (int) $session->resource_id));
        $session->update(['status' => 'terminated']);
        $this->assertFalse($service->isActiveSessionResource($session->public_id, (int) $session->resource_id));
    }

    public function test_ipc_probe_denies_expired_or_inactive_resource(): void
    {
        $session = $this->makeVaultSession();
        $service = app(AuthorizedGatewayVault::class);
        $session->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($service->isActiveSessionResource($session->public_id, (int) $session->resource_id));
        $session->update(['expires_at' => now()->addHour()]);
        $session->resource->update(['is_active' => false]);
        $this->assertFalse($service->isActiveSessionResource($session->public_id, (int) $session->resource_id));
    }

    public function test_local_ipc_command_checks_real_session_binding(): void
    {
        $session = $this->makeVaultSession();
        $this->artisan('sag:verify-gateway-ipc-session', [
            'session_uid' => $session->public_id,
            'resource_id' => (string) $session->resource_id,
        ])->expectsOutput('ALLOW')->assertExitCode(0);
        $this->artisan('sag:verify-gateway-ipc-session', [
            'session_uid' => $session->public_id,
            'resource_id' => (string) ($session->resource_id + 1),
        ])->expectsOutput('DENY')->assertExitCode(1);
        $session->update(['status' => 'terminated']);
        $this->artisan('sag:verify-gateway-ipc-session', [
            'session_uid' => $session->public_id,
            'resource_id' => (string) $session->resource_id,
        ])->expectsOutput('DENY')->assertExitCode(1);
    }

    public function test_starting_session_requires_exact_recorded_process_identity(): void
    {
        $session = $this->makeVaultSession();
        $session->forceFill([
            'status' => 'starting',
            'metadata' => ['db' => [
                'gateway_pid' => 12345,
                'gateway_proc_start_ticks' => '987654321',
            ]],
        ])->save();

        $gate = app(AuthorizedGatewayVault::class);
        $uid = $session->public_id;
        $resource = (int) $session->resource_id;

        $this->assertTrue($gate->isStartingSessionBoundToProcess($uid, $resource, 12345, '987654321'));
        $this->assertFalse($gate->isStartingSessionBoundToProcess($uid, $resource, 12346, '987654321'));
        $this->assertFalse($gate->isStartingSessionBoundToProcess($uid, $resource, 12345, '987654322'));
        $this->assertFalse($gate->isStartingSessionBoundToProcess($uid, $resource + 1, 12345, '987654321'));
        $this->assertFalse($gate->isStartingSessionBoundToProcess($uid, $resource, 12345, 'invalid'));

        // Existing Vault retrieval must still deny a starting session.
        $this->expectException(DomainException::class);
        $gate->forBoundActiveSession($uid, $resource, app(ResourceCredentialVault::class));
    }

    public function test_starting_process_identity_is_denied_after_expiry_or_deactivation(): void
    {
        $session = $this->makeVaultSession();
        $session->forceFill([
            'status' => 'starting',
            'metadata' => ['db' => [
                'gateway_pid' => 12345,
                'gateway_proc_start_ticks' => '987654321',
            ]],
        ])->save();
        $gate = app(AuthorizedGatewayVault::class);
        $uid = $session->public_id;
        $resource = (int) $session->resource_id;

        $session->update(['expires_at' => now()->subSecond()]);
        $this->assertFalse($gate->isStartingSessionBoundToProcess($uid, $resource, 12345, '987654321'));
        $session->update(['expires_at' => now()->addHour()]);
        $session->resource->update(['is_active' => false]);
        $this->assertFalse($gate->isStartingSessionBoundToProcess($uid, $resource, 12345, '987654321'));
    }

    public function test_local_ipc_bootstrap_command_requires_matching_process_identity(): void
    {
        $session = $this->makeVaultSession();
        $session->forceFill([
            'status' => 'starting',
            'metadata' => ['db' => [
                'gateway_pid' => 12345,
                'gateway_proc_start_ticks' => '987654321',
            ]],
        ])->save();
        $params = [
            'session_uid' => $session->public_id,
            'resource_id' => (string) $session->resource_id,
        ];

        $this->artisan('sag:verify-gateway-ipc-session', $params)
            ->expectsOutput('DENY')->assertExitCode(1);

        $this->artisan('sag:verify-gateway-ipc-session', $params + [
            '--peer-pid' => '12345',
            '--peer-start-ticks' => '987654321',
        ])->expectsOutput('ALLOW')->assertExitCode(0);

        $this->artisan('sag:verify-gateway-ipc-session', $params + [
            '--peer-pid' => '12345',
            '--peer-start-ticks' => '987654322',
        ])->expectsOutput('DENY')->assertExitCode(1);
    }

    public function test_starting_bootstrap_can_be_consumed_only_once(): void
    {
        $session = $this->makeVaultSession();
        $session->forceFill([
            'status' => 'starting',
            'metadata' => ['db' => [
                'gateway_pid' => 12345,
                'gateway_proc_start_ticks' => '987654321',
            ]],
        ])->save();

        $args = [
            'session_uid' => $session->public_id,
            'resource_id' => (string) $session->resource_id,
            '--peer-pid' => '12345',
            '--peer-start-ticks' => '987654321',
            '--consume-bootstrap' => true,
        ];

        $this->artisan('sag:verify-gateway-ipc-session', $args)
            ->expectsOutput('ALLOW')->assertExitCode(0);
        $this->artisan('sag:verify-gateway-ipc-session', $args)
            ->expectsOutput('DENY')->assertExitCode(1);

        $this->assertNotNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
        $this->assertFalse(app(AuthorizedGatewayVault::class)->isActiveSessionResource(
            $session->public_id, (int) $session->resource_id
        ));
    }

    public function test_bootstrap_consume_denies_wrong_process_and_revoked_session(): void
    {
        $session = $this->makeVaultSession();
        $session->forceFill([
            'status' => 'starting',
            'metadata' => ['db' => [
                'gateway_pid' => 12345,
                'gateway_proc_start_ticks' => '987654321',
            ]],
        ])->save();

        $gate = app(AuthorizedGatewayVault::class);
        $uid = $session->public_id;
        $resourceId = (int) $session->resource_id;

        $this->assertFalse($gate->consumeStartingProcessBootstrap($uid, $resourceId, 12346, '987654321'));
        $this->assertFalse($gate->consumeStartingProcessBootstrap($uid, $resourceId, 12345, '987654322'));
        $this->assertFalse($gate->consumeStartingProcessBootstrap($uid, $resourceId + 1, 12345, '987654321'));
        $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));

        $session->update(['status' => 'terminated']);
        $this->assertFalse($gate->consumeStartingProcessBootstrap($uid, $resourceId, 12345, '987654321'));
        $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
    }

    public function test_process_bound_vault_bootstrap_is_atomic_and_one_time(): void
    {
        $session = $this->makeVaultSession();
        $session->forceFill([
            'status' => 'starting',
            'metadata' => ['db' => [
                'gateway_pid' => 12345,
                'gateway_proc_start_ticks' => '987654321',
            ]],
        ])->save();
        $vault = app(ResourceCredentialVault::class);
        $vault->replace((int) $session->resource_id, 'broker_test', 'vault-only-fixture', (int) $session->owner_user_id);
        $gate = app(AuthorizedGatewayVault::class);
        $uid = $session->public_id;
        $resource = (int) $session->resource_id;

        try {
            $gate->takeBootstrapCredentials($uid, $resource, 12345, 'wrong', $vault);
            $this->fail('Wrong process was granted credentials.');
        } catch (DomainException $e) {
            $this->assertSame('Gateway bootstrap is not authorized.', $e->getMessage());
        }
        $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));

        $credentials = $gate->takeBootstrapCredentials($uid, $resource, 12345, '987654321', $vault);
        $this->assertSame('broker_test', $credentials['username']);
        $this->assertSame('vault-only-fixture', $credentials['password']);
        $this->assertNotNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));

        $this->expectException(DomainException::class);
        $gate->takeBootstrapCredentials($uid, $resource, 12345, '987654321', $vault);
    }

    public function test_missing_vault_secret_does_not_spend_bootstrap(): void
    {
        $session = $this->makeVaultSession();
        $session->forceFill([
            'status' => 'starting',
            'metadata' => ['db' => [
                'gateway_pid' => 12345,
                'gateway_proc_start_ticks' => '987654321',
            ]],
        ])->save();
        try {
            app(AuthorizedGatewayVault::class)->takeBootstrapCredentials(
                $session->public_id, (int) $session->resource_id, 12345,
                '987654321', app(ResourceCredentialVault::class)
            );
            $this->fail('Missing credentials were granted.');
        } catch (DomainException $e) {
            $this->assertSame('Backend credentials are unavailable.', $e->getMessage());
        }
        $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
    }

    public function test_missing_session_does_not_access_vault(): void
    {
        $vault = app(ResourceCredentialVault::class);
        $this->expectException(DomainException::class);
        app(AuthorizedGatewayVault::class)->forActiveSession('nonexistent', $vault);
    }
}
