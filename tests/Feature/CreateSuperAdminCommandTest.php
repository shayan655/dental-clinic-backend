<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\ApiTestCase;

class CreateSuperAdminCommandTest extends ApiTestCase
{
    public function test_it_creates_the_first_super_admin(): void
    {
        $this->artisan('clinic:create-admin')
            ->expectsQuestion('Name', 'Shayan')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password (at least 8 characters)', 'password123')
            ->expectsOutput('Super admin created.')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame(Role::SuperAdmin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_it_refuses_to_create_a_second_super_admin(): void
    {
        $this->userWithRole(Role::SuperAdmin);

        $this->artisan('clinic:create-admin')
            ->expectsOutput('A super admin already exists.')
            ->assertFailed();

        $this->assertSame(1, User::where('role', Role::SuperAdmin)->count());
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->artisan('clinic:create-admin')
            ->expectsQuestion('Name', 'Shayan')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password (at least 8 characters)', 'short')
            ->expectsOutputToContain('at least 8 characters')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}