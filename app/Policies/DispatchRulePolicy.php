<?php

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\DispatchRule;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DispatchRulePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DispatchRule $dispatchRule): bool
    {
        return $user->hasRole(RoleName::ADMIN->value);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DispatchRule $dispatchRule): bool
    {
        return $user->hasRole(RoleName::ADMIN->value);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DispatchRule $dispatchRule): bool
    {
        return $user->hasRole(RoleName::ADMIN->value);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, DispatchRule $dispatchRule): bool
    {
        return $user->hasRole(RoleName::ADMIN->value);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, DispatchRule $dispatchRule): bool
    {
        return $user->hasRole(RoleName::ADMIN->value);
    }
}
