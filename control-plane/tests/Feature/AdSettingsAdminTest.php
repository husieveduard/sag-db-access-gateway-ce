<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\AdSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdSettingsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_default_to_disabled_and_ldaps(): void
    {
        $settings = app(AdSettings::class)->get();
        $this->assertFalse($settings['enabled']);
        $this->assertSame('ldaps', $settings['protocol']);
    }

    public function test_only_superadmin_can_update_ad_settings(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin', 'is_active' => true])->save();

        $this->withoutMiddleware()->actingAs($admin)->post('/admin/ad-settings', [
            'enabled' => '1', 'protocol' => 'ldaps', 'host' => 'dc.example.local',
            'port' => 636, 'domain' => 'example.local',
            'base_dn' => 'DC=example,DC=local',
            'allowed_group_dn' => 'CN=Operators,DC=example,DC=local',
        ])->assertForbidden();

        $this->assertFalse(app(AdSettings::class)->get()['enabled']);
    }

    public function test_superadmin_saves_secure_starttls_settings_and_audits_change(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'superadmin', 'is_active' => true])->save();

        $this->withoutMiddleware()->actingAs($admin)->post('/admin/ad-settings', [
            'enabled' => '1', 'protocol' => 'starttls', 'host' => 'dc.example.local',
            'port' => 389, 'domain' => 'example.local', 'netbios_domain' => 'EXAMPLE',
            'base_dn' => 'DC=example,DC=local',
            'allowed_group_dn' => 'CN=Operators,OU=IT,DC=example,DC=local',
        ])->assertRedirect();

        $settings = app(AdSettings::class)->get();
        $this->assertTrue($settings['enabled']);
        $this->assertSame('ldap://dc.example.local:389', $settings['url']);
        $this->assertSame('starttls', $settings['protocol']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'ad.settings.updated', 'actor_user_id' => $admin->id]);
    }

    public function test_plain_ldap_cannot_be_enabled_through_admin_interface(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'superadmin', 'is_active' => true])->save();

        $this->withoutMiddleware()->actingAs($admin)->post('/admin/ad-settings', [
            'enabled' => '1', 'protocol' => 'ldap', 'host' => 'dc.example.local',
            'port' => 389, 'domain' => 'example.local',
            'base_dn' => 'DC=example,DC=local',
            'allowed_group_dn' => 'CN=Operators,DC=example,DC=local',
        ])->assertSessionHasErrors('protocol');
    }
}
