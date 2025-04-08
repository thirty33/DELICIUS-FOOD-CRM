<?php

namespace App\Policies;
use App\Enums\RoleName;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN->value);
    }
}
