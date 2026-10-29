<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\Credentials\AuthorizedGatewayVault;
use App\Services\Credentials\ResourceCredentialVault;
use App\Services\DbAccess\DbGatewayLifecycleService;
use App\Services\DbAccess\LabManagedBrokerLifecycleService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class ResourceBackendAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'superadmin', 'is_active' => true,
            'mfa_totp_secret' => 'TESTSECRET123456', 'mfa_enabled_at' => now()->subMinute()])->save();
        $this->actingAs($user)->withSession(['ce_mfa.verified_user_id' => $user->id,
            'ce_mfa.verified_at' => now()->timestamp]);

        return $user;
    }

    private function data(array $overrides = []): array
    {
        return array_merge(['name' => 'MSSQL authentication test', 'engine' => 'mssql',
            'target_host' => 'sql.example.test', 'target_port' => 1433, 'target_database' => 'lab'], $overrides);
    }

    private function resource(User $user, array $options = []): DatabaseResource
    {
        return DatabaseResource::create([...$this->data(), 'created_by_user_id' => $user->id,
            'auth_mode' => 'managed', 'target_tls_mode' => 'verify-full', 'is_active' => true,
            'target_options' => $options]);
    }

    public function test_explicit_server_mode_is_saved_displayed_and_audited_separately_from_gateway_auth(): void
    {
        $this->signIn();
        $this->post(route('admin.resources.store'), $this->data(['backend_auth_mode' => 'mssql_kerberos_service']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $resource = DatabaseResource::firstOrFail();
        $this->assertSame('mssql_kerberos_service', $resource->backendAuthMode());
        $this->assertSame('client_passthrough', $resource->auth_mode);
        $this->get(route('admin.resources.show', $resource))->assertOk()->assertSee('Windows Integrated Authentication');
        $this->get(route('admin.resources.edit', $resource))->assertOk()
            ->assertSee('value="mssql_kerberos_service" selected', false)->assertDontSee('id="vault_password"', false);
        $this->assertSame('mssql_kerberos_service', data_get(AuditEvent::firstOrFail()->event_data, 'resource.backend_auth_mode'));
        $this->post(route('admin.resources.credentials.update', $resource), ['username' => 'sql_login', 'password' => 'unused-sql-secret'])
            ->assertStatus(422);
        $this->assertDatabaseCount('database_resource_credentials', 0);
    }

    public function test_updates_preserve_mode_when_omitted_and_other_target_options(): void
    {
        $resource = $this->resource($this->signIn(), ['backend_auth_mode' => 'mssql_kerberos_service', 'created_via' => 'test']);
        $this->put(route('admin.resources.update', $resource), $this->data(['description' => 'Updated']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('mssql_kerberos_service', $resource->fresh()->backendAuthMode());
        $this->put(route('admin.resources.update', $resource), $this->data(['backend_auth_mode' => 'mssql_sql_auth']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('mssql_sql_auth', $resource->fresh()->backendAuthMode());
        $this->assertSame('test', data_get($resource->fresh()->target_options, 'created_via'));
        $event = AuditEvent::latest('id')->firstOrFail();
        $this->assertSame('mssql_kerberos_service', data_get($event->event_data, 'changes.backend_auth_mode.before'));
        $this->assertSame('mssql_sql_auth', data_get($event->event_data, 'changes.backend_auth_mode.after'));
    }

    public function test_mode_cannot_change_during_start_active_or_shutdown(): void
    {
        $user = $this->signIn();
        $resource = $this->resource($user);
        foreach (['starting', 'started', 'stopping'] as $status) {
            $session = DbAccessSession::create(['public_id' => 'AUTH-'.$status, 'owner_user_id' => $user->id,
                'resource_id' => $resource->id, 'status' => $status, 'mode' => 'temporary', 'expires_at' => now()->addHour()]);
            $this->put(route('admin.resources.update', $resource), $this->data(['backend_auth_mode' => 'mssql_kerberos_service']))
                ->assertSessionHasErrors('resource');
            $this->assertSame('mssql_sql_auth', $resource->fresh()->backendAuthMode());
            $session->delete();
        }
    }

    public function test_invalid_or_inapplicable_modes_are_rejected(): void
    {
        $this->signIn();
        foreach (['ntlm', 'mssql_kerberos_delegation', '', ['mssql_sql_auth']] as $mode) {
            $this->post(route('admin.resources.store'), $this->data(['backend_auth_mode' => $mode]))
                ->assertSessionHasErrors('backend_auth_mode');
        }
        $this->post(route('admin.resources.store'), $this->data(['engine' => 'postgresql', 'backend_auth_mode' => 'mssql_kerberos_service']))
            ->assertSessionHasErrors('backend_auth_mode');
        $this->assertDatabaseCount('database_resources', 0);
    }

    public function test_windows_or_malformed_modes_never_release_sql_password_or_fall_back(): void
    {
        $user = $this->signIn();
        $resource = $this->resource($user);
        $session = DbAccessSession::create(['public_id' => 'AUTH-WINDOWS', 'owner_user_id' => $user->id,
            'resource_id' => $resource->id, 'status' => 'starting', 'mode' => 'temporary', 'expires_at' => now()->addHour(),
            'metadata' => ['db' => ['gateway_pid' => 1234, 'gateway_proc_start_ticks' => '123456']]]);
        config()->set('sag_credentials.encryption_key', 'base64:'.base64_encode(random_bytes(32)));
        $vault = app(ResourceCredentialVault::class);
        $vault->replace($resource->id, 'sql_login', 'never-release-this-password', $user->id);
        $gate = app(AuthorizedGatewayVault::class);
        foreach (['mssql_kerberos_service', 'unknown', null, 42] as $mode) {
            $resource->forceFill(['target_options' => ['backend_auth_mode' => $mode]])->save();
            $session->forceFill(['status' => 'starting', 'metadata' => ['db' => ['gateway_pid' => 1234, 'gateway_proc_start_ticks' => '123456']]])->save();
            $this->assertFalse($gate->isStartingSessionBoundToProcess($session->public_id, $resource->id, 1234, '123456'));
            try {
                $gate->takeBootstrapCredentials($session->public_id, $resource->id, 1234, '123456', $vault);
                $this->fail('SQL password was released for a non-SQL mode.');
            } catch (DomainException) {
                $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
            }
            $session->forceFill(['status' => 'started'])->save();
            $this->assertFalse($gate->isActiveSessionResource($session->public_id, $resource->id));
            $session->forceFill(['status' => 'created'])->save();
            config()->set('sag.db_gateway.managed_lab.enabled', true);
            foreach ([DbGatewayLifecycleService::class, LabManagedBrokerLifecycleService::class] as $launcher) {
                try {
                    app($launcher)->start($session->fresh());
                    $this->fail('Authentication silently fell back.');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('refusing SQL authentication fallback', $e->getMessage());
                }
                $this->assertSame('created', $session->fresh()->status);
                $this->assertNull($session->fresh()->gateway_port);
            }
        }
    }
}
