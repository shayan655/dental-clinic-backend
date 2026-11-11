<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

class StaffTest extends ApiTestCase
{
    public static function rolesAManagerMayCreate(): array
    {
        return ['doctor' => ['doctor'], 'receptionist' => ['receptionist']];
    }

    private function newStaff(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Nina Bakker',
            'email' => 'nina@clinic.test',
            'role' => 'receptionist',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }

    // ---------- Listing ----------

    #[DataProvider('adminRoles')]
    public function test_admins_see_staff_but_not_patients_or_super_admins(Role $role): void
    {
        $actor = $this->userWithRole($role);
        $this->userWithRole(Role::Doctor);
        $this->userWithRole(Role::Receptionist);
        $this->userWithRole(Role::ClinicManager);
        $this->patientUser();
        $this->userWithRole(Role::SuperAdmin);

        $this->actingAs($actor);

        $roles = collect($this->getJson('/api/staff')->assertOk()->json('data'))->pluck('role')->unique()->sort()->values()->all();

        $this->assertSame(['clinic_manager', 'doctor', 'receptionist'], $roles);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_other_roles_cannot_use_staff_management(Role $role): void
    {
        $target = $this->userWithRole(Role::Doctor);
        $user = $role === Role::Patient ? $this->patientUser() : $this->userWithRole($role);
        $this->actingAs($user);

        $this->getJson('/api/staff')->assertForbidden();
        $this->postJson('/api/staff', $this->newStaff())->assertForbidden();
        $this->putJson("/api/staff/{$target->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/staff/{$target->id}")->assertForbidden();
    }

    // ---------- Creating ----------

    #[DataProvider('rolesAManagerMayCreate')]
    public function test_a_manager_creates_doctors_and_receptionists(string $role): void
    {
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->postJson('/api/staff', $this->newStaff(['role' => $role]))
            ->assertCreated()
            ->assertJsonPath('data.role', $role)
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue(Hash::check('password123', User::where('email', 'nina@clinic.test')->value('password')));
    }

    public function test_nobody_creates_super_admins_or_patients_from_the_staff_screen(): void
    {
        foreach ([Role::ClinicManager, Role::SuperAdmin] as $actor) {
            $this->actingAs($this->userWithRole($actor));

            $this->postJson('/api/staff', $this->newStaff(['role' => 'super_admin']))->assertForbidden();
            $this->postJson('/api/staff', $this->newStaff(['role' => 'patient']))->assertForbidden();
        }

        $this->postJson('/api/staff', $this->newStaff(['role' => 'astronaut']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_only_the_super_admin_creates_the_manager_account_and_only_one_can_be_active(): void
    {
        $this->actingAs($this->userWithRole(Role::ClinicManager));
        $this->postJson('/api/staff', $this->newStaff(['role' => 'clinic_manager']))->assertForbidden();

        $this->actingAs($this->userWithRole(Role::SuperAdmin));
        // the manager used above is still active, so a second one is refused
        $this->postJson('/api/staff', $this->newStaff(['role' => 'clinic_manager']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        User::where('role', Role::ClinicManager)->update(['is_active' => false]);

        $this->postJson('/api/staff', $this->newStaff(['role' => 'clinic_manager']))->assertCreated();
    }

    public function test_new_staff_details_are_validated(): void
    {
        $this->userWithRole(Role::Doctor, ['email' => 'taken@clinic.test']);
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->postJson('/api/staff', $this->newStaff(['email' => 'taken@clinic.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/staff', $this->newStaff(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/staff', $this->newStaff(['password_confirmation' => 'different123']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    // ---------- Editing ----------

    public function test_a_manager_edits_doctors_and_receptionists_but_never_the_manager_account(): void
    {
        $manager = $this->userWithRole(Role::ClinicManager);
        $doctor = $this->userWithRole(Role::Doctor);
        $this->actingAs($manager);

        $this->putJson("/api/staff/{$doctor->id}", ['name' => 'Dr. Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Dr. Renamed');

        // including their own account: that goes through the profile page
        $this->putJson("/api/staff/{$manager->id}", ['name' => 'Me'])->assertForbidden();
    }

    public function test_the_super_admin_can_edit_the_manager_account(): void
    {
        $manager = $this->userWithRole(Role::ClinicManager);
        $this->actingAs($this->userWithRole(Role::SuperAdmin));

        $this->putJson("/api/staff/{$manager->id}", ['name' => 'New Manager Name'])->assertOk();
    }

    public function test_patients_and_super_admins_are_not_editable_from_the_staff_screen(): void
    {
        $patient = $this->patientUser();
        $admin = $this->userWithRole(Role::SuperAdmin);

        foreach ([Role::ClinicManager, Role::SuperAdmin] as $actor) {
            $this->actingAs($this->userWithRole($actor));

            $this->putJson("/api/staff/{$patient->id}", ['name' => 'x'])->assertForbidden();
            $this->putJson("/api/staff/{$admin->id}", ['name' => 'x'])->assertForbidden();
        }
    }

    public function test_a_role_cannot_be_changed_after_creation(): void
    {
        $doctor = $this->userWithRole(Role::Doctor);
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->putJson("/api/staff/{$doctor->id}", ['role' => 'receptionist'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        // the edit form sends the unchanged role along, which is fine
        $this->putJson("/api/staff/{$doctor->id}", ['name' => 'Same Role', 'role' => 'doctor'])->assertOk();

        $this->assertSame(Role::Doctor, $doctor->fresh()->role);
    }

    public function test_changing_a_password_signs_the_person_out_everywhere(): void
    {
        $doctor = $this->userWithRole(Role::Doctor);
        DB::table('sessions')->insert(['id' => 'doctor-session', 'user_id' => $doctor->id, 'payload' => 'x', 'last_activity' => time()]);
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->putJson("/api/staff/{$doctor->id}", [
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass-1', $doctor->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $doctor->id]);
    }

    // ---------- Deactivating ----------

    public function test_deleting_staff_deactivates_them_and_ends_their_sessions(): void
    {
        $receptionist = $this->userWithRole(Role::Receptionist);
        DB::table('sessions')->insert(['id' => 'reception-session', 'user_id' => $receptionist->id, 'payload' => 'x', 'last_activity' => time()]);
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->deleteJson("/api/staff/{$receptionist->id}")->assertNoContent();

        // nothing is erased: the history stays, the account just stops working
        $this->assertDatabaseHas('users', ['id' => $receptionist->id, 'is_active' => false]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $receptionist->id]);
    }

    public function test_a_doctor_with_upcoming_appointments_cannot_be_deactivated(): void
    {
        $doctor = $this->userWithRole(Role::Doctor);
        $this->appointment($this->patientFor(), $doctor, $this->service(), self::MONDAY . ' 10:00');
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $response = $this->deleteJson("/api/staff/{$doctor->id}")->assertUnprocessable();

        $this->assertStringContainsString('1 upcoming appointment', $response->json('message'));
        $this->assertDatabaseHas('users', ['id' => $doctor->id, 'is_active' => true]);

        Appointment::query()->update(['status' => AppointmentStatus::Cancelled->value]);

        $this->deleteJson("/api/staff/{$doctor->id}")->assertNoContent();
    }

    public function test_the_manager_account_can_only_be_deactivated_by_the_super_admin(): void
    {
        $manager = $this->userWithRole(Role::ClinicManager);

        $this->actingAs($manager);
        $this->deleteJson("/api/staff/{$manager->id}")->assertForbidden();

        $this->actingAs($this->userWithRole(Role::SuperAdmin));
        $this->deleteJson("/api/staff/{$manager->id}")->assertNoContent();
    }

    public function test_deactivated_staff_can_be_reactivated_but_not_into_a_second_manager(): void
    {
        $doctor = $this->userWithRole(Role::Doctor, ['is_active' => false]);
        $oldManager = $this->userWithRole(Role::ClinicManager, ['is_active' => false]);
        $this->userWithRole(Role::ClinicManager); // the current, active manager

        $this->actingAs($this->userWithRole(Role::SuperAdmin));

        $this->putJson("/api/staff/{$doctor->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->putJson("/api/staff/{$oldManager->id}", ['is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    }

    // ---------- Doctors list and booking ----------

    public function test_the_doctors_list_only_has_active_doctors_and_is_closed_to_patients(): void
    {
        $active = $this->userWithRole(Role::Doctor);
        $this->userWithRole(Role::Doctor, ['is_active' => false]);
        $this->userWithRole(Role::Receptionist);

        $this->actingAs($this->userWithRole(Role::Receptionist));
        $this->getJson('/api/doctors')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);

        $this->actingAs($this->userWithRole(Role::Doctor));
        $this->getJson('/api/doctors')->assertOk();

        $this->actingAs($this->patientUser());
        $this->getJson('/api/doctors')->assertForbidden();
    }

    public function test_deactivated_doctors_are_not_offered_for_booking(): void
    {
        $this->userWithRole(Role::Doctor, ['is_active' => false]);
        $service = $this->service();
        $this->actingAs($this->patientUser());

        $this->getJson('/api/availability?date=' . self::MONDAY . "&service_id={$service->id}")
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}