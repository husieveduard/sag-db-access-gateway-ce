<?php

namespace Tests\Feature;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\Credentials\AuthorizedGatewayVault;
use App\Services\Credentials\ManagedKerberosProfiles;
use App\Services\Credentials\ResourceCredentialVault;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ManagedKerberosBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/sag-kerberos-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/krb5.conf', "[libdefaults]\n default_realm = SAG.TEST\n");
        file_put_contents($this->directory.'/gateway.keytab', 'opaque-keytab-fixture');
        chmod($this->directory.'/krb5.conf', 0644);
        chmod($this->directory.'/gateway.keytab', 0600);
        config()->set('sag_credentials.encryption_key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('sag.db_gateway.kerberos.enabled', true);
        config()->set('sag.db_gateway.kerberos.profiles', ['service-a' => [
            'target' => 'sql.sag.test:1433', 'spn' => 'MSSQLSvc/sql.sag.test:1433',
            'username' => 'gateway', 'realm' => 'SAG.TEST',
            'config_file' => $this->directory.'/krb5.conf', 'keytab_file' => $this->directory.'/gateway.keytab',
        ]]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function makeKerberosSession(): DbAccessSession
    {
        $user = User::factory()->create();
        $resource = DatabaseResource::query()->create([
            'name' => 'Kerberos bootstrap '.bin2hex(random_bytes(6)), 'engine' => 'mssql',
            'target_host' => 'sql.sag.test', 'target_port' => 1433, 'target_database' => 'isolated',
            'auth_mode' => 'managed', 'target_tls_mode' => 'verify-full', 'is_active' => true,
            'target_options' => ['backend_auth_mode' => 'mssql_sql_auth'], 'created_by_user_id' => $user->id,
        ]);
        // A stale encrypted SQL slot must never become a Windows fallback.
        app(ResourceCredentialVault::class)->replace($resource->id, 'old_sql_user', 'old-sql-fixture', $user->id);
        $resource->update(['target_options' => ['backend_auth_mode' => 'mssql_kerberos_service', 'kerberos_profile' => 'service-a']]);
        return DbAccessSession::query()->create([
            'public_id' => 'SAG-KERBEROS-'.bin2hex(random_bytes(8)), 'resource_id' => $resource->id,
            'owner_user_id' => $user->id, 'mode' => 'temporary', 'status' => 'starting', 'expires_at' => now()->addHour(),
            'metadata' => ['db' => ['gateway_pid' => 12345, 'gateway_proc_start_ticks' => '987654321',
                'backend_auth_mode' => 'mssql_kerberos_service', 'kerberos_profile' => 'service-a']],
        ]);
    }

    private function take(DbAccessSession $session, int $pid = 12345, string $ticks = '987654321'): array
    {
        return app(AuthorizedGatewayVault::class)->takeBootstrapKerberos(
            $session->public_id, $session->resource_id, $pid, $ticks, app(ManagedKerberosProfiles::class),
        );
    }

    public function test_typed_grant_is_one_time_and_contains_no_sql_password(): void
    {
        $session = $this->makeKerberosSession();
        $material = $this->take($session);
        $this->assertSame('kerberos_keytab', $material['kind']);
        $this->assertSame('service-a', $material['profile_id']);
        $this->assertSame('opaque-keytab-fixture', base64_decode($material['keytab_b64'], true));
        $this->assertArrayNotHasKey('password', $material);
        $this->assertStringNotContainsString('old-sql-fixture', json_encode($material));
        $this->assertNotNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
        $this->expectException(DomainException::class);
        $this->take($session);
    }

    public function test_wrong_process_identity_cannot_consume_or_read_material(): void
    {
        $session = $this->makeKerberosSession();
        foreach ([[12346, '987654321'], [12345, '987654322']] as [$pid, $ticks]) {
            try {
                $this->take($session, $pid, $ticks);
                $this->fail('Foreign process accepted.');
            } catch (DomainException) {
                $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
            }
        }
        $this->assertSame('kerberos_keytab', $this->take($session)['kind']);
    }

    public function test_sql_password_grant_never_falls_back_for_windows_session(): void
    {
        $session = $this->makeKerberosSession();
        try {
            app(AuthorizedGatewayVault::class)->takeBootstrapCredentials($session->public_id,
                $session->resource_id, 12345, '987654321', app(ResourceCredentialVault::class));
            $this->fail('SQL fallback was accepted.');
        } catch (DomainException) {
            $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
        }
        $this->assertSame('kerberos_keytab', $this->take($session)['kind']);
    }

    public function test_kerberos_grant_denies_sql_sessions_without_consuming_bootstrap(): void
    {
        $session = $this->makeKerberosSession();
        $session->resource->update(['target_options' => ['backend_auth_mode' => 'mssql_sql_auth']]);
        try {
            $this->take($session);
            $this->fail('SQL session received Kerberos material.');
        } catch (DomainException) {
            $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
        }
        $credential = app(AuthorizedGatewayVault::class)->takeBootstrapCredentials($session->public_id,
            $session->resource_id, 12345, '987654321', app(ResourceCredentialVault::class));
        $this->assertSame('old_sql_user', $credential['username']);
    }

    public function test_live_gate_revalidates_revoke_profile_target_and_feature_flag(): void
    {
        $session = $this->makeKerberosSession();
        $this->take($session);
        $session->update(['status' => 'started']);
        $gate = app(AuthorizedGatewayVault::class);
        $check = fn (): bool => $gate->isActiveSessionBoundToProcess($session->public_id, $session->resource_id, 12345, '987654321');
        $this->assertTrue($check());
        $session->update(['status' => 'terminated']);
        $this->assertFalse($check());
        $session->update(['status' => 'started']);
        $session->resource->update(['target_host' => 'other.sag.test']);
        $this->assertFalse($check());
        $session->resource->update(['target_host' => 'sql.sag.test',
            'target_options' => ['backend_auth_mode' => 'mssql_kerberos_service', 'kerberos_profile' => 'unknown']]);
        $this->assertFalse($check());
        $session->resource->update(['target_options' => ['backend_auth_mode' => 'mssql_kerberos_service', 'kerberos_profile' => 'service-a']]);
        $this->assertTrue($check());
        config()->set('sag.db_gateway.kerberos.enabled', false);
        $this->assertFalse($check());
    }

    public function test_shared_or_symlinked_keytab_and_wrong_spn_fail_before_consumption(): void
    {
        $session = $this->makeKerberosSession();
        chmod($this->directory.'/gateway.keytab', 0644);
        try {
            $this->take($session);
            $this->fail('Shared keytab accepted.');
        } catch (DomainException) {
            $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
        }
        chmod($this->directory.'/gateway.keytab', 0600);
        symlink($this->directory.'/gateway.keytab', $this->directory.'/link');
        config()->set('sag.db_gateway.kerberos.profiles.service-a.keytab_file', $this->directory.'/link');
        try {
            $this->take($session);
            $this->fail('Keytab symlink accepted.');
        } catch (DomainException) {
            $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
        }
        config()->set('sag.db_gateway.kerberos.profiles.service-a.keytab_file', $this->directory.'/gateway.keytab');
        config()->set('sag.db_gateway.kerberos.profiles.service-a.spn', 'MSSQLSvc/other.sag.test:1433');
        $this->expectException(DomainException::class);
        $this->take($session);
    }

    public function test_invalid_session_or_changed_profile_cannot_bootstrap(): void
    {
        foreach (['expired', 'inactive', 'revoked', 'persistent', 'wrong_profile'] as $case) {
            $session = $this->makeKerberosSession();
            if ($case === 'expired') {
                $session->update(['expires_at' => now()->subMinute()]);
            } elseif ($case === 'inactive') {
                $session->resource->update(['is_active' => false]);
            } elseif ($case === 'revoked') {
                $session->update(['status' => 'terminated']);
            } elseif ($case === 'persistent') {
                $session->update(['mode' => 'persistent']);
            } else {
                $session->resource->update(['target_options' => [
                    'backend_auth_mode' => 'mssql_kerberos_service', 'kerberos_profile' => 'unknown',
                ]]);
            }
            try {
                $this->take($session);
                $this->fail('Invalid session accepted: '.$case);
            } catch (DomainException) {
                $this->assertNull(data_get($session->fresh()->metadata, 'db.bootstrap_consumed_at'));
            }
        }
    }

    public function test_profiles_file_uses_aliases_without_returning_paths_or_sql_passwords(): void
    {
        $session = $this->makeKerberosSession();
        $path = $this->directory.'/profiles.json';
        file_put_contents($path, json_encode(config('sag.db_gateway.kerberos.profiles')));
        chmod($path, 0644);
        config()->set('sag.db_gateway.kerberos.profiles', null);
        config()->set('sag.db_gateway.kerberos.profiles_file', $path);
        $material = $this->take($session);
        $this->assertStringNotContainsString($this->directory, json_encode($material));
    }
}
