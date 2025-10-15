<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use App\Classes\Menus\MenuHelper;
use App\Repositories\CategoryMenuRepository;
use App\Repositories\OrderRuleRepository;
use Carbon\Carbon;
use Exception;

/**
 * Validates that orders comply with subcategory product limit rules.
 *
 * This validation ensures that orders comply with database-driven subcategory limit rules:
 * - Fetches limit rules from OrderRuleRepository based on user's role, permission, and company
 * - Company-specific rules override general rules (lower priority number = higher priority)
 * - Only applies to categories that have subcategories
 *
 * Only applies to individual agreement users (IsAgreementIndividual = true)
 * when validate_subcategory_rules is enabled.
 */
class OneProductPerSubcategory extends OrderStatusValidation
{
    protected OrderRuleRepository $orderRuleRepository;

    public function __construct()
    {
        $this->orderRuleRepository = new OrderRuleRepository();
    }

    protected function check(Order $order, User $user, Carbon $date): void
    {
        if (UserPermissions::IsAgreementIndividual($user) && $user->validate_subcategory_rules) {
            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                throw new Exception("No se encontró un menú activo para la fecha");
            }

            // Get subcategory limits from database (company-specific or general)
            $subcategoryLimits = $this->orderRuleRepository->getSubcategoryLimitsForUser($user);

            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category' => $orderLine->product->category,
                    'product_name' => $orderLine->product->name,
                ];
            });

            $subcategoriesInOrder = $this->buildSubcategoriesInOrder($categoriesInOrder);

            $this->validateOneProductPerSubcategory($subcategoriesInOrder, $subcategoryLimits);
        }
    }

    /**
     * Validate that subcategories in order respect maximum product limits from database rules.
     *
     * @param \Illuminate\Support\Collection $subcategoriesInOrder Grouped by subcategory name
     * @param \Illuminate\Support\Collection $subcategoryLimits Format: ['subcategory_name' => max_products]
     * @throws Exception
     */
    protected function validateOneProductPerSubcategory($subcategoriesInOrder, $subcategoryLimits): void
    {
        foreach ($subcategoriesInOrder as $subcategoryName => $products) {
            // Get limit from database rules, default to 1 if not found
            $maxProducts = $subcategoryLimits->get($subcategoryName, 1);

            if ($products->count() > $maxProducts) {
                $productNames = $products->pluck('product_name')->toArray();
                throw new Exception(
                    $this->generateTooManySubcategoryMessage($subcategoryName, $productNames, $maxProducts)
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
     *
     * @param string $subcategoryName
     * @param array $productNames
     * @param int $maxProducts Maximum allowed products for this subcategory
     * @return string
     */
    private function generateTooManySubcategoryMessage(string $subcategoryName, array $productNames, int $maxProducts): string
    {
        if ($maxProducts === 1) {
            return "Solo puedes elegir un {$subcategoryName} por pedido.\n\n";
        }

        return "Solo puedes elegir un máximo de {$maxProducts} productos de {$subcategoryName} por pedido.\n\n";
    }
}