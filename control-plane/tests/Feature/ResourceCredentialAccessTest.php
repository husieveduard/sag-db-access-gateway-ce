<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DatabaseResource;
use App\Models\DatabaseResourceCredential;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\Credentials\ResourceCredentialVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceCredentialAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sag_credentials.encryption_key', 'base64:'.base64_encode(random_bytes(32)));
    }

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

    private function resource(User $owner, string $engine = 'postgresql'): DatabaseResource
    {
        return DatabaseResource::query()->create([
            'name' => 'test-vault-'.bin2hex(random_bytes(5)),
            'engine' => $engine,
            'target_host' => '127.0.0.1',
            'target_port' => $engine === 'postgresql' ? 5432 : 3306,
            'target_database' => 'lab',
            'auth_mode' => 'client_passthrough',
            'target_tls_mode' => 'prefer',
            'is_active' => true,
            'query_audit_enabled' => true,
            'created_by_user_id' => $owner->id,
        ]);
    }

    public function test_superadmin_can_store_and_rotate_without_logging_password(): void
    {
        $admin = $this->signIn('superadmin');
        $resource = $this->resource($admin);
        $secret = 'SENSITIVE-'.bin2hex(random_bytes(8));

        $this->post(route('admin.resources.credentials.update', $resource), [
            'username' => 'backend_user',
            'password' => $secret,
        ])->assertRedirect(route('admin.resources.edit', $resource));

        $row = DatabaseResourceCredential::query()->where('resource_id', $resource->id)->firstOrFail();
        $this->assertNotSame($secret, $row->password_encrypted);
        $this->assertArrayNotHasKey('password_encrypted', $row->toArray());
        $this->assertSame($secret, app(ResourceCredentialVault::class)->retrieveForGateway($resource->id)['password']);
        $this->assertDatabaseHas('audit_events', [
            'resource_id' => $resource->id,
            'event_type' => 'access.db.credentials.created',
        ]);
        $this->assertStringNotContainsString($secret, AuditEvent::where('resource_id', $resource->id)->firstOrFail()->toJson());

        $this->post(route('admin.resources.credentials.update', $resource), [
            'username' => 'backend_user',
            'password' => 'second-secret',
        ])->assertRedirect();
        $this->assertSame(1, DatabaseResourceCredential::where('resource_id', $resource->id)->count());
        $this->assertSame('second-secret', app(ResourceCredentialVault::class)->retrieveForGateway($resource->id)['password']);
        $this->assertDatabaseHas('audit_events', ['resource_id' => $resource->id, 'event_type' => 'access.db.credentials.rotated']);
    }

    public function test_regular_admin_is_denied_and_never_sees_credential_form(): void
    {
        $admin = $this->signIn('admin');
        $resource = $this->resource($admin);
        $this->post(route('admin.resources.credentials.update', $resource), [
            'username' => 'test', 'password' => 'should-not-save',
        ])->assertForbidden();
        $this->get(route('admin.resources.edit', $resource))->assertOk()
            ->assertDontSee('vault_password');
        $this->assertDatabaseCount('database_resource_credentials', 0);
    }

    public function test_mysql_resource_is_not_enabled_yet(): void
    {
        $admin = $this->signIn('superadmin');
        $resource = $this->resource($admin, 'mysql');
        $this->post(route('admin.resources.credentials.update', $resource), [
            'username' => 'test', 'password' => 'should-not-save',
        ])->assertStatus(422);
        $this->assertDatabaseCount('database_resource_credentials', 0);
    }

    public function test_password_validation_does_not_persist_empty_credentials(): void
    {
        $admin = $this->signIn('superadmin');
        $resource = $this->resource($admin);
        $this->post(route('admin.resources.credentials.update', $resource), [
            'username' => 'test', 'password' => '',
        ])->assertSessionHasErrors('password');
        $this->assertDatabaseCount('database_resource_credentials', 0);
    }
}
