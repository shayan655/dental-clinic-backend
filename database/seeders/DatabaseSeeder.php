<?php

namespace Database\Seeders;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        if (app()->isProduction()) {
            return;
        }

        $accounts = [
            ['Super Admin', 'admin@clinic.test', Role::SuperAdmin],
            ['Clinic Manager', 'manager@clinic.test', Role::ClinicManager],
            ['Dr. Doctor', 'doctor@clinic.test', Role::Doctor],
            ['Reception Desk', 'reception@clinic.test', Role::Receptionist],
            ['Test Patient', 'patient@clinic.test', Role::Patient],
            ['Dr. Sophie Jansen', 'doctor2@clinic.test', Role::Doctor],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            $user = User::forceCreate([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'role' => $role,
                'is_active' => true
            ]);

            if ($role == Role::Patient) {
                $user->patient()->create([
                    'name' => $name,
                    'email' => $email,
                    'phone' => '+989111111111',
                    'date_of_birth' => '2006-01-01',
                ]);
            }
        }

        foreach ([['Anna Visser', 'anna@example.com'], ['Tom Bakker', 'tom@example.com']] as [$name, $email]) {
            Patient::create([
                'name' => $name,
                'email' => $email,
                'phone' => '+31 6 8765 4321',
                'date_of_birth' => '1985-09-30',
            ]);
        }

        $services = [
            ['Consultation', 'Initial examination and treatment planning.', 20, 40],
            ['Checkup', 'Routine examination and oral health review.', 30, 60],
            ['Cleaning', 'Professional scaling and polishing.', 45, 80],
            ['Filling', 'Cavity treatment and restoration.', 45, 120],
            ['Whitening', 'In-clinic teeth whitening session.', 60, 250],
            ['Root Canal', 'Endodontic treatment of an infected tooth.', 90, 450],
        ];

        foreach ($services as [$name, $description, $minutes, $price]) {
            Service::create([
                'name' => $name,
                'description' => $description,
                'duration_minutes' => $minutes,
                'price' => $price,
            ]);
        }

        $doctor1 = User::where('email', 'doctor@clinic.test')->first();
        $doctor2 = User::where('email', 'doctor2@clinic.test')->first();
        $checkup = Service::where('name', 'Checkup')->first();
        $cleaning = Service::where('name', 'Cleaning')->first();

        $rows = [
            ['patient@clinic.test', $doctor1, $checkup, now()->addDays(2)->setTime(10, 0), 'confirmed'],
            ['anna@example.com', $doctor1, $cleaning, now()->subDays(10)->setTime(9, 0), 'completed'],
            ['tom@example.com', $doctor2, $checkup, now()->addDays(3)->setTime(14, 0), 'pending'],
        ];

        foreach ($rows as [$email, $doctor, $service, $when, $status]) {
            Appointment::create([
                'patient_id' => Patient::where('email', $email)->value('id'),
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'duration_minutes' => $service->duration_minutes,
                'status' => $status,
                'scheduled_at' => $when,
            ]);
        }

        $anna = Patient::where('email', 'anna@example.com')->first();
        $testPatient = Patient::where('email', 'patient@clinic.test')->first();

        $anna->medical_records()->create([
            'doctor_id' => $doctor1->id,
            'appointment_id' => Appointment::where('patient_id', $anna->id)->value('id'),
            'diagnosis' => 'Mild gingivitis with early-stage plaque buildup.',
            'treatment' => 'Professional cleaning performed. Scaling and polishing completed.',
            'prescription' => 'Chlorhexidine mouthwash 0.12%, twice daily for 2 weeks.',
            'notes' => 'Advised to improve flossing technique. Follow-up in 6 months.',
        ]);

        $testPatient->medical_records()->create([
            'doctor_id' => $doctor1->id,
            'diagnosis' => 'Routine checkup, no issues found.',
            'treatment' => 'Examination and X-rays taken. No cavities detected.',
        ]);

        $anna = Patient::where('email', 'anna@example.com')->first();
        $tom = Patient::where('email', 'tom@example.com')->first();
        $testPatient = Patient::where('email', 'patient@clinic.test')->first();

        Invoice::create([
            'patient_id' => $anna->id,
            'appointment_id' => Appointment::where('patient_id', $anna->id)->value('id'),
            'amount' => 80,
            'status' => InvoiceStatus::Paid,
            'issued_at' => now()->subDays(10),
            'paid_at' => now()->subDays(10),
        ]);

        Invoice::create(['patient_id' => $tom->id, 'amount' => 120, 'status' => InvoiceStatus::Pending, 'issued_at' => now()->subDays(2)]);
        Invoice::create(['patient_id' => $testPatient->id, 'amount' => 60, 'status' => InvoiceStatus::Pending, 'issued_at' => now()->subDay()]);
            }
}
