<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AppointmentPolicy
{
    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

     /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Appointment $appointment): bool
    {
        if ($user->hasRole(Role::SuperAdmin, Role::ClinicManager, Role::Receptionist)) {
            return true;
        }

        if ($user->hasRole(Role::Doctor)) {
            return $appointment->doctor_id === $user->id;
        }

        return $appointment->patient->user_id === $user->id;
    }
}
