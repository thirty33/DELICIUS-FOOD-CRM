<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use App\Classes\UserPermissions;
use Exception;

class UserPermissionValidation extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {
        // if (!UserPermissions::IsAgreementConsolidated($user) && !UserPermissions::IsAgreementIndividual($user)) {
        //     throw new Exception("User does not have the required permissions to update the order status.");
        // }
    }
}