<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use App\Classes\UserPermissions;
use App\Classes\Menus\MenuHelper;
use Exception;

class MandatoryCategoryValidation extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {
        if (UserPermissions::IsCafeIndividual($user)) {
            // Obtener el menú actual
            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                throw new Exception("No se encontró un menú activo para la fecha.");
            }

            // Obtener las categorías obligatorias del menú
            $mandatoryCategories = $currentMenu->categoryMenus()
                ->orderedByDisplayOrder()
                ->where('mandatory_category', true)
                ->get();

            // Obtener las categorías de los productos en la orden
            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return $orderLine->product->category;
            });

            // Verificar cada categoría obligatoria
            foreach ($mandatoryCategories as $categoryMenu) {
                $category = $categoryMenu->category;

                // Verificar si la categoría obligatoria está presente en la orden
                $hasCategoryInOrder = $categoriesInOrder->contains(function ($orderCategory) use ($category) {
                    return $orderCategory->id === $category->id;
                });

                // Si no está presente, lanzar una excepción
                if (!$hasCategoryInOrder) {
                    throw new Exception("Debe seleccionar un producto de la categoría: {$category->name}.");
                }
            }
        }
    }
}
