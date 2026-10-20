<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultipleLocalAdministratorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_additional_local_administrator_can_be_created_without_an_admin_count_limit(): void
    {
        foreach (['first@example.test', 'second@example.test'] as $email) {
            $this->artisan('sag:ce-create-admin', [
                'email' => $email,
                '--name' => 'CE Administrator',
            ])
                ->expectsQuestion(
                    'Initial password (minimum 14 characters, upper/lowercase and digit)',
                    'AdminStrongPassword2026'
                )
                ->expectsQuestion('Confirm initial password', 'AdminStrongPassword2026')
                ->assertSuccessful();
        }

        $this->assertSame(2, User::query()
            ->where('auth_source', 'local')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->count());
        $this->assertSame(2, AuditEvent::query()
            ->where('event_type', 'auth.admin.created')
            ->count());
    }
}
