<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use App\Classes\Menus\MenuHelper;
use App\Repositories\CategoryMenuRepository;
use Carbon\Carbon;
use Exception;

/**
 * Simple validation to ensure only one product per category in orders.
 * 
 * This validation checks that each category in the order has exactly one product.
 * It applies to individual agreement users (IsAgreementIndividual = true).
 * 
 * Unlike MenuCompositionValidation, this class focuses solely on the basic
 * one-product-per-category rule without complex subcategory logic.
 */
class OneProductPerCategorySimple extends OrderStatusValidation
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

            // Validate one product per category ONLY for categories without subcategories
            foreach ($categoryMenus as $categoryMenu) {
                $category = $categoryMenu->category;

                // Only validate categories that DON'T have subcategories
                if (!$category->hasSubcategories()) {
                    $this->validateOneProductPerCategory($category, $groupedByCategory);
                }
            }
        }
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
            $productNames = $productsInCategory->pluck('product_name')->toArray();
            throw new Exception(
                $this->generateTooManyProductsMessage($category->name, $productNames)
            );
        }
    }

    /**
     * Generate user-friendly message for too many products in category
     */
    private function generateTooManyProductsMessage(string $categoryName, array $productNames): string
    {
        $products = implode(', ', $productNames);
        
        return "🚫 Solo puedes elegir un producto de {$categoryName}.\n\n";
    }
}