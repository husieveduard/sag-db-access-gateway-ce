<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Settings\DisplayClock;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisplayTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(string $role): void
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
    }

    public function test_default_is_utc_and_does_not_change_system_clock(): void
    {
        $this->assertSame('UTC', app(DisplayClock::class)->timezone());
        $this->assertSame('UTC', config('app.timezone'));
        $utc = Carbon::parse('2026-10-22T08:14:47+00:00');
        $this->assertSame('22.10.2026 08:14:47', app(DisplayClock::class)->format($utc));
    }

    public function test_only_superadmin_may_update_display_timezone(): void
    {
        $this->post('/admin/settings/timezone', ['timezone' => 'Europe/Kyiv'])
            ->assertRedirect();
        $this->loginAs('admin');
        $this->post('/admin/settings/timezone', ['timezone' => 'Europe/Kyiv'])->assertForbidden();

        $this->loginAs('superadmin');
        $this->post('/admin/settings/timezone', ['timezone' => 'Invalid/Injected'])->assertSessionHasErrors('timezone');
        $this->assertSame('UTC', $this->app['db']->table('ce_display_settings')->value('display_timezone'));

        $this->post('/admin/settings/timezone', ['timezone' => 'Europe/Kyiv'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Europe/Kyiv', $this->app['db']->table('ce_display_settings')->value('display_timezone'));
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_saved_timezone_formats_utc_without_mutating_stored_timestamps(): void
    {
        $this->loginAs('superadmin');
        $stored = Carbon::parse('2026-10-22T08:14:47+00:00');

        $this->post('/admin/settings/timezone', ['timezone' => 'Europe/Kyiv'])->assertRedirect();

        $this->assertSame('22.10.2026 11:14:47', app(DisplayClock::class)->format($stored));
        $this->assertSame('2026-10-22T08:14:47+00:00', $stored->toIso8601String());
        $this->get('/admin/settings')->assertOk()->assertSee('Europe/Kyiv');
    }
}
