<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PatientPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return ! $user->hasRole(Role::Patient);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Patient $patient): bool
    {
        if ($user->hasRole(Role::SuperAdmin, Role::ClinicManager, Role::Receptionist)) {
            return true;
        }

        if ($user->hasRole(Role::Doctor)) {
            return $patient->appointments()->where('doctor_id', $user->id)->exists();
        }

        return $patient->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(Role::SuperAdmin, Role::ClinicManager, Role::Receptionist);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Patient $patient): bool
    {
        return $this->create($user);
    }
}
