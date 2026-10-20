<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CeRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role,
            'is_active' => true,
            'mfa_enabled_at' => now()->subMinute(),
            'mfa_totp_secret' => 'TESTSECRET123456',
        ])->save();

        return $user;
    }

    private function loginAs(User $user): static
    {
        return $this->actingAs($user)->withSession([
            'ce_mfa.verified_user_id' => $user->id,
            'ce_mfa.verified_at' => now()->timestamp,
        ]);
    }

    public function test_only_superadmin_can_manage_roles(): void
    {
        $super = $this->administrator('superadmin');
        $admin = $this->administrator('admin');

        $this->loginAs($admin)->get('/admin/administrators')->assertForbidden();
        $this->loginAs($admin)->post('/admin/administrators/'.$admin->id.'/role', [
            'role' => 'superadmin',
        ])->assertForbidden();

        $this->loginAs($super)->get('/admin/administrators')->assertOk();
    }

    public function test_domain_administrator_may_be_promoted_by_superadmin(): void
    {
        $super = $this->administrator('superadmin');
        $admin = $this->administrator('admin');
        $admin->forceFill(['auth_source' => 'ldap', 'ldap_guid' => '00112233445566778899aabbccddeeff'])->save();

        $this->loginAs($super)->post('/admin/administrators/'.$admin->id.'/role', [
            'role' => 'superadmin',
        ])->assertRedirect();

        $this->assertSame('superadmin', $admin->fresh()->role);
        $this->assertSame('ldap', $admin->fresh()->auth_source);
    }

    public function test_last_active_superadmin_cannot_be_demoted(): void
    {
        $super = $this->administrator('superadmin');
        $this->loginAs($super)->post('/admin/administrators/'.$super->id.'/role', [
            'role' => 'admin',
        ])->assertStatus(422);

        $this->assertSame('superadmin', $super->fresh()->role);
    }

    public function test_admin_cannot_read_or_modify_another_administrators_resource(): void
    {
        $admin = $this->administrator('admin');
        $other = $this->administrator('admin');
        $super = $this->administrator('superadmin');

        $resource = DatabaseResource::query()->create([
            'name' => 'Other resource',
            'engine' => 'postgresql',
            'target_host' => 'db.example.internal',
            'target_port' => 5432,
            'target_database' => 'sample',
            'target_tls_mode' => 'prefer',
            'auth_mode' => 'client_passthrough',
            'query_audit_enabled' => true,
            'is_active' => true,
            'created_by_user_id' => $other->id,
        ]);

        $this->loginAs($admin)->get('/admin/resources/'.$resource->id)->assertNotFound();
        $this->loginAs($admin)->get('/admin/resources/'.$resource->id.'/edit')->assertNotFound();
        $this->loginAs($admin)->post('/admin/resources/'.$resource->id.'/deactivate', [
            'reason' => 'Not authorized',
        ])->assertNotFound();

        $this->loginAs($super)->get('/admin/resources/'.$resource->id)->assertOk();
        $this->assertTrue($resource->fresh()->is_active);
    }

    public function test_admin_cannot_open_or_terminate_another_administrators_session(): void
    {
        $admin = $this->administrator('admin');
        $other = $this->administrator('admin');
        $super = $this->administrator('superadmin');

        $resource = DatabaseResource::query()->create([
            'name' => 'Private resource',
            'engine' => 'postgresql',
            'target_host' => 'db.example.internal',
            'target_port' => 5432,
            'target_tls_mode' => 'prefer',
            'auth_mode' => 'client_passthrough',
            'query_audit_enabled' => true,
            'is_active' => true,
            'created_by_user_id' => $other->id,
        ]);

        $session = DbAccessSession::query()->create([
            'public_id' => 'SAG-CE-DB-PRIVATE-TEST',
            'resource_id' => $resource->id,
            'owner_user_id' => $other->id,
            'created_by_user_id' => $other->id,
            'mode' => 'temporary',
            'status' => 'created',
            'target_db_username' => 'db_user',
            'allowed_source_cidr' => '192.0.2.1/32',
            'query_audit_enabled' => true,
            'expires_at' => now()->addHour(),
        ]);

        $url = '/admin/sessions/'.$session->public_id;
        $this->loginAs($admin)->get($url)->assertNotFound();
        $this->loginAs($admin)->post($url.'/start')->assertNotFound();
        $this->loginAs($admin)->post($url.'/terminate', [
            'reason' => 'Not authorized',
        ])->assertNotFound();

        $this->loginAs($super)->get($url)->assertOk();
        $this->assertSame('created', $session->fresh()->status);
    }

    public function test_admin_audit_is_scoped_and_superadmin_audit_is_global(): void
    {
        $super = $this->administrator('superadmin');
        $admin = $this->administrator('admin');
        $other = $this->administrator('admin');

        foreach ([$admin, $other] as $actor) {
            AuditEvent::query()->create([
                'actor_user_id' => $actor->id,
                'category' => 'authentication',
                'event_type' => 'test.event.'.$actor->id,
                'severity' => 'info',
                'occurred_at' => now(),
            ]);
        }

        $this->loginAs($admin)->get('/admin/audit')
            ->assertOk()->assertSee('test.event.'.$admin->id)->assertDontSee('test.event.'.$other->id);

        $this->loginAs($super)->get('/admin/audit')
            ->assertOk()->assertSee('test.event.'.$admin->id)->assertSee('test.event.'.$other->id);
    }
}
