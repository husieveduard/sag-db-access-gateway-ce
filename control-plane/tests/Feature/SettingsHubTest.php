<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsHubTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(string $role): static
    {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role,
            'is_active' => true,
            'mfa_totp_secret' => 'TESTSECRET123456',
            'mfa_enabled_at' => now()->subMinute(),
        ])->save();

        return $this->actingAs($user)->withSession([
            'ce_mfa.verified_user_id' => $user->id,
            'ce_mfa.verified_at' => now()->timestamp,
        ]);
    }

    public function test_settings_hub_requires_mfa_superadmin(): void
    {
        $this->get('/admin/settings')->assertRedirect();
        $this->loginAs('admin')->get('/admin/settings')->assertForbidden();
        $this->loginAs('superadmin')->get('/admin/settings')
            ->assertOk()
            ->assertSee('Налаштування системи')
            ->assertSee('Active Directory')
            ->assertSee('SMTP-пошта');
    }

    public function test_settings_navigation_is_superadmin_only_and_has_no_dead_links(): void
    {
        $ordinary = $this->loginAs('admin')->get('/admin/dashboard')->assertOk();
        $ordinary->assertDontSee('href="'.route('admin.settings.index').'"', false);

        $super = $this->loginAs('superadmin')->get('/admin/dashboard')->assertOk();
        foreach ([
            'admin.settings.index',
            'admin.administrators.index',
            'admin.ad-settings.index',
            'admin.alert-channels.index',
            'admin.alert-rules.index',
        ] as $route) {
            $super->assertSee('href="'.route($route).'"', false);
        }
        $super->assertSee('ce-settings-group');
        $super->assertSee('ce-mobile-settings');
    }

    public function test_settings_pages_keep_settings_sidebar_expanded(): void
    {
        $hub = $this->loginAs('superadmin')->get('/admin/settings')->assertOk();
        $channels = $this->get('/admin/alert-channels')->assertOk();

        // The HTML boolean attribute may have whitespace around Blade directives.
        // Assert the rendered element is open instead of matching literal spacing.
        foreach ([$hub, $channels] as $response) {
            $this->assertMatchesRegularExpression(
                '/<details\\b[^>]*class="ce-settings-group"[^>]*\\bopen\\b[^>]*>/',
                $response->getContent()
            );
            $this->assertMatchesRegularExpression(
                '/<details\\b[^>]*class="ce-mobile-settings"[^>]*\\bopen\\b[^>]*>/',
                $response->getContent()
            );
        }
    }
}
