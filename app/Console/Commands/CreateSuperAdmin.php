<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdmin extends Command
{
    protected $signature = 'clinic:create-admin';

    protected $description = 'Create the first super admin account';

    public function handle(): int
    {
        if (User::where('role', Role::SuperAdmin)->exists()) {
            $this->error('A super admin already exists.');

            return self::FAILURE;
        }

        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password (at least 8 characters)');

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        User::forceCreate([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => Role::SuperAdmin,
            'is_active' => true,
        ]);

        $this->info('Super admin created.');

        return self::SUCCESS;
    }
}