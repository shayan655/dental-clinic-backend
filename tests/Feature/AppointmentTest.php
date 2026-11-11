<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Service;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

class AppointmentTest extends ApiTestCase
{
    private User $doctor;
    private User $patientAccount;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = $this->userWithRole(Role::Doctor);
        $this->patientAccount = $this->patientUser();
        $this->service = $this->service(['duration_minutes' => 30]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'service_id' => $this->service->id,
            'scheduled_at' => self::MONDAY . 'T10:00:00',
        ];
    }

    // ---------- Booking ----------

    public function test_a_patient_books_for_themselves_and_starts_as_pending(): void
    {
        $other = $this->patientFor();
        $this->actingAs($this->patientAccount);

        // a patient_id in the request is ignored: patients can only book for themselves
        $this->postJson('/api/appointments', $this->payload(['patient_id' => $other->id]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.patient_id', $this->patientAccount->patient->id)
            ->assertJsonPath('data.doctor_id', $this->doctor->id);
    }

    #[DataProvider('frontDeskRoles')]
    public function test_front_desk_bookings_need_a_patient_and_start_as_confirmed(Role $role): void
    {
        $patient = $this->patientFor();
        $this->actingAs($this->userWithRole($role));

        $this->postJson('/api/appointments', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');

        $this->postJson('/api/appointments', $this->payload(['patient_id' => $patient->id]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_doctors_book_follow_ups_with_themselves_for_their_own_patients_only(): void
    {
        $this->userWithRole(Role::Doctor); // a second doctor who could also take the slot
        $mine = $this->patientFor();
        $stranger = $this->patientFor();
        $this->appointment($mine, $this->doctor, $this->service, self::MONDAY . ' 09:00');

        $this->actingAs($this->doctor);

        $this->postJson('/api/appointments', $this->payload(['patient_id' => $mine->id, 'scheduled_at' => self::MONDAY . 'T11:00:00']))
            ->assertCreated()
            ->assertJsonPath('data.doctor_id', $this->doctor->id)
            ->assertJsonPath('data.status', 'confirmed');

        $this->postJson('/api/appointments', $this->payload(['patient_id' => $stranger->id, 'scheduled_at' => self::MONDAY . 'T12:00:00']))
            ->assertForbidden();
    }

    public function test_any_available_doctor_spreads_bookings_and_stops_when_all_are_busy(): void
    {
        $second = $this->userWithRole(Role::Doctor);
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $first = $this->postJson('/api/appointments', $this->payload(['patient_id' => $this->patientFor()->id]))
            ->assertCreated()->json('data.doctor_id');
        $next = $this->postJson('/api/appointments', $this->payload(['patient_id' => $this->patientFor()->id]))
            ->assertCreated()->json('data.doctor_id');

        $this->assertEqualsCanonicalizing([$this->doctor->id, $second->id], [$first, $next]);

        $this->postJson('/api/appointments', $this->payload(['patient_id' => $this->patientFor()->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
    }

    public function test_a_specific_doctor_who_is_busy_cannot_be_booked(): void
    {
        $second = $this->userWithRole(Role::Doctor);
        $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 10:00');
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->postJson('/api/appointments', $this->payload(['patient_id' => $this->patientFor()->id, 'doctor_id' => $this->doctor->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');

        $this->postJson('/api/appointments', $this->payload(['patient_id' => $this->patientFor()->id, 'doctor_id' => $second->id]))
            ->assertCreated()
            ->assertJsonPath('data.doctor_id', $second->id);
    }

    public function test_overlapping_appointments_are_rejected_but_back_to_back_ones_are_fine(): void
    {
        $long = $this->service(['name' => 'Long treatment', 'duration_minutes' => 60]);
        $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 10:00');
        $this->actingAs($this->userWithRole(Role::Receptionist));

        // same start
        $this->postJson('/api/appointments', $this->payload(['patient_id' => $this->patientFor()->id]))->assertUnprocessable();

        // starts before the first one ends
        $this->postJson('/api/appointments', [
            'patient_id' => $this->patientFor()->id,
            'service_id' => $long->id,
            'scheduled_at' => self::MONDAY . 'T09:30:00',
        ])->assertUnprocessable();

        // starts exactly when the first one ends
        $this->postJson('/api/appointments', $this->payload([
            'patient_id' => $this->patientFor()->id,
            'scheduled_at' => self::MONDAY . 'T10:30:00',
        ]))->assertCreated();
    }

    public function test_a_cancelled_appointment_frees_its_slot(): void
    {
        $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 10:00', AppointmentStatus::Cancelled);
        $this->actingAs($this->patientAccount);

        $this->postJson('/api/appointments', $this->payload())->assertCreated();
    }

    public function test_bookings_in_the_past_are_rejected(): void
    {
        $this->actingAs($this->patientAccount);

        $this->postJson('/api/appointments', $this->payload(['scheduled_at' => '2026-11-10T10:00:00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
    }

    public function test_bookings_on_a_closed_day_are_rejected(): void
    {
        $this->actingAs($this->patientAccount);

        $this->postJson('/api/appointments', $this->payload(['scheduled_at' => self::SATURDAY . 'T10:00:00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
    }

    public function test_bookings_outside_opening_hours_or_off_the_slot_grid_are_rejected(): void
    {
        $long = $this->service(['name' => 'Long treatment', 'duration_minutes' => 60]);
        $this->actingAs($this->patientAccount);

        foreach (['08:00:00', '16:45:00', '17:00:00'] as $time) {
            $this->postJson('/api/appointments', $this->payload(['scheduled_at' => self::MONDAY . "T{$time}"]))
                ->assertUnprocessable();
        }

        // a 60-minute treatment must finish by closing time
        $this->postJson('/api/appointments', ['service_id' => $long->id, 'scheduled_at' => self::MONDAY . 'T16:30:00'])
            ->assertUnprocessable();
        $this->postJson('/api/appointments', ['service_id' => $long->id, 'scheduled_at' => self::MONDAY . 'T16:00:00'])
            ->assertCreated();
    }

    // ---------- Availability ----------

    public function test_availability_lists_free_slots_and_leaves_out_booked_ones(): void
    {
        $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 10:00');
        $this->actingAs($this->patientAccount);

        $slots = $this->getJson('/api/availability?date=' . self::MONDAY . '&service_id=' . $this->service->id)
            ->assertOk()
            ->json('data');

        $this->assertContains('09:00', $slots);
        $this->assertNotContains('10:00', $slots);
        $this->assertContains('16:30', $slots);
        $this->assertNotContains('17:00', $slots);
    }

    public function test_availability_is_empty_on_a_closed_day_and_rejects_past_dates(): void
    {
        $this->actingAs($this->patientAccount);

        $this->getJson('/api/availability?date=' . self::SATURDAY . '&service_id=' . $this->service->id)
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->getJson('/api/availability?date=2026-11-10&service_id=' . $this->service->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    // ---------- Who sees what ----------

    public function test_each_role_only_sees_its_own_appointments(): void
    {
        $otherDoctor = $this->userWithRole(Role::Doctor);
        $mine = $this->appointment($this->patientAccount->patient, $this->doctor, $this->service, self::MONDAY . ' 10:00');
        $theirs = $this->appointment($this->patientFor(), $otherDoctor, $this->service, self::MONDAY . ' 11:00');

        $this->actingAs($this->doctor);
        $this->getJson('/api/appointments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson("/api/appointments/{$theirs->id}")->assertForbidden();

        $this->actingAs($this->patientAccount);
        $this->getJson('/api/appointments')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/appointments/{$mine->id}")->assertOk();
        $this->getJson("/api/appointments/{$theirs->id}")->assertForbidden();

        $this->actingAs($this->userWithRole(Role::Receptionist));
        $this->getJson('/api/appointments')->assertOk()->assertJsonCount(2, 'data');
    }

    // ---------- Changing appointments ----------

    public function test_doctors_can_only_change_notes_on_their_own_appointments(): void
    {
        $mine = $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 10:00');
        $theirs = $this->appointment($this->patientFor(), $this->userWithRole(Role::Doctor), $this->service, self::MONDAY . ' 11:00');

        $this->actingAs($this->doctor);

        $this->putJson("/api/appointments/{$mine->id}", ['notes' => 'Bring X-rays'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Bring X-rays');

        $this->putJson("/api/appointments/{$mine->id}", ['status' => 'completed'])->assertForbidden();
        $this->putJson("/api/appointments/{$mine->id}", ['scheduled_at' => self::MONDAY . 'T12:00:00'])->assertForbidden();
        $this->putJson("/api/appointments/{$theirs->id}", ['notes' => 'x'])->assertForbidden();
    }

    public function test_patients_can_only_cancel_their_own_open_appointments(): void
    {
        $patient = $this->patientAccount->patient;
        $mine = $this->appointment($patient, $this->doctor, $this->service, self::MONDAY . ' 10:00', AppointmentStatus::Pending);
        $started = $this->appointment($patient, $this->doctor, $this->service, self::MONDAY . ' 11:00', AppointmentStatus::InProgress);
        $notMine = $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 12:00');

        $this->actingAs($this->patientAccount);

        $this->putJson("/api/appointments/{$mine->id}", ['notes' => 'x'])->assertForbidden();
        $this->putJson("/api/appointments/{$mine->id}", ['status' => 'confirmed'])->assertUnprocessable();
        $this->putJson("/api/appointments/{$notMine->id}", ['status' => 'cancelled'])->assertForbidden();
        $this->putJson("/api/appointments/{$started->id}", ['status' => 'cancelled'])->assertUnprocessable();

        $this->putJson("/api/appointments/{$mine->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_staff_can_reschedule_but_not_into_a_taken_slot(): void
    {
        $appointment = $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 10:00');
        $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 11:00');

        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->putJson("/api/appointments/{$appointment->id}", ['scheduled_at' => self::MONDAY . 'T11:00:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');

        $this->putJson("/api/appointments/{$appointment->id}", ['scheduled_at' => self::MONDAY . 'T12:00:00'])
            ->assertOk()
            ->assertJsonPath('data.scheduled_at', self::MONDAY . 'T12:00:00');

        $this->putJson("/api/appointments/{$appointment->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_completed_and_cancelled_appointments_are_locked(): void
    {
        $done = $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 10:00', AppointmentStatus::Completed);
        $cancelled = $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 11:00', AppointmentStatus::Cancelled);

        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->putJson("/api/appointments/{$done->id}", ['notes' => 'late edit'])->assertUnprocessable();
        $this->putJson("/api/appointments/{$cancelled->id}", ['status' => 'confirmed'])->assertUnprocessable();
    }
}