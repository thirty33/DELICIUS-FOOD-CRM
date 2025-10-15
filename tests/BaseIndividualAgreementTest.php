<?php

namespace Tests;

use App\Models\Role;
use App\Models\Permission;
use App\Models\OrderRule;
use App\Models\OrderRuleSubcategoryExclusion;
use App\Models\OrderRuleSubcategoryLimit;
use App\Models\Subcategory;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\Subcategory as SubcategoryEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base test class for Individual Agreement tests.
 *
 * This class sets up the common infrastructure for Individual Agreement tests:
 * - Creates Role (AGREEMENT) and Permission (INDIVIDUAL)
 * - Creates OrderRule with subcategory exclusions
 * - Creates OrderRule with subcategory product limits
 *
 * Child classes can customize the rules by overriding getSubcategoryExclusions() and getSubcategoryLimits().
 */
abstract class BaseIndividualAgreementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create base role and permission
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // Create order rule for exclusions
        $exclusionRule = OrderRule::create([
            'name' => 'General Subcategory Exclusion Rules',
            'description' => 'Default subcategory exclusion rules for individual agreements',
            'rule_type' => 'subcategory_exclusion',
            'role_id' => $agreementRole->id,
            'permission_id' => $individualPermission->id,
            'priority' => 100,
            'is_active' => true,
        ]);

        // Get exclusion rules from child class (Template Method Pattern)
        $subcategoryExclusions = $this->getSubcategoryExclusions();

        // Create exclusion rules
        $this->createSubcategoryExclusions($exclusionRule, $subcategoryExclusions);

        // Create order rule for product limits
        $limitRule = OrderRule::create([
            'name' => 'General Subcategory Product Limits',
            'description' => 'Default maximum product limits per subcategory for individual agreements',
            'rule_type' => 'product_limit_per_subcategory',
            'role_id' => $agreementRole->id,
            'permission_id' => $individualPermission->id,
            'priority' => 100,
            'is_active' => true,
        ]);

        // Get limit rules from child class (Template Method Pattern)
        $subcategoryLimits = $this->getSubcategoryLimits();

        // Create limit rules
        $this->createSubcategoryLimits($limitRule, $subcategoryLimits);
    }

    /**
     * Get the subcategory exclusion rules for this test.
     *
     * Child classes can override this method to define their own exclusion rules.
     *
     * @return array Array with format: ['SUBCATEGORY_NAME' => ['EXCLUDED_SUBCATEGORY_1', 'EXCLUDED_SUBCATEGORY_2']]
     */
    protected function getSubcategoryExclusions(): array
    {
        // Default exclusion rules (standard for most Individual Agreement scenarios)
        return [
            SubcategoryEnum::PLATO_DE_FONDO->value => [SubcategoryEnum::PLATO_DE_FONDO->value],
            SubcategoryEnum::ENTRADA->value => [SubcategoryEnum::ENTRADA->value],
            SubcategoryEnum::FRIA->value => [SubcategoryEnum::HIPOCALORICO->value],
            SubcategoryEnum::PAN_DE_ACOMPANAMIENTO->value => [SubcategoryEnum::SANDWICH->value],
        ];
    }

    /**
     * Get the subcategory product limit rules for this test.
     *
     * Child classes can override this method to define their own limit rules.
     *
     * @return array Array with format: ['SUBCATEGORY_NAME' => max_products]
     */
    protected function getSubcategoryLimits(): array
    {
        // Default limits: max 1 product for each subcategory that has exclusions
        return [
            SubcategoryEnum::PLATO_DE_FONDO->value => 1,
            SubcategoryEnum::ENTRADA->value => 1,
            SubcategoryEnum::FRIA->value => 1,
            SubcategoryEnum::PAN_DE_ACOMPANAMIENTO->value => 1,
        ];
    }

    /**
     * Create OrderRuleSubcategoryExclusion records from the exclusion rules array.
     *
     * @param OrderRule $orderRule
     * @param array $subcategoryExclusions
     * @return void
     */
    protected function createSubcategoryExclusions(OrderRule $orderRule, array $subcategoryExclusions): void
    {
        foreach ($subcategoryExclusions as $subcategoryName => $excludedSubcategories) {
            $subcategory = Subcategory::firstOrCreate(['name' => $subcategoryName]);

            foreach ($excludedSubcategories as $excludedSubcategoryName) {
                $excludedSubcategory = Subcategory::firstOrCreate(['name' => $excludedSubcategoryName]);

                OrderRuleSubcategoryExclusion::create([
                    'order_rule_id' => $orderRule->id,
                    'subcategory_id' => $subcategory->id,
                    'excluded_subcategory_id' => $excludedSubcategory->id,
                ]);
            }
        }
    }

    /**
     * Create OrderRuleSubcategoryLimit records from the limit rules array.
     *
     * @param OrderRule $orderRule
     * @param array $subcategoryLimits
     * @return void
     */
    protected function createSubcategoryLimits(OrderRule $orderRule, array $subcategoryLimits): void
    {
        foreach ($subcategoryLimits as $subcategoryName => $maxProducts) {
            $subcategory = Subcategory::firstOrCreate(['name' => $subcategoryName]);

            OrderRuleSubcategoryLimit::create([
                'order_rule_id' => $orderRule->id,
                'subcategory_id' => $subcategory->id,
                'max_products' => $maxProducts,
            ]);
        }
    }
}
