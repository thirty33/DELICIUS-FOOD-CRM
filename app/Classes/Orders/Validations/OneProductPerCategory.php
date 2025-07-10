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
            // Obtener el menú actual
            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                throw new Exception("No se encontró un menú activo para la fecha");
            }

            // Obtener las categorías del menú ordenadas por display_order
            // Filtrar solo las categorías que tienen productos en la lista de precios de la empresa del usuario
            // $categoryMenus = $currentMenu->categoryMenus()->orderedByDisplayOrder()->get();
            $categoryMenus = $currentMenu->categoryMenus()
                ->whereHas('category.products.priceListLines', function ($query) use ($user) {
                    $query->where('active', true)
                        ->whereHas('priceList', function ($priceListQuery) use ($user) {
                            $priceListQuery->where('id', $user->company->price_list_id);
                        });
                })
                ->orderedByDisplayOrder()
                ->get();

            // Obtener las categorías de los productos en la orden
            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category' => $orderLine->product->category,
                    'product_name' => $orderLine->product->name,
                ];
            });

            // Agrupar los productos por categoría
            $groupedByCategory = $categoriesInOrder->groupBy(function ($item) {
                return $item['category']->id;
            });

            // Si validate_subcategory_rules es true, aplicar la lógica actual
            if ($user->validate_subcategory_rules) {
                // Verificar las categorías sin subcategoría
                foreach ($categoryMenus as $categoryMenu) {
                    $category = $categoryMenu->category;

                    // Si la categoría no tiene subcategorías, debe haber al menos un producto de esta categoría
                    if ($category->subcategories->isEmpty()) {
                        $hasProductInCategory = $categoriesInOrder->contains(function ($item) use ($category) {
                            return $item['category']->id === $category->id;
                        });

                        if (!$hasProductInCategory) {
                            throw new Exception("La orden debe incluir al menos un producto de la categoría: {$category->name}.");
                        }

                        $productsInCategory = $groupedByCategory->get($category->id, []);
                        if ($productsInCategory->count() > 1) {
                            $productNames = $productsInCategory->pluck('product_name')->implode(', ');
                            throw new Exception(
                                "Solo se permite un producto por categoría. Categoría: {$category->name}. " .
                                    "Productos: {$productNames}."
                            );
                        }
                    }
                }

                // Verificar las categorías con subcategorías
                $subcategoriesInOrder = $categoriesInOrder
                    ->filter(function ($item) {
                        return !$item['category']->subcategories->isEmpty(); // Filtrar solo categorías con subcategorías
                    })
                    ->flatMap(function ($item) {
                        return $item['category']->subcategories->map(function ($subcategory) use ($item) {
                            return [
                                'subcategory_name' => $subcategory->name,
                                'product_name' => $item['product_name'],
                                'category_name' => $item['category']->name,
                            ];
                        });
                    })
                    ->groupBy('subcategory_name'); // Agrupar por nombre de subcategoría

                // Validar que haya al menos un producto en la orden para cada subcategoría
                $this->validateCategoriesWithSubcategories($categoryMenus, $categoriesInOrder);

                // Verificar que no haya más de un producto por subcategoría
                foreach ($subcategoriesInOrder as $subcategoryName => $products) {
                    if ($products->count() > 1) {
                        $categoryNames = $products->pluck('category_name')->unique()->implode(', ');
                        $productNames = $products->pluck('product_name')->implode(', ');
                        throw new Exception(
                            "Solo se permite un producto por subcategoría. Subcategoría: {$subcategoryName}. " .
                                "Categorías: {$categoryNames}. Productos: {$productNames}."
                        );
                    }
                }
            } else {
                // Si validate_subcategory_rules es false, aplicar la lógica simplificada
                foreach ($categoryMenus as $categoryMenu) {
                    $category = $categoryMenu->category;

                    // Verificar que haya al menos un producto de la categoría
                    $hasProductInCategory = $categoriesInOrder->contains(function ($item) use ($category) {
                        return $item['category']->id === $category->id;
                    });

                    if (!$hasProductInCategory) {
                        throw new Exception("La orden debe incluir al menos un producto de la categoría: {$category->name}.");
                    }

                    // Verificar que no haya más de un producto por categoría
                    $productsInCategory = $groupedByCategory->get($category->id, []);
                    if ($productsInCategory->count() > 1) {
                        $productNames = $productsInCategory->pluck('product_name')->implode(', ');
                        throw new Exception(
                            "Solo se permite un producto por categoría. Categoría: {$category->name}. " .
                                "Productos: {$productNames}."
                        );
                    }
                }
            }
        }
    }

    /**
     * Validar que haya al menos un producto en la orden para cada subcategoría.
     *
     * @param \Illuminate\Support\Collection $categoryMenus
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @throws Exception
     */
    protected function validateCategoriesWithSubcategories($categoryMenus, $categoriesInOrder): void
    {
        // Obtener todas las subcategorías presentes en el menú
        $subcategoriesInMenu = $categoryMenus
            ->flatMap(function ($categoryMenu) {
                return $categoryMenu->category->subcategories->pluck('name')->toArray();
            })
            ->unique();

        // Obtener todas las subcategorías presentes en la orden
        $subcategoriesInOrder = $categoriesInOrder
            ->flatMap(function ($item) {
                return $item['category']->subcategories->pluck('name')->toArray();
            })
            ->unique();

        // 1. Verificar que haya al menos un producto con 'PLATO DE FONDO', solo si está en el menú
        if ($subcategoriesInMenu->contains('PLATO DE FONDO') && !$subcategoriesInOrder->contains('PLATO DE FONDO')) {
            throw new Exception("La orden debe incluir al menos un producto de la subcategoría: PLATO DE FONDO.");
        }

        // 2. Verificar las reglas de exclusión entre 'SANDWICH' y 'PAN', solo si están en el menú
        $hasSandwichInMenu = $subcategoriesInMenu->contains('SANDWICH');
        $hasPanInMenu = $subcategoriesInMenu->contains('PAN');

        if ($hasSandwichInMenu || $hasPanInMenu) {
            $hasSandwichInOrder = $subcategoriesInOrder->contains('SANDWICH');
            $hasPanInOrder = $subcategoriesInOrder->contains('PAN');

            if ($hasSandwichInOrder && $hasPanInOrder) {
                throw new Exception("No se permite combinar las subcategorías 'SANDWICH' y 'PAN' en la misma orden.");
            }

            if (!$hasSandwichInOrder && !$hasPanInOrder) {
                throw new Exception("La orden debe incluir al menos un producto de la subcategoría 'SANDWICH' o 'PAN'.");
            }
        }

        // 3. Verificar las reglas de exclusión entre 'ENSALADA' y 'MINI-ENSALADA', solo si están en el menú
        $hasEnsaladaInMenu = $subcategoriesInMenu->contains('ENSALADA');
        $hasMiniEnsaladaInMenu = $subcategoriesInMenu->contains('MINI-ENSALADA');

        if ($hasEnsaladaInMenu || $hasMiniEnsaladaInMenu) {
            $hasEnsaladaInOrder = $subcategoriesInOrder->contains('ENSALADA');
            $hasMiniEnsaladaInOrder = $subcategoriesInOrder->contains('MINI-ENSALADA');

            if ($hasEnsaladaInOrder && $hasMiniEnsaladaInOrder) {
                throw new Exception("No se permite combinar las subcategorías 'ENSALADA' y 'MINI-ENSALADA' en la misma orden.");
            }

            if (!$hasEnsaladaInOrder && !$hasMiniEnsaladaInOrder) {
                throw new Exception("La orden debe incluir al menos un producto de la subcategoría 'ENSALADA' o 'MINI-ENSALADA'.");
            }
        }
    }
}