<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use Carbon\Carbon;
use Exception;

class OneProductBySubcategoryValidation extends OrderStatusValidation
{
    // Definir reglas de exclusión entre subcategorías
    protected $subcategoryExclusions = [
        'PLATO DE FONDO' => ['PLATO DE FONDO'], // No puede haber otro producto con la misma subcategoría
        'SANDWICH' => ['PAN'], // Si la categoría tiene 'SANDWICH', no puede tener 'PAN'
        'ENSALADA' => ['MINI-ENSALADA'], // Si la categoría tiene 'ENSALADA', no puede tener 'MINI-ENSALADA'
    ];

    protected function check(Order $order, User $user, Carbon $date): void
    {
        if (UserPermissions::IsAgreementIndividual($user)) {
            // Filtrar los orderLines cuyas categorías tengan al menos una subcategoría
            $filteredOrderLines = $order->orderLines->filter(function ($orderLine) {
                return $orderLine->product->category->subcategories->isNotEmpty();
            });

            // Si no hay orderLines con categorías que tengan subcategorías, no es necesario validar
            if ($filteredOrderLines->isEmpty()) {
                return;
            }

            // Obtener todas las categorías y subcategorías de los productos en los orderLines filtrados
            $productsWithSubcategories = $filteredOrderLines->map(function ($orderLine) {
                return [
                    'product_id' => $orderLine->product->id, // ID del producto
                    'category' => $orderLine->product->category,
                    'subcategories' => $orderLine->product->category->subcategories->pluck('name')->toArray(),
                    'product_name' => $orderLine->product->name,
                ];
            });

            // Verificar las reglas de exclusión entre productos
            $this->validateSubcategoryExclusions($productsWithSubcategories);
        }
    }

    /**
     * Validar las exclusiones de subcategorías entre productos.
     *
     * @param \Illuminate\Support\Collection $products
     * @throws Exception
     */
    protected function validateSubcategoryExclusions($products): void
    {
        // Recorrer cada producto y comparar con los demás productos
        foreach ($products as $index => $product) {
            foreach ($this->subcategoryExclusions as $subcategory => $excludedSubcategories) {
                // Verificar si el producto tiene la subcategoría actual
                if (in_array($subcategory, $product['subcategories'])) {
                    // Buscar otros productos que tengan las subcategorías excluidas
                    foreach ($products as $otherIndex => $otherProduct) {
                        // No comparar el producto consigo mismo
                        if ($otherIndex === $index) {
                            continue;
                        }

                        // Verificar si el otro producto tiene alguna de las subcategorías excluidas
                        foreach ($excludedSubcategories as $excludedSubcategory) {
                            if (in_array($excludedSubcategory, $otherProduct['subcategories'])) {
                                throw new Exception(
                                    "No se permite combinar las subcategorías '{$subcategory}' y '{$excludedSubcategory}'. " .
                                    "Productos conflictivos: '{$product['product_name']}' y '{$otherProduct['product_name']}'."
                                );
                            }
                        }
                    }
                }
            }
        }
    }
}