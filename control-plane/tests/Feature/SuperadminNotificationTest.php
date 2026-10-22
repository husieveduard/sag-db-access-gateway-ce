<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperadminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function asRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role, 'is_active' => true,
            'mfa_totp_secret' => 'TESTSECRET123456',
            'mfa_enabled_at' => now()->subMinute(),
        ])->save();
        $this->actingAs($user)->withSession([
            'ce_mfa.verified_user_id' => $user->id,
            'ce_mfa.verified_at' => now()->timestamp,
        ]);
        return $user;
    }

    private function seedAlert(string $severity = 'high'): Alert
    {
        $rule = \App\Models\AlertRule::query()->where('code', 'high_risk_query')->firstOrFail();
        return Alert::query()->create([
            'rule_id' => $rule->id, 'last_audit_event_id' => 1,
            'fingerprint' => hash('sha256', uniqid('', true)),
            'severity' => $severity, 'status' => 'new',
            'summary' => 'High risk SQL', 'first_seen_at' => now()->subMinute(),
            'last_seen_at' => now()->subMinute(),
        ]);
    }

    public function test_only_superadmin_can_see_or_read_notifications(): void
    {
        $this->get('/admin/notifications')->assertRedirect();
        $this->asRole('admin');
        $this->get('/admin/notifications')->assertForbidden();
        $this->get('/admin/notifications/feed')->assertForbidden();
        $this->post('/admin/notifications/read-all')->assertForbidden();
        $this->post('/admin/notifications/read', ['source_type' => 'alert', 'source_id' => 1])
            ->assertForbidden();

        $this->asRole('superadmin');
        $this->get('/admin/notifications')->assertOk();
        $this->get('/admin/dashboard')->assertOk()->assertSee('ce-notification-trigger');
    }

    public function test_read_state_is_per_user_and_deduplicated_alerts_are_one_item(): void
    {
        $alert = $this->seedAlert();
        $first = $this->asRole('superadmin');
        $result = $this->getJson('/admin/notifications/feed')->assertOk();
        $result->assertJsonPath('unread_count', 1);
        $this->assertCount(1, $result->json('items'));

        $this->postJson('/admin/notifications/read', [
            'source_type' => 'alert', 'source_id' => $alert->id,
        ])->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertDatabaseCount('admin_notification_reads', 1);

        $this->postJson('/admin/notifications/read', [
            'source_type' => 'alert', 'source_id' => $alert->id,
        ])->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertDatabaseCount('admin_notification_reads', 1);

        $this->asRole('superadmin');
        $this->getJson('/admin/notifications/feed')->assertOk()
            ->assertJsonPath('unread_count', 1);

        // Existing deduplicated alert still counts as one notification, but
        // new activity must surface again for the first superadmin.
        $alert->forceFill(['occurrence_count' => 4, 'last_seen_at' => now()->addMinute()])->save();
        $this->actingAs($first)->withSession([
            'ce_mfa.verified_user_id' => $first->id,
            'ce_mfa.verified_at' => now()->timestamp,
        ]);
        $this->getJson('/admin/notifications/feed')->assertOk()
            ->assertJsonPath('unread_count', 1);
    }

    public function test_forged_sources_are_rejected_and_regular_sql_ignored(): void
    {
        $this->asRole('superadmin');
        AuditEvent::query()->create([
            'category' => 'db_access', 'event_type' => 'access.db.query.executed',
            'severity' => 'info', 'event_data' => ['sql' => 'SENSITIVE VALUE'],
            'occurred_at' => now(),
        ]);
        $this->getJson('/admin/notifications/feed')->assertOk()
            ->assertJsonPath('unread_count', 0);
        $this->postJson('/admin/notifications/read', [
            'source_type' => 'audit', 'source_id' => 999999,
        ])->assertNotFound();
        $this->postJson('/admin/notifications/read', [
            'source_type' => 'invalid', 'source_id' => 1,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('admin_notification_reads', 0);
    }

    public function test_failed_delivery_and_admin_changes_are_included_without_sensitive_payload(): void
    {
        $user = $this->asRole('superadmin');
        $alert = $this->seedAlert();
        $alert->deliveries()->create([
            'channel' => 'telegram', 'status' => 'failed',
            'attempts' => 10, 'next_attempt_at' => now(),
        ]);
        AuditEvent::query()->create([
            'actor_user_id' => $user->id, 'category' => 'authentication',
            'event_type' => 'auth.admin.created', 'severity' => 'info',
            'event_data' => ['password' => 'DO_NOT_SHOW'], 'occurred_at' => now(),
        ]);
        $response = $this->getJson('/admin/notifications/feed')->assertOk();
        $response->assertJsonPath('unread_count', 3);
        $this->assertStringNotContainsString('DO_NOT_SHOW', $response->getContent());

        $this->postJson('/admin/notifications/read-all')->assertOk()
            ->assertJsonPath('unread_count', 0);
        $this->assertDatabaseCount('admin_notification_reads', 3);
    }
}
