<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use App\Repositories\OrderRuleRepository;
use Carbon\Carbon;
use Exception;

/**
 * Validates subcategory exclusion rules to ensure menu balance.
 *
 * NEW APPROACH: Rules are now loaded dynamically from the database via OrderRuleRepository.
 * The repository fetches OrderRuleExclusion records (polymorphic table) based on:
 * - User's role and permission
 * - User's company (company-specific rules take precedence)
 * - Rule priority (lower number = higher priority)
 * - Rule type: 'subcategory_exclusion'
 * - FILTERED to Subcategory → Subcategory only (this validator only handles subcategories)
 *
 * Common exclusion rules example:
 * - PLATO DE FONDO: Cannot have multiple main dishes
 * - ENTRADA: Cannot have multiple appetizers
 * - FRIA vs HIPOCALORICO: Cold items cannot be combined with low-calorie options
 * - PAN DE ACOMPAÑAMIENTO vs SANDWICH: Bread accompaniment cannot be combined with sandwiches
 *
 * Only applies when validate_subcategory_rules is TRUE and for individual agreement users.
 * Generates user-friendly messages explaining why certain combinations aren't allowed.
 */
class SubcategoryExclusion extends OrderStatusValidation
{
    protected OrderRuleRepository $orderRuleRepository;

    // OLD CODE - HARDCODED RULES (keeping for backward compatibility during testing)
    // TODO: Remove once database-driven approach is validated
    /*
    protected $subcategoryExclusions = [
        Subcategory::PLATO_DE_FONDO->value => [Subcategory::PLATO_DE_FONDO->value],
        Subcategory::ENTRADA->value => [Subcategory::ENTRADA->value],
        Subcategory::FRIA->value => [Subcategory::HIPOCALORICO->value],
        Subcategory::PAN_DE_ACOMPANAMIENTO->value => [Subcategory::SANDWICH->value],
    ];
    */

    // NEW CODE - DYNAMIC RULES FROM DATABASE
    protected array $subcategoryExclusions = [];

    public function __construct()
    {
        $this->orderRuleRepository = new OrderRuleRepository();
    }

    protected function check(Order $order, User $user, Carbon $date): void
    {
        if(!$user->validate_subcategory_rules) {
            return;
        }

        if (UserPermissions::IsAgreementIndividual($user)) {
            // NEW CODE - Load rules from database
            $this->loadSubcategoryExclusionsFromDatabase($user);

            $filteredOrderLines = $order->orderLines->filter(function ($orderLine) {
                return $orderLine->product->category->subcategories->isNotEmpty();
            });

            if ($filteredOrderLines->isEmpty()) {
                return;
            }

            $productsWithSubcategories = $filteredOrderLines->map(function ($orderLine) {
                return [
                    'product_id' => $orderLine->product->id,
                    'category' => $orderLine->product->category,
                    'subcategories' => $orderLine->product->category->subcategories->pluck('name')->toArray(),
                    'product_name' => $orderLine->product->name,
                    'is_null_product' => $orderLine->product->is_null_product,
                ];
            });

            $this->validateSubcategoryExclusions($productsWithSubcategories);
        }
    }

    /**
     * Load subcategory exclusion rules from database for the given user.
     *
     * NEW VERSION: Uses OrderRuleExclusion (polymorphic) helper methods.
     * Builds the $subcategoryExclusions array from OrderRuleExclusion records.
     *
     * @param User $user
     * @return void
     */
    protected function loadSubcategoryExclusionsFromDatabase(User $user): void
    {
        $exclusions = $this->orderRuleRepository->getSubcategoryExclusionsForUser($user);

        // Reset the array
        $this->subcategoryExclusions = [];

        // Build the exclusions array in the same format as the hardcoded version
        foreach ($exclusions as $exclusion) {
            // NEW: Use helper methods (works with polymorphic relationships)
            $subcategoryName = $exclusion->getSourceName();
            $excludedSubcategoryName = $exclusion->getExcludedName();

            // OLD CODE (using old non-polymorphic relationships - COMMENTED OUT):
            /*
            $subcategoryName = $exclusion->subcategory->name;
            $excludedSubcategoryName = $exclusion->excludedSubcategory->name;
            */

            if (!isset($this->subcategoryExclusions[$subcategoryName])) {
                $this->subcategoryExclusions[$subcategoryName] = [];
            }

            $this->subcategoryExclusions[$subcategoryName][] = $excludedSubcategoryName;
        }
    }

    /**
     * Validate subcategory exclusions between products.
     *
     * @param \Illuminate\Support\Collection $products
     * @throws Exception
     */
    protected function validateSubcategoryExclusions($products): void
    {
        foreach ($products as $index => $product) {
            foreach ($this->subcategoryExclusions as $subcategory => $excludedSubcategories) {
                if (in_array($subcategory, $product['subcategories'])) {
                    foreach ($products as $otherIndex => $otherProduct) {
                        if ($otherIndex === $index) {
                            continue;
                        }

                        // Skip validation if either product is null product (e.g., "SIN PLATO DE FONDO")
                        // because null products represent absence of that type, not a real product
                        if ($product['is_null_product'] || $otherProduct['is_null_product']) {
                            continue;
                        }

                        foreach ($excludedSubcategories as $excludedSubcategory) {
                            if (in_array($excludedSubcategory, $otherProduct['subcategories'])) {
                                throw new Exception(
                                    $this->generateSubcategoryConflictMessage($subcategory, $excludedSubcategory)
                                );
                            }
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Generate user-friendly message for subcategory conflicts
     */
    private function generateSubcategoryConflictMessage(string $subcategory1, string $subcategory2): string
    {
        return "No puedes combinar las subcategorías: {$subcategory1} con {$subcategory2}.\n\n";
    }
}