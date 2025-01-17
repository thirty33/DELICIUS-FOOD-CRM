<?php

namespace App\Classes\Orders\Validations;

use App\Classes\Menus\MenuHelper;
use App\Classes\UserPermissions;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

class AtLeastOneProductByCategory extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {
        if (UserPermissions::IsAgreement($user)) {

            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                throw new Exception("No se encontró un menú activo para la fecha");
            }

            $categoryMenus = $currentMenu->categoryMenus;

            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return $orderLine->product->category;
            })->unique();

            foreach ($categoryMenus as $categoryMenu) {
                $category = $categoryMenu->category;
                $hasProductInCategory = $categoriesInOrder->contains('id', $category->id);

                if (!$hasProductInCategory) {
                    throw new Exception("La orden debe incluir al menos un producto de la categoría: {$category->name}.");
                }
            }

            $quantities = $order->orderLines->pluck('quantity')->unique();

            if ($quantities->count() > 1) {
                throw new Exception("Todos los productos en la orden deben tener la misma cantidad.");
            }
        }
    }
}
