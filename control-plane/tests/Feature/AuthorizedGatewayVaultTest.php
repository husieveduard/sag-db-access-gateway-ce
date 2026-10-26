<?php

namespace Tests\Feature;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\Credentials\AuthorizedGatewayVault;
use App\Services\Credentials\ResourceCredentialVault;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class AuthorizedGatewayVaultTest extends TestCase
{
    use RefreshDatabase;

    private function makeVaultSession(): DbAccessSession
    {
        $user = User::factory()->create();
        $resource = DatabaseResource::query()->create([
            'name' => 'Vault boundary test',
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
        $vault = Mockery::mock(ResourceCredentialVault::class);
        $vault->shouldReceive('retrieveForGateway')->once()
            ->with($session->resource_id)
            ->andReturn(['username' => 'gateway_service', 'password' => 'protected-secret']);
        $this->assertSame('gateway_service', app(AuthorizedGatewayVault::class)
            ->forActiveSession($session->public_id, $vault)['username']);
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
            $vault = Mockery::mock(ResourceCredentialVault::class);
            $vault->shouldNotReceive('retrieveForGateway');
            try {
                app(AuthorizedGatewayVault::class)->forActiveSession($session->public_id, $vault);
                $this->fail('Unauthorized session accepted: '.$case);
            } catch (DomainException $exception) {
                $this->assertSame('Gateway session is not authorized.', $exception->getMessage());
            }
        }
    }

    public function test_missing_session_does_not_access_vault(): void
    {
        $vault = Mockery::mock(ResourceCredentialVault::class);
        $vault->shouldNotReceive('retrieveForGateway');
        $this->expectException(DomainException::class);
        app(AuthorizedGatewayVault::class)->forActiveSession('nonexistent', $vault);
    }
}
