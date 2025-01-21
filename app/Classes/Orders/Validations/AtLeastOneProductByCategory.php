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
        if (UserPermissions::IsAgreementConsolidated($user)) {

            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                throw new Exception("No se encontró un menú activo para la fecha");
            }

            // Obtener las categorías del menú ordenadas por display_order
            $categoryMenus = $currentMenu->categoryMenus()->orderedByDisplayOrder()->get();

            // Obtener las categorías de los productos en la orden
            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category' => $orderLine->product->category,
                    'quantity' => $orderLine->quantity,
                ];
            });

            // Verificar que la orden incluya al menos un producto de cada categoría
            foreach ($categoryMenus as $categoryMenu) {
                $category = $categoryMenu->category;
                $hasProductInCategory = $categoriesInOrder->contains('category.id', $category->id);

                if (!$hasProductInCategory) {
                    throw new Exception("La orden debe incluir al menos un producto de la categoría: {$category->name}.");
                }
            }

            // Agrupar las cantidades por categoría y calcular la suma por categoría
            $quantitiesByCategory = $categoriesInOrder->groupBy('category.id')->map(function ($items) {
                return $items->sum('quantity'); // Sumar las cantidades de los productos por categoría
            });

            // Verificar que todas las sumas de cantidades por categoría sean iguales
            $uniqueQuantities = $quantitiesByCategory->unique();

            if ($uniqueQuantities->count() > 1) {
                throw new Exception("Cada categoría debe tener la misma cantidad de productos.");
            }
        }

        // Validación de cantidad para UserPermissions::IsAgreementIndividual
        if (UserPermissions::IsAgreementIndividual($user)) {
            $quantities = $order->orderLines->pluck('quantity')->unique();

            if ($quantities->count() > 1) {
                throw new Exception("Todos los productos en la orden deben tener la misma cantidad.");
            }
        }
    }
}