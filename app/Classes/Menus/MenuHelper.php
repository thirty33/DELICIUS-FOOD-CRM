<?php

namespace App\Classes\Menus;

use App\Classes\UserPermissions;
use App\Models\Menu;
use Illuminate\Support\Carbon;

class MenuHelper
{

    public static function getMenu($date, $user)
    {
        return Menu::where('publication_date', $date)
            ->where('role_id', UserPermissions::getRole($user)->id)
            ->where('permissions_id', UserPermissions::getPermission($user)->id);
    }

    public static function getCurrentMenuQuery($date, $user)
    {
        $query = Menu::where('publication_date', $date)
            ->where('role_id', UserPermissions::getRole($user)->id)
            ->where('permissions_id', UserPermissions::getPermission($user)->id)
            ->where('publication_date', '>=', Carbon::now()->startOfDay())
            ->where('active', 1);

        if ($user->allow_late_orders) {
            $query->where('max_order_date', '>', Carbon::now());
        }

        return $query;
    }
}
