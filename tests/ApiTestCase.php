<?php

namespace Tests;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    // "Now" is frozen at Wednesday 11 Nov 2026, 08:00. The clinic is open Mon-Fri 09:00-17:00 by default.
    protected const MONDAY = '2026-11-16';
    protected const SATURDAY = '2026-11-14';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-11 08:00:00'));
    }

    // ---- Role groups, used as data providers ----

    public static function adminRoles(): array
    {
        return [
            'super admin' => [Role::SuperAdmin],
            'clinic manager' => [Role::ClinicManager],
        ];
    }

    public static function nonAdminRoles(): array
    {
        return [
            'doctor' => [Role::Doctor],
            'receptionist' => [Role::Receptionist],
            'patient' => [Role::Patient],
        ];
    }

    public static function frontDeskRoles(): array
    {
        return [
            'super admin' => [Role::SuperAdmin],
            'clinic manager' => [Role::ClinicManager],
            'receptionist' => [Role::Receptionist],
        ];
    }

    public static function nonFrontDeskRoles(): array
    {
        return [
            'doctor' => [Role::Doctor],
            'patient' => [Role::Patient],
        ];
    }

    // ---- Data builders ----

    protected function userWithRole(Role $role, array $attributes = []): User
    {
        return User::factory()->create(
            array_merge(['is_active' => true], $attributes, ['role' => $role])
        );
    }

    // A patient who can log in: a user account plus the linked clinic profile
    protected function patientUser(array $profile = []): User
    {
        $user = $this->userWithRole(Role::Patient);

        $this->patientFor($user, $profile + ['name' => $user->name, 'email' => $user->email]);

        return $user;
    }

    protected function patientFor(?User $user = null, array $attributes = []): Patient
    {
        $patient = new Patient($attributes + [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+31 6 12345678',
            'date_of_birth' => '1990-04-12',
        ]);

        if ($user) {
            $patient->user_id = $user->id;
        }

        $patient->save();

        return $patient;
    }

    protected function service(array $attributes = []): Service
    {
        return Service::create($attributes + [
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'duration_minutes' => 30,
            'price' => 50,
        ]);
    }

    protected function appointment(
        Patient $patient,
        User $doctor,
        Service $service,
        string $when,
        AppointmentStatus $status = AppointmentStatus::Confirmed
    ): Appointment {
        return Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'duration_minutes' => $service->duration_minutes,
            'status' => $status,
            'scheduled_at' => Carbon::parse($when),
        ]);
    }

    // The headers your real frontend sends, so Sanctum treats the request as a cookie-session one
    protected function asFrontend(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://app.clinic.test',
            'Referer' => 'http://app.clinic.test/',
        ]);
    }
}