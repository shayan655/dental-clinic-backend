<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ContactMessagePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::SuperAdmin, Role::ClinicManager, Role::Receptionist);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ContactMessage $contactMessage): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ContactMessage $contactMessage): bool
    {
        return $user->hasRole(Role::SuperAdmin, Role::ClinicManager);
    }
}
