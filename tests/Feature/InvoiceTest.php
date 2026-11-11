<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ApiTestCase;

class InvoiceTest extends ApiTestCase
{
    private User $doctor;
    private User $patientAccount;
    private Patient $patient;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = $this->userWithRole(Role::Doctor);
        $this->patientAccount = $this->patientUser();
        $this->patient = $this->patientAccount->patient;
        $this->service = $this->service(['name' => 'Cleaning', 'price' => 80]);
    }

    private function invoice(Patient $patient, array $overrides = []): Invoice
    {
        return Invoice::create($overrides + [
            'patient_id' => $patient->id,
            'amount' => 80,
            'status' => InvoiceStatus::Pending,
            'issued_at' => now(),
        ]);
    }

    // ---------- Reading ----------

    #[DataProvider('frontDeskRoles')]
    public function test_front_desk_roles_see_every_invoice(Role $role): void
    {
        $this->invoice($this->patient);
        $this->invoice($this->patientFor());
        $this->actingAs($this->userWithRole($role));

        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_patients_only_see_their_own_invoices_even_when_asking_for_others(): void
    {
        $stranger = $this->patientFor();
        $this->invoice($this->patient);
        $this->invoice($stranger);

        $this->actingAs($this->patientAccount);

        $this->getJson('/api/invoices')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/invoices?patient_id={$stranger->id}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_doctors_cannot_see_billing(): void
    {
        $this->invoice($this->patient);
        $this->actingAs($this->doctor);

        $this->getJson('/api/invoices')->assertForbidden();
    }

    public function test_the_amount_is_returned_as_a_number(): void
    {
        $this->invoice($this->patient, ['amount' => 80]);
        $this->actingAs($this->patientAccount);

        $amount = $this->getJson('/api/invoices')->json('data.0.amount');

        $this->assertIsNumeric($amount);
        $this->assertEquals(80, $amount);
    }

    // ---------- Creating ----------

    #[DataProvider('frontDeskRoles')]
    public function test_front_desk_roles_create_manual_invoices(Role $role): void
    {
        $this->actingAs($this->userWithRole($role));

        $this->postJson('/api/invoices', ['patient_id' => $this->patient->id, 'amount' => 45])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.issued_at', '2026-11-11T08:00:00')
            ->assertJsonPath('data.paid_at', null);
    }

    #[DataProvider('nonFrontDeskRoles')]
    public function test_doctors_and_patients_cannot_create_or_change_invoices(Role $role): void
    {
        $invoice = $this->invoice($this->patient);
        $user = $role === Role::Patient ? $this->patientAccount : $this->userWithRole($role);
        $this->actingAs($user);

        $this->postJson('/api/invoices', ['patient_id' => $this->patient->id, 'amount' => 45])->assertForbidden();
        $this->putJson("/api/invoices/{$invoice->id}", ['status' => 'paid'])->assertForbidden();

        $this->assertSame(InvoiceStatus::Pending, $invoice->fresh()->status);
    }

    public function test_new_invoice_details_are_validated(): void
    {
        $appointment = $this->appointment($this->patient, $this->doctor, $this->service, self::MONDAY . ' 10:00');
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->postJson('/api/invoices', ['amount' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');

        foreach ([0, -5, 100000] as $amount) {
            $this->postJson('/api/invoices', ['patient_id' => $this->patient->id, 'amount' => $amount])
                ->assertUnprocessable()->assertJsonValidationErrors('amount');
        }

        // the appointment must belong to the invoiced patient
        $this->postJson('/api/invoices', [
            'patient_id' => $this->patientFor()->id,
            'appointment_id' => $appointment->id,
            'amount' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('appointment_id');

        // one invoice per appointment
        $this->postJson('/api/invoices', ['patient_id' => $this->patient->id, 'appointment_id' => $appointment->id, 'amount' => 10])
            ->assertCreated();
        $this->postJson('/api/invoices', ['patient_id' => $this->patient->id, 'appointment_id' => $appointment->id, 'amount' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors('appointment_id');
    }

    // ---------- Paying and cancelling ----------

    public function test_staff_can_mark_an_invoice_as_paid(): void
    {
        $invoice = $this->invoice($this->patient);
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->putJson("/api/invoices/{$invoice->id}", ['status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid_at', '2026-11-11T08:00:00');
    }

    public function test_a_pending_invoice_can_be_corrected_or_cancelled(): void
    {
        $invoice = $this->invoice($this->patient);
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->putJson("/api/invoices/{$invoice->id}", ['amount' => 99])
            ->assertOk()
            ->assertJsonPath('data.amount', 99);

        $this->putJson("/api/invoices/{$invoice->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_paid_and_cancelled_invoices_are_final(): void
    {
        $paid = $this->invoice($this->patient, ['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
        $cancelled = $this->invoice($this->patient, ['status' => InvoiceStatus::Cancelled]);
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->putJson("/api/invoices/{$paid->id}", ['amount' => 1])->assertUnprocessable();
        $this->putJson("/api/invoices/{$paid->id}", ['status' => 'cancelled'])->assertUnprocessable();
        $this->putJson("/api/invoices/{$cancelled->id}", ['status' => 'paid'])->assertUnprocessable();

        $this->assertEquals(80, $paid->fresh()->amount);
    }

    public function test_an_invoice_cannot_be_moved_back_to_pending(): void
    {
        $invoice = $this->invoice($this->patient);
        $this->actingAs($this->userWithRole(Role::ClinicManager));

        $this->putJson("/api/invoices/{$invoice->id}", ['status' => 'pending'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    // ---------- Automatic invoices ----------

    public function test_completing_an_appointment_creates_an_invoice_with_the_service_price(): void
    {
        $appointment = $this->appointment($this->patient, $this->doctor, $this->service, self::MONDAY . ' 10:00');
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->putJson("/api/appointments/{$appointment->id}", ['status' => 'completed'])->assertOk();

        $invoice = Invoice::where('appointment_id', $appointment->id)->firstOrFail();

        $this->assertSame($this->patient->id, $invoice->patient_id);
        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        $this->assertSame('80.00', $invoice->amount);

        // later price changes never touch an issued invoice
        $this->service->update(['price' => 120]);
        $this->assertSame('80.00', $invoice->fresh()->amount);
    }

    public function test_other_status_changes_do_not_create_invoices(): void
    {
        $appointment = $this->appointment($this->patient, $this->doctor, $this->service, self::MONDAY . ' 10:00');
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->putJson("/api/appointments/{$appointment->id}", ['status' => 'in_progress'])->assertOk();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_an_appointment_that_already_has_an_invoice_is_not_billed_twice(): void
    {
        $appointment = $this->appointment($this->patient, $this->doctor, $this->service, self::MONDAY . ' 10:00');
        $this->invoice($this->patient, ['appointment_id' => $appointment->id, 'amount' => 55]);
        $this->actingAs($this->userWithRole(Role::Receptionist));

        $this->putJson("/api/appointments/{$appointment->id}", ['status' => 'completed'])->assertOk();

        $this->assertSame(1, Invoice::where('appointment_id', $appointment->id)->count());
    }
}