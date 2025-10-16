<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use App\Classes\Menus\MenuHelper;
use App\Repositories\OrderRuleRepository;
use App\Repositories\CategoryMenuRepository;
use Carbon\Carbon;
use Exception;

/**
 * Validates that orders have EXACT product counts per subcategory when updating order status.
 *
 * This validation is the inverse of OneProductPerSubcategory:
 * - OneProductPerSubcategory: Validates during order creation/update - "you cannot ADD more than X products"
 * - ExactProductCountPerSubcategory: Validates during status update - "the order MUST HAVE exactly X products"
 *
 * Behavior:
 * - Fetches limit rules from OrderRuleRepository (same rules as OneProductPerSubcategory)
 * - Interprets max_products as MINIMUM REQUIRED count when updating order status
 * - Company-specific rules override general rules (lower priority number = higher priority)
 * - ONLY validates subcategories that exist in the current menu with products that have prices
 * - Subcategories not available in the menu are ignored
 *
 * Example:
 * - Rule says: "ENTRADA: max_products = 2"
 * - Menu has ENTRADA products with prices
 * - Validation checks: "Order MUST have exactly 2 products with ENTRADA subcategory"
 * - 0, 1, or 3 ENTRADA products → Error 422
 * - Exactly 2 ENTRADA products → Pass ✓
 *
 * If menu doesn't have ENTRADA products with prices, validation is skipped for ENTRADA.
 *
 * Only applies to individual agreement users (IsAgreementIndividual = true)
 * when validate_subcategory_rules is enabled.
 */
class ExactProductCountPerSubcategory extends OrderStatusValidation
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
            // These limits are interpreted as REQUIRED counts in this validation
            $subcategoryLimits = $this->orderRuleRepository->getSubcategoryLimitsForUser($user);

            // Get subcategories available in menu with products that have prices
            $categoryMenuRepository = app(CategoryMenuRepository::class);
            $categoryMenus = $categoryMenuRepository->getCategoryMenusForValidation($currentMenu, $user);
            $availableSubcategories = $this->getAvailableSubcategoriesInMenu($categoryMenus);

            // Filter limits to only include subcategories that are available in the menu
            $applicableLimits = $subcategoryLimits->filter(function ($limit, $subcategoryName) use ($availableSubcategories) {
                return $availableSubcategories->contains($subcategoryName);
            });

            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category' => $orderLine->product->category,
                    'product_name' => $orderLine->product->name,
                ];
            });

            $subcategoriesInOrder = $this->buildSubcategoriesInOrder($categoriesInOrder);

            $this->validateExactProductCountPerSubcategory($subcategoriesInOrder, $applicableLimits);
        }
    }

    /**
     * Validate that subcategories in order have EXACT product counts as defined in database rules.
     *
     * @param \Illuminate\Support\Collection $subcategoriesInOrder Grouped by subcategory name
     * @param \Illuminate\Support\Collection $subcategoryLimits Format: ['subcategory_name' => required_count]
     * @throws Exception
     */
    protected function validateExactProductCountPerSubcategory($subcategoriesInOrder, $subcategoryLimits): void
    {
        // For each subcategory that has a rule defined, validate exact count
        foreach ($subcategoryLimits as $subcategoryName => $requiredCount) {
            $actualCount = 0;

            if ($subcategoriesInOrder->has($subcategoryName)) {
                $actualCount = $subcategoriesInOrder->get($subcategoryName)->count();
            }

            if ($actualCount !== $requiredCount) {
                throw new Exception(
                    $this->generateIncorrectCountMessage($subcategoryName, $requiredCount, $actualCount)
                );
            }
        }
    }

    /**
     * Get all subcategories available in the menu (categories with products that have prices)
     *
     * @param \Illuminate\Support\Collection $categoryMenus
     * @return \Illuminate\Support\Collection
     */
    protected function getAvailableSubcategoriesInMenu($categoryMenus)
    {
        return $categoryMenus
            ->flatMap(function ($categoryMenu) {
                return $categoryMenu->category->subcategories->pluck('name');
            })
            ->unique()
            ->values();
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
     * Generate user-friendly message for incorrect product count in subcategory
     *
     * @param string $subcategoryName
     * @param int $requiredCount Required number of products for this subcategory
     * @param int $actualCount Actual number of products in the order
     * @return string
     */
    private function generateIncorrectCountMessage(string $subcategoryName, int $requiredCount, int $actualCount): string
    {
        if ($requiredCount === 1) {
            if ($actualCount === 0) {
                return "Tu pedido debe incluir 1 producto de tipo {$subcategoryName}.\n\n";
            }
            return "Tu pedido debe incluir exactamente 1 producto de tipo {$subcategoryName}, pero tiene {$actualCount}.\n\n";
        }

        if ($actualCount === 0) {
            return "Tu pedido debe incluir {$requiredCount} productos de tipo {$subcategoryName}.\n\n";
        }

        return "Tu pedido debe incluir exactamente {$requiredCount} productos de tipo {$subcategoryName}, pero tiene {$actualCount}.\n\n";
    }
}
