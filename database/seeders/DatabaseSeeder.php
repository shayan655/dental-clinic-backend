<?php

namespace Database\Seeders;

use App\Enums\Role;
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
    }
}
