<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use App\Classes\Menus\MenuHelper;
use Carbon\Carbon;
use Exception;

/**
 * Validates that there is no more than one product per subcategory in orders.
 * 
 * This validation ensures that orders comply with subcategory structure requirements:
 * - Maximum one product per subcategory type
 * - Only applies to categories that have subcategories
 * 
 * Only applies to individual agreement users (IsAgreementIndividual = true)
 * when validate_subcategory_rules is enabled.
 */
class OneProductPerSubcategory extends OrderStatusValidation
{
    protected function check(Order $order, User $user, Carbon $date): void
    {
        if (UserPermissions::IsAgreementIndividual($user) && $user->validate_subcategory_rules) {
            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                throw new Exception("No se encontró un menú activo para la fecha");
            }

            $categoryMenus = $currentMenu->categoryMenus()
                ->where('is_active', true)
                ->whereHas('category.products.priceListLines', function ($query) use ($user) {
                    $query->where('active', true)
                        ->whereHas('priceList', function ($priceListQuery) use ($user) {
                            $priceListQuery->where('id', $user->company->price_list_id);
                        });
                })
                ->orderedByDisplayOrder()
                ->get();

            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category' => $orderLine->product->category,
                    'product_name' => $orderLine->product->name,
                ];
            });

            $subcategoriesInOrder = $this->buildSubcategoriesInOrder($categoriesInOrder);

            $this->validateOneProductPerSubcategory($subcategoriesInOrder);
        }
    }

    /**
     * Validate that there is no more than one product per subcategory
     *
     * @param \Illuminate\Support\Collection $subcategoriesInOrder
     * @throws Exception
     */
    protected function validateOneProductPerSubcategory($subcategoriesInOrder): void
    {
        foreach ($subcategoriesInOrder as $subcategoryName => $products) {
            if ($products->count() > 1) {
                $productNames = $products->pluck('product_name')->toArray();
                throw new Exception(
                    $this->generateTooManySubcategoryMessage($subcategoryName, $productNames)
                );
            }
        }
    }

    /**
     * Build subcategories in order collection
     *
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @return \Illuminate\Support\Collection
     */
    protected function buildSubcategoriesInOrder($categoriesInOrder)
    {
        return $categoriesInOrder
            ->filter(function ($item) {
                return !$item['category']->subcategories->isEmpty();
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
            ->groupBy('subcategory_name');
    }

    /**
     * Generate user-friendly message for too many products in subcategory
     */
    private function generateTooManySubcategoryMessage(string $subcategoryName, array $productNames): string
    {
        return "Solo puedes elegir un {$subcategoryName} por pedido.\n\n";
    }
}