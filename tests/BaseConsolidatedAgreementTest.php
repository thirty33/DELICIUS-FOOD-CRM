<?php

namespace Tests;

use App\Models\Role;
use App\Models\Permission;
use App\Models\OrderRule;
use App\Models\OrderRuleExclusion;
use App\Models\OrderRuleSubcategoryLimit;
use App\Models\Subcategory;
use App\Models\Category;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\Subcategory as SubcategoryEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base test class for Consolidated Agreement tests.
 *
 * This class sets up the common infrastructure for Consolidated Agreement tests:
 * - Creates Role (AGREEMENT) and Permission (CONSOLIDATED)
 * - Creates OrderRule with subcategory/category exclusions
 * - Creates OrderRule with subcategory product limits
 *
 * Child classes can customize the rules by overriding getSubcategoryExclusions() and getSubcategoryLimits().
 */
abstract class BaseConsolidatedAgreementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create base role and permission for Consolidated
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $consolidatedPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // Create order rule for exclusions
        $exclusionRule = OrderRule::create([
            'name' => 'General Subcategory Exclusion Rules - Consolidated',
            'description' => 'Default subcategory exclusion rules for consolidated agreements',
            'rule_type' => 'subcategory_exclusion',
            'role_id' => $agreementRole->id,
            'permission_id' => $consolidatedPermission->id,
            'priority' => 100,
            'is_active' => true,
        ]);

        // Get exclusion rules from child class (Template Method Pattern)
        $subcategoryExclusions = $this->getSubcategoryExclusions();

        // Create exclusion rules (Subcategory → Subcategory)
        $this->createSubcategoryExclusions($exclusionRule, $subcategoryExclusions);

        // Create order rule for product limits
        $limitRule = OrderRule::create([
            'name' => 'General Subcategory Product Limits - Consolidated',
            'description' => 'Default maximum product limits per subcategory for consolidated agreements',
            'rule_type' => 'product_limit_per_subcategory',
            'role_id' => $agreementRole->id,
            'permission_id' => $consolidatedPermission->id,
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
        // Default exclusion rules (standard for most Consolidated Agreement scenarios)
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
     * Create OrderRuleExclusion records from the exclusion rules array.
     *
     * NEW VERSION: Uses polymorphic order_rule_exclusions table.
     * Creates Subcategory → Subcategory exclusions.
     *
     * @param OrderRule $orderRule
     * @param array $subcategoryExclusions Array with format: ['SUBCATEGORY_NAME' => ['EXCLUDED_SUBCATEGORY_1', ...]]
     * @return void
     */
    protected function createSubcategoryExclusions(OrderRule $orderRule, array $subcategoryExclusions): void
    {
        foreach ($subcategoryExclusions as $subcategoryName => $excludedSubcategories) {
            $subcategory = Subcategory::firstOrCreate(['name' => $subcategoryName]);

            foreach ($excludedSubcategories as $excludedSubcategoryName) {
                $excludedSubcategory = Subcategory::firstOrCreate(['name' => $excludedSubcategoryName]);

                // NEW: Create in polymorphic table (Subcategory → Subcategory)
                OrderRuleExclusion::create([
                    'order_rule_id' => $orderRule->id,
                    'source_id' => $subcategory->id,
                    'source_type' => Subcategory::class,
                    'excluded_id' => $excludedSubcategory->id,
                    'excluded_type' => Subcategory::class,
                ]);
            }
        }
    }

    /**
     * Create polymorphic exclusion rules (supports Subcategory → Category, Category → Subcategory, etc.).
     *
     * NEW METHOD: For creating mixed polymorphic exclusions beyond just Subcategory → Subcategory.
     *
     * @param OrderRule $orderRule
     * @param array $exclusions Array with format:
     *   [
     *     ['source_type' => Subcategory::class, 'source_name' => 'ENTRADA', 'excluded_type' => Category::class, 'excluded_name' => 'POSTRES'],
     *     ['source_type' => Category::class, 'source_name' => 'Ensaladas', 'excluded_type' => Subcategory::class, 'excluded_name' => 'SANDWICH'],
     *   ]
     * @return void
     */
    protected function createPolymorphicExclusions(OrderRule $orderRule, array $exclusions): void
    {
        foreach ($exclusions as $exclusion) {
            // Get or create source entity
            if ($exclusion['source_type'] === Subcategory::class) {
                $source = Subcategory::firstOrCreate(['name' => $exclusion['source_name']]);
            } else {
                $source = Category::firstOrCreate(
                    ['name' => $exclusion['source_name']], // Search criteria
                    [ // Default values if creating
                        'description' => $exclusion['source_name'],
                        'is_active' => true,
                    ]
                );
            }

            // Get or create excluded entity
            if ($exclusion['excluded_type'] === Subcategory::class) {
                $excluded = Subcategory::firstOrCreate(['name' => $exclusion['excluded_name']]);
            } else {
                $excluded = Category::firstOrCreate(
                    ['name' => $exclusion['excluded_name']], // Search criteria
                    [ // Default values if creating
                        'description' => $exclusion['excluded_name'],
                        'is_active' => true,
                    ]
                );
            }

            // Create polymorphic exclusion
            OrderRuleExclusion::create([
                'order_rule_id' => $orderRule->id,
                'source_id' => $source->id,
                'source_type' => $exclusion['source_type'],
                'excluded_id' => $excluded->id,
                'excluded_type' => $exclusion['excluded_type'],
            ]);
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