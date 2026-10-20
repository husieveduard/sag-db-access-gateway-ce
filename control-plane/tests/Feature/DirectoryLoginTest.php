<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\ActiveDirectoryAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DirectoryLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_ad_login_requires_enabled_directory(): void
    {
        config()->set('sag.ldap.enabled', false);
        $this->post('/login', ['email' => 'alice', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_authorized_ad_login_creates_unique_profile_and_requires_mfa(): void
    {
        config()->set('sag.ldap.enabled', true);
        $directory = \Mockery::mock(ActiveDirectoryAuthenticator::class);
        $directory->shouldReceive('authenticate')->once()->andReturn([
            'guid' => '00112233445566778899aabbccddeeff',
            'email' => 'alice@example.internal',
            'name' => 'Alice',
        ]);
        $this->app->instance(ActiveDirectoryAuthenticator::class, $directory);

        $this->post('/login', ['email' => 'alice', 'password' => 'valid-domain-password'])
            ->assertRedirect(route('mfa.setup'))
            ->assertSessionHas('ce_mfa.pending_user_id');

        $user = User::query()->firstOrFail();
        $this->assertSame('ldap', $user->auth_source);
        $this->assertSame('00112233445566778899aabbccddeeff', $user->ldap_guid);
        $this->assertSame('admin', $user->role);
        $this->assertFalse(Hash::check('valid-domain-password', $user->password));
        $this->assertGuest();
    }

    public function test_directory_login_must_not_take_over_local_admin_email(): void
    {
        config()->set('sag.ldap.enabled', true);
        User::query()->create([
            'name' => 'Local admin',
            'email' => 'alice@example.internal',
            'password' => Hash::make('SomeLongLocalPassword123'),
        ])->forceFill(['role' => 'admin', 'is_active' => true])->save();

        $directory = \Mockery::mock(ActiveDirectoryAuthenticator::class);
        $directory->shouldNotReceive('authenticate');
        $this->app->instance(ActiveDirectoryAuthenticator::class, $directory);

        $this->post('/login', [
            'email' => 'alice@example.internal',
            'password' => 'valid-domain-password',
        ])->assertSessionHasErrors('email');
        $this->assertSame('local', User::query()->firstOrFail()->auth_source);
    }

    public function test_disabled_directory_profile_is_denied(): void
    {
        config()->set('sag.ldap.enabled', true);
        $user = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.internal',
            'password' => Hash::make('unusable-password'),
        ]);
        $user->forceFill([
            'auth_source' => 'ldap',
            'ldap_guid' => '00112233445566778899aabbccddeeff',
            'role' => 'admin',
            'is_active' => false,
        ])->save();

        $directory = \Mockery::mock(ActiveDirectoryAuthenticator::class);
        $directory->shouldReceive('authenticate')->once()->andReturn([
            'guid' => '00112233445566778899aabbccddeeff',
            'email' => 'alice@example.internal',
            'name' => 'Alice',
        ]);
        $this->app->instance(ActiveDirectoryAuthenticator::class, $directory);

        $this->post('/login', [
            'email' => 'alice',
            'password' => 'valid-domain-password',
        ])->assertSessionHasErrors('email');
    }
    public function test_promoted_domain_superadmin_retains_role_after_another_ad_login(): void
    {
        config()->set('sag.ldap.enabled', true);

        $user = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.internal',
            'password' => Hash::make('NoLocalLoginPassword2026'),
        ]);
        $user->forceFill([
            'auth_source' => 'ldap',
            'ldap_guid' => '00112233445566778899aabbccddeeff',
            'role' => 'superadmin',
            'is_active' => true,
        ])->save();

        $directory = \Mockery::mock(ActiveDirectoryAuthenticator::class);
        $directory->shouldReceive('authenticate')->once()->andReturn([
            'guid' => '00112233445566778899aabbccddeeff',
            'email' => 'alice@example.internal',
            'name' => 'Alice Domain',
        ]);
        $this->app->instance(ActiveDirectoryAuthenticator::class, $directory);

        $this->post('/login', [
            'email' => 'alice',
            'password' => 'valid-domain-password',
        ])->assertRedirect(route('mfa.setup'));

        $this->assertSame('superadmin', $user->fresh()->role);
        $this->assertSame('Alice Domain', $user->fresh()->name);
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_revoked_domain_membership_prevents_subsequent_login(): void
    {
        config()->set('sag.ldap.enabled', true);

        $user = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.internal',
            'password' => Hash::make('NoLocalLoginPassword2026'),
        ]);
        $user->forceFill([
            'auth_source' => 'ldap',
            'ldap_guid' => '00112233445566778899aabbccddeeff',
            'role' => 'superadmin',
            'is_active' => true,
        ])->save();

        // The directory authenticator returns null when the account was
        // disabled or lost membership in the allowed AD group.
        $directory = \Mockery::mock(ActiveDirectoryAuthenticator::class);
        $directory->shouldReceive('authenticate')->once()->andReturnNull();
        $this->app->instance(ActiveDirectoryAuthenticator::class, $directory);

        $this->post('/login', [
            'email' => 'alice',
            'password' => 'valid-domain-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame('superadmin', $user->fresh()->role);
    }

    public function test_domain_user_cannot_sign_in_using_local_placeholder_password(): void
    {
        config()->set('sag.ldap.enabled', false);

        $user = User::query()->create([
            'name' => 'Alice',
            'email' => 'alice@example.internal',
            'password' => Hash::make('NotValidForLocalLogin2026'),
        ]);
        $user->forceFill([
            'auth_source' => 'ldap',
            'ldap_guid' => '00112233445566778899aabbccddeeff',
            'role' => 'superadmin',
            'is_active' => true,
        ])->save();

        $this->post('/login', [
            'email' => 'alice@example.internal',
            'password' => 'NotValidForLocalLogin2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

}
