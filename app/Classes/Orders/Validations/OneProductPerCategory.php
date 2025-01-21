<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use App\Classes\Menus\MenuHelper;
use Carbon\Carbon;
use Exception;

class OneProductPerCategory extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {

        if (UserPermissions::IsAgreementIndividual($user)) {
            
            //test
            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                throw new Exception("No se encontró un menú activo para la fecha");
            }

            // Obtener las categorías del menú ordenadas por display_order
            $categoryMenus = $currentMenu->categoryMenus()->orderedByDisplayOrder()->get();

            // Obtener las categorías de los productos en la orden
            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return $orderLine->product->category;
            });

            // Verificar las categorías sin subcategoría
            foreach ($categoryMenus as $categoryMenu) {
                $category = $categoryMenu->category;

                // Si la categoría no tiene subcategoría, debe haber al menos un producto de esta categoría
                if (is_null($category->subcategory)) {
                    $hasProductInCategory = $categoriesInOrder->contains('id', $category->id);

                    if (!$hasProductInCategory) {
                        throw new Exception("La orden debe incluir al menos un producto de la categoría: {$category->name}.");
                    }
                }
            }

            // Verificar las categorías con subcategoría
            $subcategoriesInOrder = $categoriesInOrder
                ->filter(function ($category) {
                    return !is_null($category->subcategory); // Filtrar solo categorías con subcategoría
                })
                ->groupBy('subcategory'); // Agrupar por subcategoría

            foreach ($subcategoriesInOrder as $subcategory => $categories) {
                if ($categories->count() > 1) {
                    throw new Exception("Solo puede haber un producto de la subcategoría: {$subcategory}.");
                }
            }
            //

            // Get all the categories of the products in the order
            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category_id' => $orderLine->product->category->id,
                    'category_name' => $orderLine->product->category->name,
                    'product_name' => $orderLine->product->name,
                ];
            });
            
            // Group the products by category
            $groupedByCategory = $categoriesInOrder->groupBy('category_id');

            // Check that there is no more than one product per category
            foreach ($groupedByCategory as $categoryId => $products) {
                if ($products->count() > 1) {
                    $categoryName = $products->first()['category_name'];
                    $productNames = $products->pluck('product_name')->implode(', ');
                    throw new Exception("Solo se permite un producto por categoría. Categoría: {$categoryName}. Productos: {$productNames}.");
                }
            }
            
        }
    }
}
