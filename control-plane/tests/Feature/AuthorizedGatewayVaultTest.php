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

    public function test_missing_session_does_not_access_vault(): void
    {
        $vault = app(ResourceCredentialVault::class);
        $this->expectException(DomainException::class);
        app(AuthorizedGatewayVault::class)->forActiveSession('nonexistent', $vault);
    }
}
