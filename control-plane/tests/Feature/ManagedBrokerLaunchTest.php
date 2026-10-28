<?php

namespace Tests\Feature;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use App\Services\DbAccess\LabManagedBrokerLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class ManagedBrokerLaunchTest extends TestCase
{
    use RefreshDatabase;

    private function makeLaunchSession(string $engine = 'postgresql'): DbAccessSession
    {
        $user = User::factory()->create();
        $resource = DatabaseResource::query()->create(['name' => 'Managed launch gate '.$engine, 'engine' => $engine,
            'target_host' => '127.0.0.1', 'target_port' => 5432, 'target_database' => 'lab',
            'auth_mode' => 'managed', 'target_tls_mode' => 'disable', 'is_active' => true,
            'created_by_user_id' => $user->id]);

        return DbAccessSession::query()->create(['public_id' => 'SAG-LAUNCH-'.bin2hex(random_bytes(6)),
            'owner_user_id' => $user->id, 'resource_id' => $resource->id, 'status' => 'created',
            'mode' => 'temporary', 'expires_at' => now()->addHour()]);
    }

    public function test_lab_launch_is_disabled_by_default_without_mutating_session(): void
    {
        $session = $this->makeLaunchSession();
        config()->set('sag.db_gateway.managed_lab.enabled', false);
        try {
            app(LabManagedBrokerLifecycleService::class)->start($session);
            $this->fail('Disabled lab launcher started.');
        } catch (RuntimeException $e) {
            $this->assertSame('Managed laboratory broker is disabled in this environment.', $e->getMessage());
        }
        $this->assertSame('created', $session->fresh()->status);
    }

    public function test_production_refuses_lab_even_with_explicit_flag(): void
    {
        $session = $this->makeLaunchSession();
        config()->set('sag.db_gateway.managed_lab.enabled', true);
        $this->app->detectEnvironment(fn () => 'production');
        try {
            app(LabManagedBrokerLifecycleService::class)->start($session);
            $this->fail('Production accepted lab launcher.');
        } catch (RuntimeException $e) {
            $this->assertSame('Managed laboratory broker is disabled in this environment.', $e->getMessage());
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
        $this->assertSame('created', $session->fresh()->status);
    }

    public function test_mysql_mariadb_and_mssql_never_fall_back_to_postgres_or_passthrough(): void
    {
        config()->set('sag.db_gateway.managed_lab.enabled', true);
        foreach (['mysql', 'mariadb', 'mssql', 'sqlserver'] as $engine) {
            $session = $this->makeLaunchSession($engine);
            try {
                app(LabManagedBrokerLifecycleService::class)->start($session);
                $this->fail('Unsupported native adapter launched.');
            } catch (RuntimeException $e) {
                $this->assertSame('Managed laboratory broker is not implemented for this engine.', $e->getMessage());
            }
            $this->assertSame('created', $session->fresh()->status);
            $this->assertNull($session->fresh()->gateway_port);
        }
    }
}
