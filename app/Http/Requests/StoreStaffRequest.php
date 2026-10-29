<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user->can('viewAny', User::class)) {
            return false;
        }

        // An unknown role is a validation problem, not a permission problem
        $role = Role::tryFrom((string) $this->input('role'));

        return $role === null || $user->can('create', [User::class, $role]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(Role::staffValues()), function ($attribute, $value, $fail) {
                $managerExists = $value === Role::ClinicManager->value
                    && User::where('role', Role::ClinicManager)->where('is_active', true)->exists();

                if ($managerExists) {
                    $fail('An active clinic manager account already exists.');
                }
            }],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}