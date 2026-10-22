<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogUxTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role,
            'is_active' => true,
            'mfa_totp_secret' => 'TESTSECRET123456',
            'mfa_enabled_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($user)->withSession([
            'ce_mfa.verified_user_id' => $user->id,
            'ce_mfa.verified_at' => now()->timestamp,
        ]);

        return $user;
    }

    private function log(User $user, string $type, string $ip = '192.0.2.4'): AuditEvent
    {
        return AuditEvent::query()->create([
            'actor_user_id' => $user->id,
            'category' => 'db_access',
            'event_type' => $type,
            'severity' => 'high',
            'ip_address' => $ip,
            'event_data' => [
                'statement_type' => 'SELECT',
                'nested' => ['api_token' => 'NEVER_SHOW_SECRET'],
            ],
            'occurred_at' => now(),
        ]);
    }

    public function test_admin_cannot_view_other_users_events_even_when_filtering(): void
    {
        $admin = $this->signIn('admin');
        $own = $this->log($admin, 'access.db.connection.denied');
        $other = User::factory()->create();
        $this->log($other, 'auth.admin.created', '192.0.2.5');

        $response = $this->get('/admin/audit?q=admin');
        $response->assertOk()->assertDontSee('auth.admin.created');

        $this->get('/admin/audit?ip=192.0.2.4')
            ->assertOk()->assertSee('access.db.connection.denied');
        $this->get('/admin/audit?user='.$other->email)
            ->assertOk()->assertDontSee('auth.admin.created');

        $this->assertNotNull($own);
    }

    public function test_superadmin_search_and_filters_and_safe_inspector(): void
    {
        $super = $this->signIn('superadmin');
        $event = $this->log($super, 'access.db.query.high_risk');
        $this->log($super, 'access.db.connection.denied', '192.0.2.5');

        $response = $this->get('/admin/audit?event_type=access.db.query.high_risk&ip=192.0.2.4')
            ->assertOk()
            ->assertSee('audit-payload-'.$event->id)
            ->assertSee('access.db.query.high_risk')
            ->assertDontSee('<code class="ce-audit-event-code">access.db.connection.denied</code>', false);
        $response->assertDontSee('NEVER_SHOW_SECRET');
        $response->assertSee('[REDACTED]');
        $response->assertSee('ce-audit-dialog');
        $response->assertSee('SQL-запит високого ризику');
        $response->assertSee('Доступ до БД');
    }

    public function test_invalid_filters_fail_and_no_mfa_cannot_read(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin', 'is_active' => true])->save();
        $this->actingAs($user)->get('/admin/audit')->assertRedirect();

        $this->signIn('superadmin');
        $this->get('/admin/audit?ip=invalid-ip')->assertSessionHasErrors('ip');
        $this->get('/admin/audit?from=2026-10-23&to=2026-10-22')
            ->assertSessionHasErrors('to');
    }

    public function test_informational_severity_has_human_readable_localization(): void
    {
        $admin = $this->signIn('superadmin');
        $event = $this->log($admin, 'access.db.connection.started');
        $event->forceFill(['severity' => 'info'])->save();

        $this->get('/admin/audit')->assertOk()
            ->assertSee('Інформаційний')
            ->assertSee('Підключення до БД відкрито')
            ->assertDontSee('ce.common.info');
    }

    public function test_redaction_preserves_non_sensitive_fields(): void
    {
        $presenter = app(\App\Services\Audit\SafeAuditPayload::class);
        $json = $presenter->json([
            'username' => 'sag_lab',
            'token' => 'UNSAFE',
            'nested' => ['password' => 'HIDDEN', 'connection_uid' => 'CONN-123'],
        ]);
        $this->assertStringContainsString('sag_lab', $json);
        $this->assertStringContainsString('CONN-123', $json);
        $this->assertStringNotContainsString('UNSAFE', $json);
        $this->assertStringNotContainsString('HIDDEN', $json);
    }
}
