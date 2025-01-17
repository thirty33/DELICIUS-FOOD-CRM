<?php

namespace App\Classes\Orders\Validations;

use App\Classes\Menus\MenuHelper;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use App\Models\Menu;
use App\Classes\UserPermissions;
use Exception;

class MenuExistsValidation extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {   
        $menuExists = MenuHelper::getCurrentMenuQuery($date, $user)
            ->exists();

        if (!$menuExists) {
            throw new Exception("No hay un menú disponible para esta fecha de despacho");
        }
    }
}