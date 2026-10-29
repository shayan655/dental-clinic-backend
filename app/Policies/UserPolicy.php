<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::SuperAdmin, Role::ClinicManager);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $actor, Role $role): bool
    {
        return match (true) {
            $actor->hasRole(Role::SuperAdmin) => in_array($role, Role::staff(), true),
            $actor->hasRole(Role::ClinicManager) => in_array($role, [Role::Doctor, Role::Receptionist], true),
            default => false,
        };
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $actor, User $target): bool
    {
        if (! in_array($target->role, Role::staff(), true)) {
            return false;
        }

        return match (true) {
            $actor->hasRole(Role::SuperAdmin) => true,
            $actor->hasRole(Role::ClinicManager) => $target->role !== Role::ClinicManager,
            default => false
        };
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $actor, User $target): bool
    {
        return $actor->id !== $target->id && $this->update($actor, $target);
    }
}
