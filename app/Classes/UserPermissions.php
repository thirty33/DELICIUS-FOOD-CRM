<?php

namespace App\CLasses;

use App\Models\User;
use App\Enums\PermissionName;
use App\Enums\RoleName;

class UserPermissions
{
    public static function IsAgreementConsolidated(User $user)
    {
        return $user->hasPermission(PermissionName::CONSOLIDADO->value) && $user->hasRole(RoleName::AGREEMENT->value);
    }

    public static function IsAgreementIndividual(User $user)
    {
        return $user->hasPermission(PermissionName::INDIVIDUAL->value) && $user->hasRole(RoleName::AGREEMENT->value);
    }

    public static function IsCafe(User $user)
    {
        return $user->hasRole(RoleName::CAFE->value);
    }

    public static function IsAdmin(User $user)
    {
        return $user->hasRole(RoleName::ADMIN->value);
    }

    public static function getRole($user) {
        return $user->roles->first();
    }
    
    public static function getPermission($user) {
        return $user->permissions->first();
    }

}