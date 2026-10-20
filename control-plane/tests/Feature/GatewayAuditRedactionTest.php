<?php

namespace Tests\Feature;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\DbConnection;
use App\Models\DbQueryEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GatewayAuditRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_query_endpoint_never_persists_sql_or_error_text(): void
    {
        config()->set('sag.internal_token', 'integration-secret');
        $user = User::factory()->create();
        $resource = DatabaseResource::query()->create([
            'name' => 'Sensitive test resource',
            'engine' => 'postgresql',
            'target_host' => '127.0.0.1',
            'target_port' => 5432,
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);
        $session = DbAccessSession::query()->create([
            'public_id' => 'SAG-AUDIT-TEST',
            'resource_id' => $resource->id,
            'owner_user_id' => $user->id,
            'mode' => 'temporary',
            'status' => 'started',
            'expires_at' => now()->addHour(),
        ]);
        DbConnection::query()->create([
            'public_id' => 'CONN-AUDIT-TEST',
            'session_id' => $session->id,
            'resource_id' => $resource->id,
            'owner_user_id' => $user->id,
            'engine' => 'postgresql',
            'status' => 'opened',
            'opened_at' => now(),
        ]);

        $this->withHeader('X-SAG-Internal-Token', 'integration-secret')
            ->postJson('/api/internal/db/query-event', [
                'session_uid' => $session->public_id,
                'connection_uid' => 'CONN-AUDIT-TEST',
                'query_uid' => 'QUERY-AUDIT-TEST',
                'db_engine' => 'postgresql',
                'query_type' => 'UPDATE',
                'risk_level' => 'high',
                'query_text_masked' => "UPDATE users SET password='hidden-secret'",
                'normalized_sql' => "UPDATE users SET token='another-secret'",
                'error_message_masked' => "password=third-secret",
                'query_hash' => str_repeat('a', 64),
                'status' => 'failed',
                'started_at' => now()->toIso8601String(),
            ])->assertOk();

        $event = DbQueryEvent::query()->where('query_uid', 'QUERY-AUDIT-TEST')->firstOrFail();
        $this->assertNull($event->sql_text);
        $this->assertNull($event->error_message);
        $this->assertTrue($event->sql_redacted);
        $this->assertSame(str_repeat('a', 64), $event->sql_hash);
        $this->assertSame('UPDATE', $event->statement_type);
    }
}
