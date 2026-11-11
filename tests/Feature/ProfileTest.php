<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

class ProfileTest extends ApiTestCase
{
    public static function accountRoles(): array
    {
        return [
            'super admin' => [Role::SuperAdmin],
            'clinic manager' => [Role::ClinicManager],
            'doctor' => [Role::Doctor],
            'receptionist' => [Role::Receptionist],
        ];
    }

    #[DataProvider('accountRoles')]
    public function test_everyone_can_update_their_own_name_and_email(Role $role): void
    {
        $this->actingAs($this->userWithRole($role));

        $this->putJson('/api/profile', ['name' => 'New Name', 'email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.role', $role->value);
    }

    public function test_nobody_can_promote_themselves_through_the_profile(): void
    {
        $doctor = $this->userWithRole(Role::Doctor);
        $this->actingAs($doctor);

        $this->putJson('/api/profile', [
            'name' => $doctor->name,
            'email' => $doctor->email,
            'role' => 'super_admin',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.role', 'doctor');

        $fresh = $doctor->fresh();
        $this->assertSame(Role::Doctor, $fresh->role);
        $this->assertTrue($fresh->is_active);
    }

    public function test_the_email_must_be_unique_but_keeping_your_own_is_fine(): void
    {
        $other = $this->userWithRole(Role::Doctor);
        $me = $this->userWithRole(Role::Receptionist);
        $this->actingAs($me);

        $this->putJson('/api/profile', ['name' => 'Me', 'email' => $other->email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->putJson('/api/profile', ['name' => 'Me', 'email' => $me->email])->assertOk();
    }

    public function test_a_patients_login_and_clinic_profile_stay_in_sync(): void
    {
        $account = $this->patientUser();
        $this->actingAs($account);

        $this->putJson('/api/profile', ['name' => 'Renamed Patient', 'email' => 'renamed@example.com'])->assertOk();

        $this->assertDatabaseHas('patients', [
            'user_id' => $account->id,
            'name' => 'Renamed Patient',
            'email' => 'renamed@example.com',
        ]);
    }

    public function test_a_patient_cannot_take_the_email_of_another_patient_profile(): void
    {
        $this->patientFor(null, ['email' => 'taken@example.com']); // staff-created profile
        $this->actingAs($this->patientUser());

        $this->putJson('/api/profile', ['name' => 'Me', 'email' => 'taken@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    // ---------- Password ----------

    public function test_the_password_can_be_changed_with_the_current_one(): void
    {
        $user = $this->userWithRole(Role::Doctor, ['password' => 'old-password-1']);
        $this->actingAs($user);

        $this->putJson('/api/profile/password', [
            'current_password' => 'old-password-1',
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('brand-new-pass-1', $user->fresh()->password));
    }

    public function test_a_wrong_current_password_is_refused(): void
    {
        $user = $this->userWithRole(Role::Doctor, ['password' => 'old-password-1']);
        $this->actingAs($user);

        $this->putJson('/api/profile/password', [
            'current_password' => 'not-my-password',
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function test_the_new_password_must_differ_be_long_enough_and_be_confirmed(): void
    {
        $this->actingAs($this->userWithRole(Role::Doctor, ['password' => 'old-password-1']));

        $this->putJson('/api/profile/password', [
            'current_password' => 'old-password-1',
            'password' => 'old-password-1',
            'password_confirmation' => 'old-password-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->putJson('/api/profile/password', [
            'current_password' => 'old-password-1',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->putJson('/api/profile/password', [
            'current_password' => 'old-password-1',
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'something-else-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}