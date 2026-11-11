<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Tests\ApiTestCase;

class AuthTest extends ApiTestCase
{
    private function registration(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Sarah',
            'last_name' => 'Johnson',
            'email' => 'sarah@example.com',
            'phone' => '+31 6 11111111',
            'date_of_birth' => '1992-05-20',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }

    public function test_guests_cannot_read_the_current_user(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_login_returns_the_user_with_their_role(): void
    {
        $user = $this->userWithRole(Role::Doctor, ['email' => 'doc@clinic.test', 'password' => 'secret-pass-1']);

        $this->asFrontend()
            ->postJson('/api/login', ['email' => 'doc@clinic.test', 'password' => 'secret-pass-1'])
            ->assertOk()
            ->assertJsonPath('data.role', 'doctor')
            ->assertJsonPath('data.email', 'doc@clinic.test');

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_rejected_with_a_validation_error(): void
    {
        $this->userWithRole(Role::Doctor, ['email' => 'doc@clinic.test', 'password' => 'secret-pass-1']);

        $this->asFrontend()
            ->postJson('/api/login', ['email' => 'doc@clinic.test', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_accounts_cannot_log_in(): void
    {
        $this->userWithRole(Role::Receptionist, [
            'email' => 'gone@clinic.test',
            'password' => 'secret-pass-1',
            'is_active' => false,
        ]);

        $this->asFrontend()
            ->postJson('/api/login', ['email' => 'gone@clinic.test', 'password' => 'secret-pass-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_logging_out_ends_the_session(): void
    {
        $this->actingAs($this->userWithRole(Role::Doctor));

        $this->asFrontend()->postJson('/api/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_registering_creates_a_patient_account_and_profile(): void
    {
        $response = $this->asFrontend()
            ->postJson('/api/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('data.role', 'patient');

        $user = User::where('email', 'sarah@example.com')->firstOrFail();

        $this->assertSame(Role::Patient, $user->role);
        $this->assertDatabaseHas('patients', ['user_id' => $user->id, 'name' => 'Sarah Johnson']);
        $this->assertSame($user->patient->id, $response->json('data.patient_id'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_registering_cannot_choose_a_role(): void
    {
        $this->asFrontend()
            ->postJson('/api/register', $this->registration(['role' => 'super_admin']))
            ->assertCreated();

        $this->assertSame(Role::Patient, User::where('email', 'sarah@example.com')->firstOrFail()->role);
    }

    public function test_registering_with_the_email_of_a_staff_created_profile_is_blocked(): void
    {
        $this->patientFor(null, ['email' => 'sarah@example.com']);

        $this->asFrontend()
            ->postJson('/api/register', $this->registration())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'sarah@example.com']);
    }

    public function test_the_password_must_be_confirmed_and_long_enough(): void
    {
        $this->asFrontend()
            ->postJson('/api/register', $this->registration(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->asFrontend()
            ->postJson('/api/register', $this->registration(['password_confirmation' => 'different123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }
}