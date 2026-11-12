<?php

namespace Tests\Feature;

use App\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class PatientTest extends ApiTestCase
{
    private array $newPatient = [
        'name' => 'Emma Johnson',
        'email' => 'emma@example.com',
        'phone' => '+31 6 12345678',
        'date_of_birth' => '1990-04-15',
    ];

    #[DataProvider('frontDeskRoles')]
    public function test_front_desk_roles_see_every_patient(Role $role): void
    {
        $this->patientFor();
        $this->patientFor();
        $this->patientFor();

        $this->actingAs($this->userWithRole($role));

        $this->getJson('/api/patients')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_doctors_only_see_their_own_patients(): void
    {
        $doctor = $this->userWithRole(Role::Doctor);
        $otherDoctor = $this->userWithRole(Role::Doctor);
        $service = $this->service();

        $mine = $this->patientFor();
        $theirs = $this->patientFor();
        $this->patientFor(); // nobody's patient yet

        $this->appointment($mine, $doctor, $service, self::MONDAY . ' 10:00');
        $this->appointment($theirs, $otherDoctor, $service, self::MONDAY . ' 10:00');

        $this->actingAs($doctor);

        $this->getJson('/api/patients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->getJson("/api/patients/{$mine->id}")->assertOk();
        $this->getJson("/api/patients/{$theirs->id}")->assertForbidden();
    }

    public function test_patients_cannot_browse_the_directory_but_can_open_their_own_profile(): void
    {
        $account = $this->patientUser();
        $stranger = $this->patientFor();

        $this->actingAs($account);

        $this->getJson('/api/patients')->assertForbidden();
        $this->getJson("/api/patients/{$account->patient->id}")->assertOk();
        $this->getJson("/api/patients/{$stranger->id}")->assertForbidden();
    }

    #[DataProvider('frontDeskRoles')]
    public function test_front_desk_roles_can_create_and_edit_patients(Role $role): void
    {
        $this->actingAs($this->userWithRole($role));

        $id = $this->postJson('/api/patients', $this->newPatient)
            ->assertCreated()
            ->assertJsonPath('data.user_id', null)
            ->json('data.id');

        $this->putJson("/api/patients/{$id}", ['phone' => '+31 6 99999999'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+31 6 99999999');
    }

    #[DataProvider('nonFrontDeskRoles')]
    public function test_doctors_and_patients_cannot_create_or_edit_patients(Role $role): void
    {
        $patient = $this->patientFor();
        $this->actingAs($this->userWithRole($role));

        $this->postJson('/api/patients', $this->newPatient)->assertForbidden();
        $this->putJson("/api/patients/{$patient->id}", ['phone' => '000'])->assertForbidden();
    }

    public function test_patient_details_are_validated(): void
    {
        $this->patientFor(null, ['email' => 'taken@example.com']);
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->postJson('/api/patients', ['email' => 'taken@example.com'] + $this->newPatient)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/patients', ['date_of_birth' => '2999-01-01'] + $this->newPatient)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_of_birth');
    }

    #[DataProvider('frontDeskRoles')]
public function test_front_desk_roles_can_create_a_login_for_a_staff_created_patient(Role $role): void
{
    $patient = $this->patientFor(null, ['email' => 'anna@example.com']);
    $this->actingAs($this->userWithRole($role));

    $response = $this->postJson("/api/patients/{$patient->id}/account")->assertCreated();

    $user = User::where('email', 'anna@example.com')->firstOrFail();
    $password = $response->json('temporary_password');

    $this->assertSame(Role::Patient, $user->role);
    $this->assertTrue($user->is_active);
    $this->assertSame($user->id, $patient->fresh()->user_id);
    $this->assertSame($user->id, $response->json('data.user_id'));
    $this->assertGreaterThanOrEqual(8, strlen($password));
    $this->assertTrue(Hash::check($password, $user->password));
}

    #[DataProvider('nonFrontDeskRoles')]
    public function test_doctors_and_patients_cannot_create_logins(Role $role): void
    {
        $patient = $this->patientFor();
        $user = $role === Role::Patient ? $this->patientUser() : $this->userWithRole($role);
        $this->actingAs($user);

        $this->postJson("/api/patients/{$patient->id}/account")->assertForbidden();

        $this->assertNull($patient->fresh()->user_id);
    }

    public function test_a_patient_who_already_has_a_login_cannot_get_a_second_one(): void
    {
        $account = $this->patientUser();
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->postJson("/api/patients/{$account->patient->id}/account")->assertUnprocessable();
    }

    public function test_a_login_is_refused_when_the_email_belongs_to_another_account(): void
    {
        $this->userWithRole(Role::Doctor, ['email' => 'shared@example.com']);
        $patient = $this->patientFor(null, ['email' => 'shared@example.com']);
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->postJson("/api/patients/{$patient->id}/account")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertNull($patient->fresh()->user_id);
    }
}