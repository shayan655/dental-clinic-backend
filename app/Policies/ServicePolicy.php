<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ServicePolicy
{
    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Service $service): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Service $service): bool
    {
        return $this->canManage($user);
    }

    private function canManage(User $user): bool {
        return $user->hasRole(Role::SuperAdmin, Role::ClinicManager);
    }
}
