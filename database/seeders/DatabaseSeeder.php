<?php

namespace Database\Seeders;

use App\Enums\Role;
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
            User::forceCreate([
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'role' => $role,
                'is_active' => true
            ]);
        }
    }
}
