<?php

namespace App\Classes\Orders\Validations;

use App\Classes\Menus\MenuHelper;
use App\Classes\UserPermissions;
use App\Models\Order;
use App\Models\User;
use App\Repositories\CategoryMenuRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

class AtLeastOneProductByCategory extends OrderStatusValidation
{
    // protected function check(Order $order, User $user, Carbon $date): void
    // {
    //     if (UserPermissions::IsAgreementConsolidated($user)) {

    //         $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

    //         if (!$currentMenu) {
    //             throw new Exception("No se encontró un menú activo para la fecha");
    //         }

    //         // Use repository to get category menus filtered by price list
    //         $categoryMenuRepository = app(CategoryMenuRepository::class);
    //         $categoryMenus = $categoryMenuRepository->getCategoryMenusForValidation($currentMenu, $user);

    //         // Obtener las categorías de los productos en la orden
    //         $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
    //             return [
    //                 'category' => $orderLine->product->category,
    //                 'quantity' => $orderLine->quantity,
    //             ];
    //         });

    //         // Verificar que la orden incluya al menos un producto de cada categoría
    //         // Solo se validan las categorías que tienen productos en la lista de precios de la empresa
    //         foreach ($categoryMenus as $categoryMenu) {
    //             $category = $categoryMenu->category;
    //             $hasProductInCategory = $categoriesInOrder->contains('category.id', $category->id);

    //             if (!$hasProductInCategory) {
    //                 throw new Exception("La orden debe incluir al menos un producto de la categoría: {$category->name}.");
    //             }
    //         }

    //         // Agrupar las cantidades por categoría y calcular la suma por categoría
    //         $quantitiesByCategory = $categoriesInOrder->groupBy('category.id')->map(function ($items) {
    //             return $items->sum('quantity'); // Sumar las cantidades de los productos por categoría
    //         });

    //         // Verificar que todas las sumas de cantidades por categoría sean iguales
    //         $uniqueQuantities = $quantitiesByCategory->unique();

    //         if ($uniqueQuantities->count() > 1) {
    //             throw new Exception("Cada categoría debe tener la misma cantidad de productos.");
    //         }
    //     }

    //     // Validación de cantidad para UserPermissions::IsAgreementIndividual
    //     if (UserPermissions::IsAgreementIndividual($user)) {
    //         $quantities = $order->orderLines->pluck('quantity')->unique();

    //         if ($quantities->count() > 1) {
    //             throw new Exception("Todos los productos en la orden deben tener la misma cantidad.");
    //         }
    //     }
    // }

    protected function check(Order $order, User $user, Carbon $date): void
    {
        \Log::debug('AtLeastOneProductByCategory: Starting validation', [
            'order_id' => $order->id,
            'user_id' => $user->id,
            'user_nickname' => $user->nickname,
            'user_type' => 'CONSOLIDATED',
            'validate_subcategory_rules' => $user->validate_subcategory_rules
        ]);

        if (UserPermissions::IsAgreementConsolidated($user)) {
            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                \Log::error('AtLeastOneProductByCategory: No active menu found');
                throw new Exception("No se encontró un menú activo para la fecha");
            }

            \Log::debug('AtLeastOneProductByCategory: Found current menu', [
                'menu_id' => $currentMenu->id,
                'menu_title' => $currentMenu->title
            ]);

            // Use repository to get category menus filtered by price list
            $categoryMenuRepository = app(CategoryMenuRepository::class);
            $categoryMenus = $categoryMenuRepository->getCategoryMenusForValidation($currentMenu, $user);

            \Log::debug('AtLeastOneProductByCategory: Category menus for validation', [
                'category_menus_count' => $categoryMenus->count(),
                'category_names' => $categoryMenus->pluck('category.name')->toArray()
            ]);

            // Obtener las categorías de los productos en la orden
            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category' => $orderLine->product->category,
                    'quantity' => $orderLine->quantity,
                    'product_name' => $orderLine->product->name
                    // 'is_null_product' => $orderLine->product->is_null_product // TODO: Add for null product filtering
                ];
            });

            \Log::debug('AtLeastOneProductByCategory: Categories in order', [
                'order_lines_count' => $order->orderLines->count(),
                'categories_in_order' => $categoriesInOrder->pluck('category.name')->toArray(),
                'products_in_order' => $categoriesInOrder->pluck('product_name')->toArray()
            ]);

            // FIXED: Check if we should use subcategory validation AND if subcategories exist
            if ($user->validate_subcategory_rules && $this->hasSubcategoriesInMenu($categoryMenus)) {
                // 1. Validate required subcategories
                $this->validateConsolidatedWithSubcategories($categoryMenus, $categoriesInOrder);

                // 2. ALSO validate categories without subcategories  
                $this->validateCategoriesWithoutSubcategories($categoryMenus, $categoriesInOrder);
            } else {
                // Standard validation for all categories
                $this->validateConsolidatedWithoutSubcategories($categoryMenus, $categoriesInOrder);
            }

            // Rest of the quantity validation remains the same...
            // TODO: EXCLUDE null products (is_null_product = 1) from quantity validation
            // $categoriesInOrderWithoutNullProducts = $categoriesInOrder->filter(function ($item) {
            //     return !$item['is_null_product'];
            // });

            $quantitiesByCategory = $categoriesInOrder->groupBy('category.id')->map(function ($items) {
                return $items->sum('quantity');
            });

            $uniqueQuantities = $quantitiesByCategory->unique();

            if ($uniqueQuantities->count() > 1) {
                \Log::debug('AtLeastOneProductByCategory: Quantity validation failed', [
                    'quantities_by_category' => $quantitiesByCategory->toArray()
                ]);
                throw new Exception("Cada categoría debe tener la misma cantidad de productos.");
            }

            \Log::debug('AtLeastOneProductByCategory: Validation passed successfully');
        }

        // Individual agreement validation remains the same...
        if (UserPermissions::IsAgreementIndividual($user)) {
            $quantities = $order->orderLines->pluck('quantity')->unique();
            if ($quantities->count() > 1) {
                throw new Exception("Todos los productos en la orden deben tener la misma cantidad.");
            }
        }
    }


    // NEW: Add helper method to check if categories have subcategories
    protected function hasSubcategoriesInMenu($categoryMenus): bool
    {
        $hasSubcategories = $categoryMenus->contains(function ($categoryMenu) {
            return $categoryMenu->category->subcategories->isNotEmpty();
        });

        \Log::debug('AtLeastOneProductByCategory: Checking for subcategories in menu', [
            'has_subcategories' => $hasSubcategories,
            'categories_checked' => $categoryMenus->count()
        ]);

        return $hasSubcategories;
    }

    // The existing validation methods remain the same but with improved logging
    protected function validateConsolidatedWithSubcategories($categoryMenus, $categoriesInOrder): void
    {
        \Log::debug('AtLeastOneProductByCategory: Starting subcategory validation');

        // Only get subcategories from categories that HAVE subcategories
        $subcategoriesInMenu = $categoryMenus
            ->filter(function ($categoryMenu) {
                return $categoryMenu->category->subcategories->isNotEmpty();
            })
            ->flatMap(function ($categoryMenu) {
                return $categoryMenu->category->subcategories->pluck('name')->toArray();
            })
            ->unique();

        $subcategoriesInOrder = $categoriesInOrder
            ->flatMap(function ($item) {
                return $item['category']->subcategories->pluck('name')->toArray();
            })
            ->unique();

        \Log::debug('AtLeastOneProductByCategory: Subcategory analysis', [
            'subcategories_in_menu' => $subcategoriesInMenu->toArray(),
            'subcategories_in_order' => $subcategoriesInOrder->toArray()
        ]);

        $requiredSubcategories = [
            \App\Enums\Subcategory::PLATO_DE_FONDO,
            \App\Enums\Subcategory::ENTRADA,
            \App\Enums\Subcategory::PAN_DE_ACOMPANAMIENTO
        ];

        $missingSubcategories = [];

        // For each required subcategory, check if it exists in menu AND if so, does order have it
        // This implements GROUPING: all categories with PLATO_DE_FONDO are treated as ONE group
        foreach ($requiredSubcategories as $requiredSubcategory) {
            $subcategoryValue = $requiredSubcategory->value;

            if ($subcategoriesInMenu->contains($subcategoryValue) && !$subcategoriesInOrder->contains($subcategoryValue)) {
                $missingSubcategories[] = $subcategoryValue;
            }
        }

        \Log::debug('AtLeastOneProductByCategory: Missing subcategories check', [
            'missing_subcategories' => $missingSubcategories
        ]);

        if (!empty($missingSubcategories)) {
            $message = $this->generateMissingItemsMessage($missingSubcategories);
            \Log::error('AtLeastOneProductByCategory: Subcategory validation failed', [
                'message' => $message
            ]);
            throw new Exception($message);
        }
    }

    protected function validateConsolidatedWithoutSubcategories($categoryMenus, $categoriesInOrder): void
    {
        \Log::debug('AtLeastOneProductByCategory: Starting standard category validation');

        $missingCategories = [];

        foreach ($categoryMenus as $categoryMenu) {
            $category = $categoryMenu->category;
            $hasProductInCategory = $categoriesInOrder->contains('category.id', $category->id);

            \Log::debug('AtLeastOneProductByCategory: Checking category', [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'has_product_in_category' => $hasProductInCategory
            ]);

            if (!$hasProductInCategory) {
                $missingCategories[] = $category->name;
                \Log::debug('AtLeastOneProductByCategory: Missing category detected', [
                    'category_name' => $category->name
                ]);
            }
        }

        \Log::debug('AtLeastOneProductByCategory: Missing categories summary', [
            'missing_categories' => $missingCategories
        ]);

        if (!empty($missingCategories)) {
            $message = $this->generateMissingItemsMessage($missingCategories);
            \Log::error('AtLeastOneProductByCategory: Category validation failed', [
                'message' => $message
            ]);
            throw new Exception($message);
        }
    }

    // Keep the existing helper method
    private function generateMissingItemsMessage(array $missingItems): string
    {
        $message = "🍽️ Tu menú necesita algunos elementos para estar completo: ";

        $formattedItems = [];
        foreach ($missingItems as $item) {
            $formattedItems[] = ucfirst(mb_strtolower($item, 'UTF-8'));
        }

        $message .= implode(', ', $formattedItems) . ".";

        return $message;
    }

    // NEW: Add method to validate categories without subcategories (when subcategory rules are enabled)
    protected function validateCategoriesWithoutSubcategories($categoryMenus, $categoriesInOrder): void
    {
        \Log::debug('AtLeastOneProductByCategory: Starting validation of categories without subcategories');

        $missingCategories = [];

        // Get categories that don't have subcategories
        $categoriesWithoutSubcategories = $categoryMenus->filter(function ($categoryMenu) {
            return $categoryMenu->category->subcategories->isEmpty();
        });

        \Log::debug('AtLeastOneProductByCategory: Categories without subcategories', [
            'count' => $categoriesWithoutSubcategories->count(),
            'category_names' => $categoriesWithoutSubcategories->pluck('category.name')->toArray()
        ]);

        foreach ($categoriesWithoutSubcategories as $categoryMenu) {
            $category = $categoryMenu->category;
            $hasProductInCategory = $categoriesInOrder->contains('category.id', $category->id);

            \Log::debug('AtLeastOneProductByCategory: Checking category without subcategories', [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'has_product_in_category' => $hasProductInCategory
            ]);

            if (!$hasProductInCategory) {
                $missingCategories[] = $category->name;
                \Log::debug('AtLeastOneProductByCategory: Missing category without subcategories', [
                    'category_name' => $category->name
                ]);
            }
        }

        \Log::debug('AtLeastOneProductByCategory: Missing categories without subcategories summary', [
            'missing_categories' => $missingCategories
        ]);

        if (!empty($missingCategories)) {
            $message = $this->generateMissingItemsMessage($missingCategories);
            \Log::error('AtLeastOneProductByCategory: Categories without subcategories validation failed', [
                'message' => $message
            ]);
            throw new Exception($message);
        }
    }
}
