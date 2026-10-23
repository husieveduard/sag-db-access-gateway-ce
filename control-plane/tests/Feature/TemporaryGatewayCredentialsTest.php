<?php

namespace Tests\Feature;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\GatewayTemporaryCredential;
use App\Models\User;
use App\Services\Credentials\TemporaryGatewayCredentials;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemporaryGatewayCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(string $mode = 'temporary', string $engine = 'postgresql'): DbAccessSession
    {
        $owner = User::factory()->create();
        $resource = DatabaseResource::query()->create([
            'name' => 'temp-gateway-'.bin2hex(random_bytes(4)),
            'engine' => $engine,
            'target_host' => 'lab.example',
            'target_port' => match ($engine) {
                'mysql' => 3306,
                'mssql' => 1433,
                default => 5432,
            },
            'target_database' => 'sag_ce_lab',
            'created_by_user_id' => $owner->id,
        ]);
        return DbAccessSession::query()->create([
            'public_id' => 'SAG-TEST-'.bin2hex(random_bytes(8)),
            'resource_id' => $resource->id,
            'owner_user_id' => $owner->id,
            'mode' => $mode,
            'status' => 'created',
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_issues_only_once_and_never_persists_plaintext_password(): void
    {
        $session = $this->makeSession();
        $service = app(TemporaryGatewayCredentials::class);
        $issued = $service->issue($session);
        $this->assertStringStartsWith('sag_', $issued['username']);
        $this->assertGreaterThanOrEqual(40, strlen($issued['password']));

        $record = GatewayTemporaryCredential::query()->firstOrFail();
        $this->assertSame($issued['username'], $record->username);
        $this->assertNotSame($issued['password'], $record->password_hash);
        $this->assertStringNotContainsString($issued['password'], json_encode($record->toArray()));

        $session->update(['status' => 'started']);
        $this->assertTrue($service->verify($session->fresh(), $issued['username'], $issued['password']));
        $this->assertFalse($service->verify($session->fresh(), $issued['username'], 'wrong-password'));
        $this->assertFalse($service->verify($session->fresh(), 'wrong-user', $issued['password']));

        $this->expectException(DomainException::class);
        $service->issue($session->fresh());
    }

    public function test_expired_or_terminated_session_cannot_authenticate(): void
    {
        $session = $this->makeSession();
        $service = app(TemporaryGatewayCredentials::class);
        $issued = $service->issue($session);

        $session->update(['status' => 'terminated']);
        $this->assertFalse($service->verify($session->fresh(), $issued['username'], $issued['password']));

        $session->update(['status' => 'started', 'expires_at' => now()->subSecond()]);
        $this->assertFalse($service->verify($session->fresh(), $issued['username'], $issued['password']));
    }

    public function test_persistent_session_cannot_receive_temporary_credentials(): void
    {
        $this->expectException(DomainException::class);
        app(TemporaryGatewayCredentials::class)->issue($this->makeSession('persistent'));
    }
    public function test_temporary_credentials_are_engine_independent(): void
    {
        foreach (['postgresql', 'mysql', 'mssql'] as $engine) {
            $session = $this->makeSession('temporary', $engine);
            $service = app(TemporaryGatewayCredentials::class);
            $issued = $service->issue($session);
            $session->update(['status' => 'started']);
            $this->assertTrue($service->verify($session->fresh(), $issued['username'], $issued['password']), $engine);
            $this->assertFalse($service->verify($session->fresh(), $issued['username'], 'incorrect'), $engine);
        }
    }

    public function test_failed_or_expired_session_cannot_issue_credentials(): void
    {
        $service = app(TemporaryGatewayCredentials::class);
        $failed = $this->makeSession();
        $failed->update(['status' => 'failed']);
        $this->expectException(DomainException::class);
        $service->issue($failed->fresh());
    }

    public function test_expired_created_session_cannot_issue_credentials(): void
    {
        $session = $this->makeSession();
        $session->update(['expires_at' => now()->subSecond()]);
        $this->expectException(DomainException::class);
        app(TemporaryGatewayCredentials::class)->issue($session->fresh());
    }

}
