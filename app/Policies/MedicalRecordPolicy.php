<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MedicalRecordPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Patient $patient): bool
    {
        if ($user->hasRole(Role::Doctor)) {
            return $user->can('view', $patient);
        }

        if ($user->hasRole(Role::Patient)) {
            return $patient->user_id === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Patient $patient): bool
    {
        return $user->hasRole(Role::Doctor) && $user->can('view', $patient);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, MedicalRecord $record): bool
    {
        return $user->hasRole(Role::Doctor) && $record->doctor_id === $user->id;
    }
}
