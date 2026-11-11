<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

class MedicalRecordTest extends ApiTestCase
{
    private User $doctor;
    private User $otherDoctor;
    private User $patientAccount;
    private Patient $patient;
    private Service $service;
    private Appointment $visit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = $this->userWithRole(Role::Doctor);
        $this->otherDoctor = $this->userWithRole(Role::Doctor);
        $this->patientAccount = $this->patientUser();
        $this->patient = $this->patientAccount->patient;
        $this->service = $this->service();

        // $this->doctor treats this patient; $this->otherDoctor has nothing to do with them
        $this->visit = $this->appointment($this->patient, $this->doctor, $this->service, self::MONDAY . ' 10:00');
    }

    private function url(?Patient $patient = null, ?MedicalRecord $record = null): string
    {
        $patient ??= $this->patient;

        return "/api/patients/{$patient->id}/records" . ($record ? "/{$record->id}" : '');
    }

    private function record(Patient $patient, User $doctor, array $attributes = []): MedicalRecord
    {
        return $patient->medicalRecords()->create($attributes + [
            'doctor_id' => $doctor->id,
            'diagnosis' => 'Mild gingivitis',
            'treatment' => 'Professional cleaning',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['diagnosis' => 'Cavity on lower left molar', 'treatment' => 'Filling placed'];
    }

    // ---------- Reading ----------

    public function test_doctors_read_every_record_of_their_own_patients(): void
    {
        $this->record($this->patient, $this->doctor);
        $this->record($this->patient, $this->otherDoctor, ['diagnosis' => 'Earlier diagnosis']);

        $this->actingAs($this->doctor);

        $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_doctors_cannot_read_the_records_of_patients_that_are_not_theirs(): void
    {
        $this->record($this->patient, $this->doctor);

        $this->actingAs($this->otherDoctor);

        $this->getJson($this->url())->assertForbidden();
    }

    public function test_patients_read_their_own_records_but_nobody_elses(): void
    {
        $stranger = $this->patientFor();
        $this->record($this->patient, $this->doctor);
        $this->record($stranger, $this->doctor);

        $this->actingAs($this->patientAccount);

        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url($stranger))->assertForbidden();
    }

    #[DataProvider('frontDeskRoles')]
    public function test_front_desk_roles_never_touch_clinical_data(Role $role): void
    {
        $record = $this->record($this->patient, $this->doctor);
        $this->actingAs($this->userWithRole($role));

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->putJson($this->url(null, $record), ['diagnosis' => 'Changed'])->assertForbidden();

        $this->assertSame('Mild gingivitis', $record->fresh()->diagnosis);
    }

    // ---------- Writing ----------

    public function test_a_doctor_creates_a_record_for_their_patient_as_themselves(): void
    {
        $this->actingAs($this->doctor);

        // doctor_id and patient_id in the body must be ignored
        $response = $this->postJson($this->url(), $this->payload([
            'doctor_id' => $this->otherDoctor->id,
            'patient_id' => 9999,
        ]))->assertCreated();

        $this->assertDatabaseHas('medical_records', [
            'id' => $response->json('data.id'),
            'doctor_id' => $this->doctor->id,
            'patient_id' => $this->patient->id,
        ]);
    }

    public function test_a_doctor_cannot_write_records_for_someone_elses_patient(): void
    {
        $this->actingAs($this->otherDoctor);

        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('medical_records', 0);
    }

    public function test_patients_cannot_create_or_change_records(): void
    {
        $record = $this->record($this->patient, $this->doctor);
        $this->actingAs($this->patientAccount);

        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->putJson($this->url(null, $record), ['diagnosis' => 'Changed'])->assertForbidden();
    }

    public function test_only_the_author_can_edit_a_record(): void
    {
        // the other doctor can see this patient too, but did not write the record
        $this->appointment($this->patient, $this->otherDoctor, $this->service, self::MONDAY . ' 11:00');
        $record = $this->record($this->patient, $this->doctor);

        $this->actingAs($this->otherDoctor);
        $this->putJson($this->url(null, $record), ['diagnosis' => 'Overwritten'])->assertForbidden();
        $this->assertSame('Mild gingivitis', $record->fresh()->diagnosis);

        $this->actingAs($this->doctor);
        $this->putJson($this->url(null, $record), ['diagnosis' => 'Updated by the author'])
            ->assertOk()
            ->assertJsonPath('data.diagnosis', 'Updated by the author');
    }

    public function test_a_record_cannot_be_reached_through_another_patients_url(): void
    {
        $other = $this->patientFor();
        $this->appointment($other, $this->doctor, $this->service, self::MONDAY . ' 12:00');
        $recordOfOther = $this->record($other, $this->doctor);

        $this->actingAs($this->doctor);

        $this->putJson($this->url($this->patient, $recordOfOther), ['diagnosis' => 'x'])->assertNotFound();
    }

    public function test_a_linked_appointment_must_belong_to_this_patient_and_this_doctor(): void
    {
        $strangersVisit = $this->appointment($this->patientFor(), $this->doctor, $this->service, self::MONDAY . ' 13:00');
        $otherDoctorsVisit = $this->appointment($this->patient, $this->otherDoctor, $this->service, self::MONDAY . ' 14:00');

        $this->actingAs($this->doctor);

        $this->postJson($this->url(), $this->payload(['appointment_id' => $strangersVisit->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');

        $this->postJson($this->url(), $this->payload(['appointment_id' => $otherDoctorsVisit->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');

        $this->postJson($this->url(), $this->payload(['appointment_id' => $this->visit->id]))
            ->assertCreated()
            ->assertJsonPath('data.appointment_id', $this->visit->id);
    }

    public function test_diagnosis_and_treatment_are_required(): void
    {
        $this->actingAs($this->doctor);

        $this->postJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['diagnosis', 'treatment']);
    }

    public function test_records_cannot_be_deleted(): void
    {
        $record = $this->record($this->patient, $this->doctor);
        $this->actingAs($this->doctor);

        $this->deleteJson($this->url(null, $record))->assertMethodNotAllowed();

        $this->assertDatabaseHas('medical_records', ['id' => $record->id]);
    }
}