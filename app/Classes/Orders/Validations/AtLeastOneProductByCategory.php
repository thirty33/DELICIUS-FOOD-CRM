<?php

namespace App\Classes\Orders\Validations;

use App\Classes\Menus\MenuHelper;
use App\Classes\UserPermissions;
use App\Models\Category;
use App\Models\Order;
use App\Models\Subcategory;
use App\Models\User;
use App\Repositories\CategoryMenuRepository;
use App\Repositories\OrderRuleRepository;
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
                $this->validateConsolidatedWithSubcategories($categoryMenus, $categoriesInOrder, $user);

                // 2. ALSO validate categories without subcategories
                $this->validateCategoriesWithoutSubcategories($categoryMenus, $categoriesInOrder, $order, $user);
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

    protected function validateConsolidatedWithSubcategories($categoryMenus, $categoriesInOrder, User $user): void
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

        // NEW: Filter out subcategories that are blocked by exclusion rules
        $filteredMissingSubcategories = $this->filterBlockedSubcategories(
            $missingSubcategories,
            $categoriesInOrder,
            $user
        );

        if (!empty($filteredMissingSubcategories)) {
            $message = $this->generateMissingItemsMessage($filteredMissingSubcategories);
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
    protected function validateCategoriesWithoutSubcategories($categoryMenus, $categoriesInOrder, Order $order, User $user): void
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
                // Check if this category is blocked by an exclusion rule
                if (!$this->isCategoryBlockedByExclusion($category, $categoriesInOrder, $user)) {
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

    /**
     * Check if a category is blocked by an exclusion rule.
     *
     * A category is considered "blocked" if there's an exclusion rule where:
     * - The category appears in either source or excluded side of the rule
     * - The "other side" of the rule matches a product already in the order
     *
     * Logic:
     * 1. Find all exclusion rules where this category appears (source or excluded)
     * 2. For each rule, check if the "other side" is present in the order
     * 3. If the "other side" is present → category is blocked (rule is active)
     *
     * @param Category $category The category to check
     * @param \Illuminate\Support\Collection $categoriesInOrder Products currently in the order
     * @param User $user The user to get exclusion rules for
     * @return bool True if category is blocked by an active exclusion rule
     */
    protected function isCategoryBlockedByExclusion(Category $category, $categoriesInOrder, User $user): bool
    {
        // Get exclusion rules for this user
        $orderRuleRepository = app(OrderRuleRepository::class);
        $orderRule = $orderRuleRepository->getOrderRuleForUser($user, 'subcategory_exclusion');

        if (!$orderRule) {
            return false; // No rules, not blocked
        }

        // Get all polymorphic exclusions
        $exclusions = $orderRule->exclusions()
            ->with(['source', 'excluded'])
            ->get();

        // Check each exclusion rule
        foreach ($exclusions as $exclusion) {
            $categoryIsInSource = ($exclusion->source_type === Category::class && $exclusion->source_id === $category->id);
            $categoryIsInExcluded = ($exclusion->excluded_type === Category::class && $exclusion->excluded_id === $category->id);

            // If category is not involved in this rule, skip
            if (!$categoryIsInSource && !$categoryIsInExcluded) {
                continue;
            }

            // Category is involved, check the "other side"
            if ($categoryIsInSource) {
                // Category is in source, check if excluded side is in order
                if ($this->isExclusionSideInOrder($exclusion->excluded_type, $exclusion->excluded_id, $categoriesInOrder)) {
                    return true; // Other side is present, category is blocked
                }
            }

            if ($categoryIsInExcluded) {
                // Category is in excluded, check if source side is in order
                if ($this->isExclusionSideInOrder($exclusion->source_type, $exclusion->source_id, $categoriesInOrder)) {
                    return true; // Other side is present, category is blocked
                }
            }
        }

        return false; // No active rule blocks this category
    }

    /**
     * Check if a specific side of an exclusion rule (source or excluded) is present in the order.
     *
     * @param string $type The type (Category::class or Subcategory::class)
     * @param int $id The ID of the category/subcategory
     * @param \Illuminate\Support\Collection $categoriesInOrder Products currently in the order
     * @return bool True if this side is present in the order
     */
    protected function isExclusionSideInOrder(string $type, int $id, $categoriesInOrder): bool
    {
        if ($type === Category::class) {
            // Check if any product in order has this category
            return $categoriesInOrder->contains(function ($item) use ($id) {
                return $item['category']->id === $id;
            });
        }

        if ($type === Subcategory::class) {
            // Check if any product in order has this subcategory
            $subcategory = Subcategory::find($id);
            if (!$subcategory) {
                return false;
            }

            return $categoriesInOrder->contains(function ($item) use ($subcategory) {
                return $item['category']->subcategories->pluck('name')->contains($subcategory->name);
            });
        }

        return false;
    }

    /**
     * Filter out subcategories that are blocked by exclusion rules.
     *
     * A subcategory is considered "blocked" if there's an exclusion rule where:
     * - The subcategory appears in either source or excluded side of the rule
     * - The "other side" of the rule matches a product already in the order
     *
     * @param array $missingSubcategories Array of subcategory names that are missing
     * @param \Illuminate\Support\Collection $categoriesInOrder Products currently in the order
     * @param User $user The user to get exclusion rules for
     * @return array Filtered array of subcategories that are truly required (not blocked)
     */
    protected function filterBlockedSubcategories(array $missingSubcategories, $categoriesInOrder, User $user): array
    {
        $filtered = [];

        foreach ($missingSubcategories as $subcategoryName) {
            // If NOT blocked by exclusion, add it to the filtered list
            if (!$this->isSubcategoryBlockedByExclusion($subcategoryName, $categoriesInOrder, $user)) {
                $filtered[] = $subcategoryName;
            }
        }

        return $filtered;
    }

    /**
     * Check if a subcategory is blocked by an exclusion rule.
     *
     * Similar to isCategoryBlockedByExclusion but works with subcategory names.
     *
     * @param string $subcategoryName The subcategory name to check
     * @param \Illuminate\Support\Collection $categoriesInOrder Products currently in the order
     * @param User $user The user to get exclusion rules for
     * @return bool True if subcategory is blocked by an active exclusion rule
     */
    protected function isSubcategoryBlockedByExclusion(string $subcategoryName, $categoriesInOrder, User $user): bool
    {
        // Get exclusion rules for this user
        $orderRuleRepository = app(OrderRuleRepository::class);
        $orderRule = $orderRuleRepository->getOrderRuleForUser($user, 'subcategory_exclusion');

        if (!$orderRule) {
            return false; // No rules, not blocked
        }

        // Find the subcategory by name
        $subcategory = Subcategory::where('name', $subcategoryName)->first();
        if (!$subcategory) {
            return false; // Subcategory doesn't exist, not blocked
        }

        // Get all polymorphic exclusions
        $exclusions = $orderRule->exclusions()
            ->with(['source', 'excluded'])
            ->get();

        // Check each exclusion rule
        foreach ($exclusions as $exclusion) {
            $subcategoryIsInSource = ($exclusion->source_type === Subcategory::class && $exclusion->source_id === $subcategory->id);
            $subcategoryIsInExcluded = ($exclusion->excluded_type === Subcategory::class && $exclusion->excluded_id === $subcategory->id);

            // If subcategory is not involved in this rule, skip
            if (!$subcategoryIsInSource && !$subcategoryIsInExcluded) {
                continue;
            }

            // Subcategory is involved, check the "other side"
            if ($subcategoryIsInSource) {
                // Subcategory is in source, check if excluded side is in order
                if ($this->isExclusionSideInOrder($exclusion->excluded_type, $exclusion->excluded_id, $categoriesInOrder)) {
                    return true; // Other side is present, subcategory is blocked
                }
            }

            if ($subcategoryIsInExcluded) {
                // Subcategory is in excluded, check if source side is in order
                if ($this->isExclusionSideInOrder($exclusion->source_type, $exclusion->source_id, $categoriesInOrder)) {
                    return true; // Other side is present, subcategory is blocked
                }
            }
        }

        return false; // No active rule blocks this subcategory
    }
}
