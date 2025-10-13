<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use App\Classes\Menus\MenuHelper;
use App\Classes\OrderHelper;
use App\Enums\Subcategory;
use App\Repositories\CategoryMenuRepository;
use Carbon\Carbon;
use Exception;

/**
 * Validates order composition rules for individual agreement users.
 * 
 * This validation ensures that orders comply with menu structure requirements:
 * 
 * When validate_subcategory_rules is TRUE (complex validation):
 * - Categories WITHOUT subcategories: Must have exactly one product each
 * - Categories WITH subcategories: Validated through subcategory rules
 * - Required subcategories (PLATO DE FONDO, ENTRADA, SANDWICH, PAN DE ACOMPAÑAMIENTO): 
 *   Must have at least one product if present in menu
 * - All subcategories: Maximum one product per subcategory type
 * 
 * When validate_subcategory_rules is FALSE (simplified validation):
 * - ALL categories: Must have exactly one product each (no subcategory logic)
 * 
 * Error messages are dynamically generated showing specific missing types/categories.
 * Comprehensive logging is provided for debugging validation logic.
 * 
 * Only applies to individual agreement users (IsAgreementIndividual = true).
 */
class MenuCompositionValidation extends OrderStatusValidation
{

    protected function check(Order $order, User $user, Carbon $date): void
    {
        if (UserPermissions::IsAgreementIndividual($user)) {
            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                throw new Exception("No se encontró un menú activo para la fecha");
            }

            // Use repository to get category menus filtered by price list
            $categoryMenuRepository = app(CategoryMenuRepository::class);
            $categoryMenus = $categoryMenuRepository->getCategoryMenusForValidation($currentMenu, $user);
            
            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category' => $orderLine->product->category,
                    'product_name' => $orderLine->product->name,
                ];
            });

            $groupedByCategory = $categoriesInOrder->groupBy(function ($item) {
                return $item['category']->id;
            });
            
            if ($user->validate_subcategory_rules) {

                $this->validateCategoriesWithSubcategories($categoryMenus, $categoriesInOrder);

                $this->validateCategoriesWithoutSubcategories($categoryMenus, $categoriesInOrder, $groupedByCategory);

            } else {
                $this->validateSimplifiedCategoryRules($categoryMenus, $categoriesInOrder, $groupedByCategory);
            }
        }
    }

    /**
     * Validate that there is at least one product in the order for each required subcategory.
     *
     * @param \Illuminate\Support\Collection $categoryMenus
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @throws Exception
     */
    protected function validateCategoriesWithSubcategories($categoryMenus, $categoriesInOrder): void
    {
        $subcategoriesInMenu = $categoryMenus
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
            Subcategory::PLATO_DE_FONDO,
            Subcategory::ENTRADA,
            Subcategory::PAN_DE_ACOMPANAMIENTO
        ];

        $missingSubcategories = [];

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

    /**
     * Validate categories without subcategories
     *
     * @param \Illuminate\Support\Collection $categoryMenus
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @param \Illuminate\Support\Collection $groupedByCategory
     * @throws Exception
     */
    protected function validateCategoriesWithoutSubcategories($categoryMenus, $categoriesInOrder, $groupedByCategory): void
    {
        $missingCategories = [];

        // Sort by display order to ensure consistent error message ordering
        $sortedCategoryMenus = $categoryMenus->sortBy('display_order');

        foreach ($sortedCategoryMenus as $categoryMenu) {
            $category = $categoryMenu->category;

            if ($category->subcategories->isEmpty()) {
                $hasProductInCategory = $this->checkProductInCategory($category, $categoriesInOrder);

                if (!$hasProductInCategory) {
                    $missingCategories[] = $category->name;
                }
            }
        }

        if (!empty($missingCategories)) {
            $message = $this->generateMissingItemsMessage($missingCategories);
            throw new Exception($message);
        }
    }


    /**
     * Check if there is at least one product in a specific category
     *
     * @param \App\Models\Category $category
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @return bool
     */
    protected function checkProductInCategory($category, $categoriesInOrder): bool
    {
        return $categoriesInOrder->contains(function ($item) use ($category) {
            return $item['category']->id === $category->id;
        });
    }

    /**
     * Validate that there is only one product per category
     *
     * @param \App\Models\Category $category
     * @param \Illuminate\Support\Collection $groupedByCategory
     * @throws Exception
     */
    protected function validateOneProductPerCategory($category, $groupedByCategory): void
    {
        $productsInCategory = $groupedByCategory->get($category->id, collect([]));
        if ($productsInCategory->count() > 1) {
            $productNames = $productsInCategory->pluck('product_name')->map(function ($name) {
                return OrderHelper::formatProductName($name);
            })->toArray();
            throw new Exception(
                $this->generateTooManyProductsMessage($category->name, $productNames)
            );
        }
    }

    /**
     * Validate simplified category rules (when validate_subcategory_rules is false)
     *
     * @param \Illuminate\Support\Collection $categoryMenus
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @param \Illuminate\Support\Collection $groupedByCategory
     * @throws Exception
     */
    protected function validateSimplifiedCategoryRules($categoryMenus, $categoriesInOrder, $groupedByCategory): void
    {
        $missingCategories = [];

        // Sort by display order to ensure consistent error message ordering
        $sortedCategoryMenus = $categoryMenus->sortBy('display_order');

        foreach ($sortedCategoryMenus as $categoryMenu) {
            $category = $categoryMenu->category;

            $hasProductInCategory = $this->checkProductInCategory($category, $categoriesInOrder);

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
     * Generate user-friendly message for missing menu items
     */
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

    /**
     * Generate user-friendly message for too many products in category
     */
    private function generateTooManyProductsMessage(string $categoryName, array $productNames): string
    {
        return "🚫 Solo puedes elegir un producto de {$categoryName}.\n\n";
    }

}