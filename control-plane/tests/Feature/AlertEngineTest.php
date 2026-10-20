<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Alerts\AlertEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_risk_audit_creates_alert_and_deduplicates_repetitions(): void
    {
        $user = User::factory()->create();
        foreach ([1, 2] as $number) {
            AuditEvent::query()->create([
                'actor_user_id' => $user->id,
                'category' => 'db_access',
                'event_type' => 'access.db.query.high_risk',
                'severity' => 'high',
                'ip_address' => '127.0.0.1',
                'event_data' => ['sample' => $number],
                'occurred_at' => now(),
            ]);
        }

        $this->assertSame(2, app(AlertEngine::class)->process());
        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseCount('alert_matches', 2);
        $this->assertSame(2, Alert::query()->firstOrFail()->occurrence_count);

        $this->assertSame(0, app(AlertEngine::class)->process());
        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_rules_can_be_disabled(): void
    {
        \App\Models\AlertRule::query()->where('code', 'denied_connection')->update(['enabled' => false]);
        AuditEvent::query()->create([
            'category' => 'db_access',
            'event_type' => 'access.db.connection.denied',
            'severity' => 'high',
            'event_data' => [],
            'occurred_at' => now(),
        ]);

        app(AlertEngine::class)->process();
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_alert_pages_require_login_and_mfa(): void
    {
        $this->get('/admin/alerts')->assertRedirect();
        $this->get('/admin/alert-rules')->assertRedirect();
    }
}
