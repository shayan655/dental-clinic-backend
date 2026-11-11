<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\ApiTestCase;

class DashboardTest extends ApiTestCase
{
    private const TODAY = '2026-11-11';
    private const TOMORROW = '2026-11-12';

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->service(['name' => 'Checkup']);
    }

    private function invoice(Patient $patient, InvoiceStatus $status): Invoice
    {
        return Invoice::create([
            'patient_id' => $patient->id,
            'amount' => 50,
            'status' => $status,
            'issued_at' => now(),
        ]);
    }

    public function test_each_dashboard_is_limited_to_its_own_role(): void
    {
        $matrix = [
            'manager' => [Role::SuperAdmin, Role::ClinicManager],
            'doctor' => [Role::Doctor],
            'receptionist' => [Role::Receptionist],
            'patient' => [Role::Patient],
        ];

        foreach (Role::cases() as $role) {
            $this->actingAs($role === Role::Patient ? $this->patientUser() : $this->userWithRole($role));

            foreach ($matrix as $dashboard => $allowed) {
                $response = $this->getJson("/api/dashboard/{$dashboard}");

                in_array($role, $allowed, true) ? $response->assertOk() : $response->assertForbidden();
            }
        }
    }

    // ---------- Manager ----------

    public function test_the_manager_dashboard_counts_the_right_things(): void
    {
        $d1 = $this->userWithRole(Role::Doctor);
        $d2 = $this->userWithRole(Role::Doctor);
        $this->userWithRole(Role::Doctor, ['is_active' => false]);

        [$p1, $p2, $p3] = [$this->patientFor(), $this->patientFor(), $this->patientFor()];

        $this->appointment($p1, $d1, $this->service, self::TODAY . ' 10:00', AppointmentStatus::Confirmed);
        $this->appointment($p2, $d2, $this->service, self::TODAY . ' 11:00', AppointmentStatus::Pending);
        $this->appointment($p3, $d1, $this->service, self::TODAY . ' 12:00', AppointmentStatus::Cancelled);
        $this->appointment($p1, $d1, $this->service, self::TOMORROW . ' 10:00', AppointmentStatus::Confirmed);

        $this->invoice($p1, InvoiceStatus::Pending);
        $this->invoice($p2, InvoiceStatus::Pending);
        $this->invoice($p3, InvoiceStatus::Paid);

        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->getJson('/api/dashboard/manager')
            ->assertOk()
            ->assertJsonPath('stats.appointmentsToday', 2)   // the cancelled one is not counted
            ->assertJsonPath('stats.totalPatients', 3)
            ->assertJsonPath('stats.pendingInvoices', 2)
            ->assertJsonPath('stats.doctorsOnDuty', 2)       // the deactivated doctor is not on duty
            ->assertJsonCount(3, 'todaysAppointments')       // but the list still shows the cancellation
            ->assertJsonPath('todaysAppointments.0.time', '10:00')
            ->assertJsonPath('todaysAppointments.0.patientName', $p1->name)
            ->assertJsonPath('todaysAppointments.0.service', 'Checkup')
            ->assertJsonPath('todaysAppointments.2.status', 'cancelled');
    }

    public function test_nobody_is_on_duty_when_the_clinic_is_closed(): void
    {
        $this->userWithRole(Role::Doctor);
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->travelTo(Carbon::parse(self::SATURDAY . ' 08:00:00'));

        $this->getJson('/api/dashboard/manager')->assertOk()->assertJsonPath('stats.doctorsOnDuty', 0);
    }

    // ---------- Doctor ----------

    public function test_the_doctor_dashboard_only_covers_their_own_day(): void
    {
        $doctor = $this->userWithRole(Role::Doctor);
        $other = $this->userWithRole(Role::Doctor);

        $this->appointment($this->patientFor(), $doctor, $this->service, self::TODAY . ' 09:00', AppointmentStatus::Completed);
        $this->appointment($this->patientFor(), $doctor, $this->service, self::TODAY . ' 10:00', AppointmentStatus::Confirmed);
        $this->appointment($this->patientFor(), $doctor, $this->service, self::TODAY . ' 11:00', AppointmentStatus::InProgress);
        $this->appointment($this->patientFor(), $doctor, $this->service, self::TODAY . ' 12:00', AppointmentStatus::Cancelled);
        $this->appointment($this->patientFor(), $other, $this->service, self::TODAY . ' 10:00', AppointmentStatus::Confirmed);

        $this->actingAs($doctor);

        $this->getJson('/api/dashboard/doctor')
            ->assertOk()
            ->assertJsonPath('stats.myAppointmentsToday', 3)
            ->assertJsonPath('stats.completedToday', 1)
            ->assertJsonPath('stats.pendingToday', 2)        // confirmed + in progress are still to do
            ->assertJsonPath('stats.myTotalPatients', 4)     // the other doctor's patient is not counted
            ->assertJsonCount(4, 'todaysAppointments');
    }

    // ---------- Receptionist ----------

    public function test_the_receptionist_dashboard_counts_arrivals_and_new_patients(): void
    {
        $doctor = $this->userWithRole(Role::Doctor);

        $oldPatient = $this->patientFor();
        $oldPatient->forceFill(['created_at' => now()->subDays(2)])->save();

        $arrivals = [$this->patientFor(), $this->patientFor(), $this->patientFor()];

        $this->appointment($arrivals[0], $doctor, $this->service, self::TODAY . ' 09:00', AppointmentStatus::Completed);
        $this->appointment($arrivals[1], $doctor, $this->service, self::TODAY . ' 10:00', AppointmentStatus::InProgress);
        $this->appointment($arrivals[2], $doctor, $this->service, self::TODAY . ' 11:00', AppointmentStatus::Confirmed);
        $this->appointment($oldPatient, $doctor, $this->service, self::TODAY . ' 12:00', AppointmentStatus::Cancelled);

        $this->invoice($arrivals[0], InvoiceStatus::Pending);
        $this->invoice($arrivals[1], InvoiceStatus::Paid);

        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->getJson('/api/dashboard/receptionist')
            ->assertOk()
            ->assertJsonPath('stats.appointmentsToday', 3)
            ->assertJsonPath('stats.checkedInCount', 2)      // in progress + completed
            ->assertJsonPath('stats.pendingInvoices', 1)
            ->assertJsonPath('stats.newPatientsToday', 3)    // the patient created two days ago is not new
            ->assertJsonCount(4, 'todaysAppointments');
    }

    // ---------- Patient ----------

    public function test_the_patient_dashboard_shows_the_next_visit_and_recent_treatments(): void
    {
        $account = $this->patientUser();
        $patient = $account->patient;
        $doctor = $this->userWithRole(Role::Doctor, ['name' => 'Dr. House']);

        // upcoming: the cancelled one is earliest but must be ignored
        $this->appointment($patient, $doctor, $this->service, '2026-11-12 10:00', AppointmentStatus::Cancelled);
        $next = $this->appointment($patient, $doctor, $this->service, self::MONDAY . ' 10:00', AppointmentStatus::Confirmed);
        $this->appointment($patient, $doctor, $this->service, '2026-11-18 09:00', AppointmentStatus::Pending);

        // past visits: four completed, only the latest three are shown
        foreach (['2026-10-01', '2026-10-08', '2026-10-15'] as $date) {
            $this->appointment($patient, $doctor, $this->service, "{$date} 10:00", AppointmentStatus::Completed);
        }
        $latest = $this->appointment($patient, $doctor, $this->service, '2026-10-22 10:00', AppointmentStatus::Completed);
        $latest->update(['notes' => 'secret reception note']);

        $patient->medicalRecords()->create([
            'doctor_id' => $doctor->id,
            'appointment_id' => $latest->id,
            'diagnosis' => 'Plaque',
            'treatment' => 'Scaling and polishing',
        ]);

        $this->actingAs($account);

        $response = $this->getJson('/api/dashboard/patient')
            ->assertOk()
            ->assertJsonPath('nextAppointment.id', $next->id)
            ->assertJsonPath('nextAppointment.date', 'Monday, 16 November 2026')
            ->assertJsonPath('nextAppointment.time', '10:00')
            ->assertJsonPath('nextAppointment.service', 'Checkup')
            ->assertJsonPath('nextAppointment.doctorName', 'Dr. House')
            ->assertJsonPath('nextAppointment.status', 'confirmed')
            ->assertJsonCount(3, 'recentVisits')
            ->assertJsonPath('recentVisits.0.id', $latest->id)
            ->assertJsonPath('recentVisits.0.date', '22 Oct 2026')
            ->assertJsonPath('recentVisits.0.notes', 'Scaling and polishing')
            ->assertJsonPath('recentVisits.1.notes', '');

        // internal appointment notes never reach the patient
        $response->assertDontSee('secret reception note');
    }

    public function test_a_patient_without_visits_gets_an_empty_dashboard(): void
    {
        $this->actingAs($this->patientUser());

        $this->getJson('/api/dashboard/patient')
            ->assertOk()
            ->assertJsonPath('nextAppointment', null)
            ->assertJsonCount(0, 'recentVisits');
    }
}