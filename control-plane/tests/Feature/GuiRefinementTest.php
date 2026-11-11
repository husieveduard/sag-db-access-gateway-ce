<?php

namespace Tests\Feature;

use App\Models\DatabaseResource;
use App\Models\DbAccessSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuiRefinementTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(string $role = 'superadmin'): User
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

    public function test_navigation_toggle_only_on_authenticated_shell_and_assets_exist(): void
    {
        $this->assertFileExists(public_path('js/ce-ui.js'));
        $this->get('/login')->assertOk()->assertDontSee('ce-sidebar-toggle');
        $this->loginAs();
        $response = $this->get('/admin/dashboard')->assertOk();
        $response->assertSee('id="ce-sidebar-toggle"', false)
            ->assertSee('ce-ui.js')
            ->assertSee('data-ce-label-expand=', false)
            ->assertSee('aria-expanded="true"', false);
    }

    public function test_session_ids_are_truncated_but_full_id_is_available_for_copy(): void
    {
        $admin = $this->loginAs();
        $resource = DatabaseResource::query()->create([
            'name' => 'GUI LAB', 'engine' => 'postgresql',
            'target_host' => 'db.example.internal', 'target_port' => 5432,
            'target_database' => 'test', 'target_tls_mode' => 'prefer',
            'auth_mode' => 'client_passthrough', 'query_audit_enabled' => true,
            'is_active' => true, 'created_by_user_id' => $admin->id,
        ]);
        $id = 'SAG-CE-DB-POSTGRESQL-20261022-074308-IICYUESC';
        $session = DbAccessSession::query()->create([
            'public_id' => $id, 'resource_id' => $resource->id,
            'owner_user_id' => $admin->id, 'created_by_user_id' => $admin->id,
            'mode' => 'temporary', 'status' => 'created',
            'target_db_username' => 'sag_lab', 'allowed_source_cidr' => '192.0.2.0/24',
            'query_audit_enabled' => true, 'expires_at' => now()->addHour(),
        ]);
        $short = \Illuminate\Support\Str::substr($id, 0, 14).'…'.\Illuminate\Support\Str::substr($id, -8);

        $this->get('/admin/sessions')->assertOk()
            ->assertSee('data-ce-copy="'.$id.'"', false)
            ->assertSee($short);
        $this->get('/admin/dashboard')->assertOk()
            ->assertSee('data-ce-copy="'.$id.'"', false)
            ->assertSee($short);
        $this->get(route('admin.sessions.show', $session))->assertOk()
            ->assertSee('data-ce-copy="'.$id.'"', false);
    }

    public function test_non_superadmin_does_not_get_settings_controls(): void
    {
        $this->loginAs('admin');
        $this->get('/admin/dashboard')->assertOk()
            ->assertDontSee('ce-settings-group')
            ->assertSee('ce-sidebar-toggle');
    }
}
