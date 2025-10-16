<?php

namespace App\Classes\Orders\Validations;

use App\Classes\Menus\MenuHelper;
use App\Classes\UserPermissions;
use App\Models\Order;
use App\Models\User;
use App\Repositories\CategoryMenuRepository;
use Carbon\Carbon;
use Exception;

class AtLeastOneProductByCategory extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {
        if (UserPermissions::IsAgreementConsolidated($user)) {
            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                throw new Exception("No se encontró un menú activo para la fecha");
            }

            // Use repository to get category menus filtered by price list
            $categoryMenuRepository = app(CategoryMenuRepository::class);
            $categoryMenus = $categoryMenuRepository->getCategoryMenusForValidation($currentMenu, $user);

            // Obtener las categorías de los productos en la orden
            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category' => $orderLine->product->category,
                    'quantity' => $orderLine->quantity,
                    'product_name' => $orderLine->product->name
                    // 'is_null_product' => $orderLine->product->is_null_product // TODO: Add for null product filtering
                ];
            });

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

            // Quantity validation: group differently based on subcategory rules
            if ($user->validate_subcategory_rules && $this->hasSubcategoriesInMenu($categoryMenus)) {
                // Group by subcategory for special subcategories, by category for others
                $quantitiesByGroup = $this->groupQuantitiesForConsolidatedWithSubcategories($categoriesInOrder);
            } else {
                // Standard: group by category
                $quantitiesByGroup = $categoriesInOrder->groupBy('category.id')->map(function ($items) {
                    return $items->sum('quantity');
                });
            }

            $uniqueQuantities = $quantitiesByGroup->unique();

            if ($uniqueQuantities->count() > 1) {
                throw new Exception("Cada categoría debe tener la misma cantidad de productos.");
            }
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
        return $categoryMenus->contains(function ($categoryMenu) {
            return $categoryMenu->category->subcategories->isNotEmpty();
        });
    }

    protected function validateConsolidatedWithSubcategories($categoryMenus, $categoriesInOrder): void
    {
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

        if (!empty($missingSubcategories)) {
            $message = $this->generateMissingItemsMessage($missingSubcategories);
            throw new Exception($message);
        }
    }

    protected function validateConsolidatedWithoutSubcategories($categoryMenus, $categoriesInOrder): void
    {
        $missingCategories = [];

        foreach ($categoryMenus as $categoryMenu) {
            $category = $categoryMenu->category;
            $hasProductInCategory = $categoriesInOrder->contains('category.id', $category->id);

            if (!$hasProductInCategory) {
                $missingCategories[] = $category->name;
            }
        }

        if (!empty($missingCategories)) {
            $message = $this->generateMissingItemsMessage($missingCategories);
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
        $missingCategories = [];

        // Get categories that don't have subcategories
        $categoriesWithoutSubcategories = $categoryMenus->filter(function ($categoryMenu) {
            return $categoryMenu->category->subcategories->isEmpty();
        });

        foreach ($categoriesWithoutSubcategories as $categoryMenu) {
            $category = $categoryMenu->category;
            $hasProductInCategory = $categoriesInOrder->contains('category.id', $category->id);

            if (!$hasProductInCategory) {
                $missingCategories[] = $category->name;
            }
        }

        if (!empty($missingCategories)) {
            $message = $this->generateMissingItemsMessage($missingCategories);
            throw new Exception($message);
        }
    }

    /**
     * Group quantities for consolidated orders with subcategories.
     *
     * Grouping rules:
     * 1. Special subcategories (PLATO_DE_FONDO, ENTRADA, PAN_DE_ACOMPAÑAMIENTO):
     *    Group ALL categories with these subcategories together by subcategory
     * 2. Categories without subcategories:
     *    Each category is a separate group
     *
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @return \Illuminate\Support\Collection
     */
    protected function groupQuantitiesForConsolidatedWithSubcategories($categoriesInOrder)
    {
        $requiredSubcategories = [
            \App\Enums\Subcategory::PLATO_DE_FONDO,
            \App\Enums\Subcategory::ENTRADA,
            \App\Enums\Subcategory::PAN_DE_ACOMPANAMIENTO
        ];

        $quantities = collect();

        // Group 1: Products with special subcategories (grouped by subcategory)
        foreach ($requiredSubcategories as $requiredSubcategory) {
            $subcategoryValue = $requiredSubcategory->value;

            $totalForSubcategory = $categoriesInOrder->filter(function ($item) use ($subcategoryValue) {
                return $item['category']->subcategories->pluck('name')->contains($subcategoryValue);
            })->sum('quantity');

            if ($totalForSubcategory > 0) {
                $quantities->put("subcat_{$subcategoryValue}", $totalForSubcategory);
            }
        }

        // Group 2: Products without subcategories (each category separate)
        $categoriesWithoutSubcategories = $categoriesInOrder->filter(function ($item) {
            return $item['category']->subcategories->isEmpty();
        });

        $quantitiesByCategory = $categoriesWithoutSubcategories->groupBy('category.id')->map(function ($items) {
            return $items->sum('quantity');
        });

        foreach ($quantitiesByCategory as $categoryId => $quantity) {
            $quantities->put("cat_{$categoryId}", $quantity);
        }

        return $quantities;
    }
}
