<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use Carbon\Carbon;
use Exception;

class OneProductBySubcategoryValidation extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {
        if (UserPermissions::IsAgreementIndividual($user)) {

            // Obtener todas las subcategorías de las categorías de los productos en el pedido
            $subcategoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'subcategory' => $orderLine->product->category->subcategory, // Subcategoría de la categoría del producto
                    'category_name' => $orderLine->product->category->name, // Nombre de la categoría
                    'product_name' => $orderLine->product->name, // Nombre del producto
                ];
            });

            // Filtrar los productos que no tienen subcategoría (subcategory es null)
            $filteredSubcategories = $subcategoriesInOrder->filter(function ($item) {
                return !is_null($item['subcategory']); // Solo incluir elementos con subcategoría no nula
            });

            // Agrupar los productos por subcategoría
            $groupedBySubcategory = $filteredSubcategories->groupBy('subcategory');

            // Verificar que no haya más de un producto por subcategoría
            foreach ($groupedBySubcategory as $subcategory => $products) {
                if ($products->count() > 1) {
                    $categoryNames = $products->pluck('category_name')->implode(', ');
                    $productNames = $products->pluck('product_name')->implode(', ');
                    throw new Exception("No se permite más de un producto por subcategoría. Subcategoría: {$subcategory}. Categorías: {$categoryNames}. Productos: {$productNames}.");
                }
            }
        }
    }
}