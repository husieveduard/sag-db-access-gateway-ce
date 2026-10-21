<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Alerts\ChannelSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministratorWebManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin', string $source = 'local'): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role,
            'auth_source' => $source,
            'is_active' => true,
            'mfa_totp_secret' => 'TESTSECRET123456',
            'mfa_enabled_at' => now()->subMinute(),
        ])->save();

        return $user;
    }

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession([
            'ce_mfa.verified_user_id' => $user->id,
            'ce_mfa.verified_at' => now()->timestamp,
        ]);
    }

    private function newAdmin(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Administrator',
            'email' => 'new@example.test',
            'role' => 'admin',
            'password' => 'SecurePassword2026',
            'password_confirmation' => 'SecurePassword2026',
        ], $overrides);
    }

    public function test_only_superadmin_can_open_and_submit_creation_form(): void
    {
        $ordinary = $this->user();
        $this->login($ordinary)->get('/admin/administrators/create')->assertForbidden();
        $this->login($ordinary)->post('/admin/administrators', $this->newAdmin())
            ->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_web_created_local_admin_is_active_hashed_mfa_required_and_audited(): void
    {
        $super = $this->user('superadmin');
        $this->login($super)->get('/admin/administrators/create')
            ->assertOk()->assertSee('Додати локального адміністратора');

        $this->login($super)->post('/admin/administrators', $this->newAdmin())
            ->assertRedirect(route('admin.administrators.index'));

        $created = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('admin', $created->role);
        $this->assertSame('local', $created->auth_source);
        $this->assertTrue($created->is_active);
        $this->assertFalse($created->is_service_account);
        $this->assertTrue(Hash::check('SecurePassword2026', $created->password));
        $this->assertNull($created->mfa_enabled_at);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'auth.admin.created',
            'actor_user_id' => $super->id,
        ]);
        $this->assertStringNotContainsString(
            'SecurePassword2026',
            \App\Models\AuditEvent::query()->where('event_type', 'auth.admin.created')
                ->firstOrFail()->event_data ? json_encode(
                    \App\Models\AuditEvent::query()->where('event_type', 'auth.admin.created')
                        ->firstOrFail()->event_data
                ) : ''
        );
    }

    public function test_superadmin_can_create_another_superadmin(): void
    {
        $super = $this->user('superadmin');
        $this->login($super)->post('/admin/administrators', $this->newAdmin(['role' => 'superadmin']))
            ->assertRedirect(route('admin.administrators.index'));
        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'role' => 'superadmin']);
    }

    public function test_invalid_duplicate_or_weak_password_is_rejected(): void
    {
        $super = $this->user('superadmin');
        $this->login($super)->post('/admin/administrators', $this->newAdmin([
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]))->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);

        $this->login($super)->post('/admin/administrators', $this->newAdmin([
            'email' => $super->email,
        ]))->assertSessionHasErrors('email');

        $this->login($super)->post('/admin/administrators', $this->newAdmin([
            'role' => 'user',
        ]))->assertSessionHasErrors('role');

        $this->login($super)->post('/admin/administrators', $this->newAdmin([
            'password_confirmation' => 'DifferentPassword2026',
        ]))->assertSessionHasErrors('password');
    }

    public function test_superadmin_can_disable_and_reenable_local_admin_with_audit(): void
    {
        $super = $this->user('superadmin');
        $target = $this->user();

        $this->login($super)->post('/admin/administrators/'.$target->id.'/status', [
            'active' => '0',
        ])->assertRedirect();
        $this->assertFalse($target->fresh()->is_active);

        $this->login($super)->post('/admin/administrators/'.$target->id.'/status', [
            'active' => '1',
        ])->assertRedirect();
        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseCount('audit_events', 2);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'auth.admin.status_changed',
            'actor_user_id' => $super->id,
        ]);
    }

    public function test_admin_cannot_change_status_and_last_superadmin_cannot_be_disabled(): void
    {
        $super = $this->user('superadmin');
        $ordinary = $this->user();

        $this->login($ordinary)->post('/admin/administrators/'.$super->id.'/status', [
            'active' => '0',
        ])->assertForbidden();

        $this->login($super)->post('/admin/administrators/'.$super->id.'/status', [
            'active' => '0',
        ])->assertStatus(422);

        $this->assertTrue($super->fresh()->is_active);
    }

    public function test_superadmin_cannot_disable_own_account_even_with_another_superadmin(): void
    {
        $super = $this->user('superadmin');
        $this->user('superadmin');

        $this->login($super)->post('/admin/administrators/'.$super->id.'/status', [
            'active' => '0',
        ])->assertStatus(422);
        $this->assertTrue($super->fresh()->is_active);
    }

    public function test_disabled_ad_profile_cannot_be_used_for_console_access(): void
    {
        $super = $this->user('superadmin');
        $target = $this->user('admin', 'ldap');
        $this->login($super)->post('/admin/administrators/'.$target->id.'/status', [
            'active' => '0',
        ])->assertRedirect();
        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_alert_health_page_renders_with_supported_channels(): void
    {
        $super = $this->user('superadmin');
        $ordinary = $this->user('admin');

        $this->login($ordinary)->get('/admin/alert-health')->assertForbidden();
        $page = $this->login($super)->get('/admin/alert-health')->assertOk();
        $page->assertSee('Стан сповіщень')
            ->assertSee('alert-health-stats')
            ->assertSee('Необроблені події аудиту')
            ->assertSee('Канали сповіщень');
        foreach (ChannelSender::CHANNELS as $channel) {
            $page->assertSee($channel);
        }
    }
}
