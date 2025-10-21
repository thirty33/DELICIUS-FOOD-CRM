<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use App\Models\Category;
use App\Models\Subcategory;
use App\Repositories\OrderRuleRepository;
use Carbon\Carbon;
use Exception;

/**
 * Validates polymorphic exclusion rules for Consolidated agreements.
 *
 * This validator handles ALL types of polymorphic exclusions:
 * - Subcategory → Subcategory (e.g., ENTRADA → ENTRADA)
 * - Subcategory → Category (e.g., ENTRADA → POSTRES)
 * - Category → Subcategory (e.g., POSTRES → ENTRADA)
 * - Category → Category (e.g., ENSALADAS → POSTRES)
 *
 * CRITICAL DIFFERENCE from SubcategoryExclusion:
 * - SubcategoryExclusion: Only for Individual agreements, only Subcategory → Subcategory
 * - PolymorphicExclusion: Only for Consolidated agreements, supports ALL polymorphic combinations
 *
 * Rules are loaded dynamically from the database via OrderRuleRepository:
 * - Fetches OrderRuleExclusion records (polymorphic table)
 * - Based on user's role, permission, and company
 * - NO FILTERING - retrieves ALL polymorphic exclusions
 * - Company-specific rules take precedence over general rules
 *
 * Only applies when validate_subcategory_rules is TRUE and for consolidated agreement users.
 */
class PolymorphicExclusion extends OrderStatusValidation
{
    protected OrderRuleRepository $orderRuleRepository;
    protected array $polymorphicExclusions = [];

    public function __construct()
    {
        $this->orderRuleRepository = new OrderRuleRepository();
    }

    protected function check(Order $order, User $user, Carbon $date): void
    {
        // Only validate for Consolidated agreement users with validation enabled
        if (!$user->validate_subcategory_rules) {
            return;
        }

        if (!UserPermissions::IsAgreementConsolidated($user)) {
            return;
        }

        // Load polymorphic exclusion rules from database
        $this->loadPolymorphicExclusionsFromDatabase($user);

        // If no exclusions configured, skip validation
        if (empty($this->polymorphicExclusions)) {
            return;
        }

        // Get all products with their categories and subcategories
        $productsData = $order->orderLines->map(function ($orderLine) {
            return [
                'product_id' => $orderLine->product->id,
                'product_name' => $orderLine->product->name,
                'category' => $orderLine->product->category,
                'category_id' => $orderLine->product->category->id,
                'category_name' => $orderLine->product->category->name,
                'subcategories' => $orderLine->product->category->subcategories->pluck('name')->toArray(),
                'is_null_product' => $orderLine->product->is_null_product,
            ];
        });

        // Validate polymorphic exclusions
        $this->validatePolymorphicExclusions($productsData);
    }

    /**
     * Load ALL polymorphic exclusion rules from database for the given user.
     *
     * Uses OrderRuleRepository to get the appropriate OrderRule based on:
     * - User's role and permission (Convenio + Consolidado)
     * - User's company (company-specific rules override general rules)
     * - Rule priority
     *
     * @param User $user
     * @return void
     */
    protected function loadPolymorphicExclusionsFromDatabase(User $user): void
    {
        // Get the OrderRule for this user
        $orderRule = $this->orderRuleRepository->getOrderRuleForUser($user, 'subcategory_exclusion');

        if (!$orderRule) {
            return;
        }

        // Get ALL polymorphic exclusions (no filtering)
        $exclusions = $orderRule->exclusions()
            ->with(['source', 'excluded'])
            ->get();

        // Reset the array
        $this->polymorphicExclusions = [];

        // Store exclusions in a format optimized for validation
        foreach ($exclusions as $exclusion) {
            $this->polymorphicExclusions[] = [
                'source_type' => $exclusion->source_type,
                'source_id' => $exclusion->source_id,
                'source_name' => $exclusion->getSourceName(),
                'excluded_type' => $exclusion->excluded_type,
                'excluded_id' => $exclusion->excluded_id,
                'excluded_name' => $exclusion->getExcludedName(),
                'exclusion' => $exclusion, // Keep reference for helper methods
            ];
        }
    }

    /**
     * Validate polymorphic exclusions between products.
     *
     * Checks all products in the order against all configured exclusion rules.
     * For each product, determines if it matches the source of any exclusion rule,
     * then checks if any other product matches the excluded target.
     *
     * @param \Illuminate\Support\Collection $products
     * @throws Exception
     */
    protected function validatePolymorphicExclusions($products): void
    {
        foreach ($products as $index => $product) {
            // Skip null products (e.g., "SIN PLATO DE FONDO")
            if ($product['is_null_product']) {
                continue;
            }

            // Check each exclusion rule
            foreach ($this->polymorphicExclusions as $exclusionRule) {
                // Check if current product matches the SOURCE of this exclusion rule
                if (!$this->productMatchesSource($product, $exclusionRule)) {
                    continue;
                }

                // Current product matches source, now check if any other product matches EXCLUDED
                foreach ($products as $otherIndex => $otherProduct) {
                    // Skip comparing product with itself
                    if ($otherIndex === $index) {
                        continue;
                    }

                    // Skip null products
                    if ($otherProduct['is_null_product']) {
                        continue;
                    }

                    // Check if other product matches the EXCLUDED target
                    if ($this->productMatchesExcluded($otherProduct, $exclusionRule)) {
                        throw new Exception(
                            $this->generateExclusionMessage(
                                $exclusionRule['source_name'],
                                $exclusionRule['excluded_name'],
                                $exclusionRule['source_type'],
                                $exclusionRule['excluded_type']
                            )
                        );
                    }
                }
            }
        }
    }

    /**
     * Check if a product matches the SOURCE of an exclusion rule.
     *
     * @param array $product Product data with category and subcategories
     * @param array $exclusionRule Exclusion rule data
     * @return bool
     */
    protected function productMatchesSource(array $product, array $exclusionRule): bool
    {
        // If source is a Category
        if ($exclusionRule['source_type'] === Category::class) {
            return $product['category_id'] === $exclusionRule['source_id'];
        }

        // If source is a Subcategory
        if ($exclusionRule['source_type'] === Subcategory::class) {
            return in_array($exclusionRule['source_name'], $product['subcategories']);
        }

        return false;
    }

    /**
     * Check if a product matches the EXCLUDED target of an exclusion rule.
     *
     * @param array $product Product data with category and subcategories
     * @param array $exclusionRule Exclusion rule data
     * @return bool
     */
    protected function productMatchesExcluded(array $product, array $exclusionRule): bool
    {
        // If excluded is a Category
        if ($exclusionRule['excluded_type'] === Category::class) {
            return $product['category_id'] === $exclusionRule['excluded_id'];
        }

        // If excluded is a Subcategory
        if ($exclusionRule['excluded_type'] === Subcategory::class) {
            return in_array($exclusionRule['excluded_name'], $product['subcategories']);
        }

        return false;
    }

    /**
     * Generate user-friendly message for exclusion conflicts.
     *
     * Message varies based on whether the exclusion involves categories or subcategories.
     *
     * @param string $sourceName Name of the source (category or subcategory)
     * @param string $excludedName Name of the excluded (category or subcategory)
     * @param string $sourceType Class name of source type
     * @param string $excludedType Class name of excluded type
     * @return string
     */
    protected function generateExclusionMessage(
        string $sourceName,
        string $excludedName,
        string $sourceType,
        string $excludedType
    ): string {
        $sourceLabel = $sourceType === Category::class ? 'categoría' : 'subcategoría';
        $excludedLabel = $excludedType === Category::class ? 'categoría' : 'subcategoría';

        return "No puedes combinar la {$sourceLabel} '{$sourceName}' con la {$excludedLabel} '{$excludedName}'.\n\n";
    }
}