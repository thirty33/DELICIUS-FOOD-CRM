<?php

namespace Tests\Feature\API\V1\Agreement\Individual;

use Tests\BaseIndividualAgreementTest;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Menu;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Product;
use App\Models\PriceListLine;
use App\Models\Subcategory;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderRule;
use App\Models\OrderRuleSubcategoryExclusion;
use App\Models\OrderRuleSubcategoryLimit;
use App\Models\Role;
use App\Models\Permission;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\Subcategory as SubcategoryEnum;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Test to verify company-specific OrderRule behavior.
 *
 * This test demonstrates:
 * 1. Default behavior: General rules apply (ENTRADA exclusion returns 422)
 * 2. Company-specific override: Company rules take precedence over general rules (allows ENTRADA, returns 200)
 *
 * Priority system:
 * - Company-specific rules (linked via order_rule_companies pivot) have priority
 * - General rules (no company association) are fallback
 * - Lower priority number = higher priority
 */
class CompanySpecificRulesTest extends BaseIndividualAgreementTest
{
    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time to 2025-10-14
        Carbon::setTestNow('2025-10-14 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // NOTE: We don't override getSubcategoryExclusions()
    // So this test uses the DEFAULT rules from BaseIndividualAgreementTest

    /**
     * Test 1: Default behavior - Multiple ENTRADA products are rejected (422).
     *
     * This test verifies that with the default general rules from BaseIndividualAgreementTest,
     * attempting to add multiple ENTRADA products returns a 422 validation error.
     */
    public function test_default_rules_reject_multiple_entrada_products(): void
    {
        // Get roles and permissions
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // Create price list
        $priceList = PriceList::create([
            'name' => 'Test Price List Default',
            'active' => true,
        ]);

        // Create company (no specific rules associated)
        $company = Company::create([
            'name' => 'Test Company Default Rules',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'default@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // Create branch
        $branch = Branch::create([
            'name' => 'Main Branch',
            'address' => 'Branch Address',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // Create user with validation enabled
        $user = User::create([
            'name' => 'Test User Default',
            'nickname' => 'TEST.DEFAULT.RULES',
            'email' => 'test.default@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // Get subcategories
        $entradaSubcat = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $calienteSubcat = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $friaSubcat = Subcategory::firstOrCreate(['name' => 'FRIA']);

        // Create menu
        $menu = Menu::create([
            'title' => 'TEST MENU DEFAULT RULES',
            'publication_date' => '2025-10-14',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // Category 1: SOPAS (ENTRADA + CALIENTE)
        $categorySopas = Category::create([
            'name' => 'TEST SOPAS',
            'description' => 'Test category',
            'active' => true,
        ]);
        $categorySopas->subcategories()->attach([$entradaSubcat->id, $calienteSubcat->id]);

        $productSopa = Product::create([
            'name' => 'TEST - SOPA DE POLLO',
            'description' => 'Test soup',
            'code' => 'TEST-SOPA-001',
            'category_id' => $categorySopas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productSopa->id,
            'unit_price' => 3000.00,
            'active' => true,
        ]);

        // Category 2: ENSALADAS (ENTRADA + FRIA)
        $categoryEnsaladas = Category::create([
            'name' => 'TEST ENSALADAS',
            'description' => 'Test category',
            'active' => true,
        ]);
        $categoryEnsaladas->subcategories()->attach([$entradaSubcat->id, $friaSubcat->id]);

        $productEnsalada = Product::create([
            'name' => 'TEST - ENSALADA VERDE',
            'description' => 'Test salad',
            'code' => 'TEST-ENSALADA-001',
            'category_id' => $categoryEnsaladas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productEnsalada->id,
            'unit_price' => 2500.00,
            'active' => true,
        ]);

        // Create CategoryMenus
        $categoryMenuSopas = CategoryMenu::create([
            'category_id' => $categorySopas->id,
            'menu_id' => $menu->id,
            'order' => 10,
            'display_order' => 10,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuSopas->products()->attach($productSopa->id);

        $categoryMenuEnsaladas = CategoryMenu::create([
            'category_id' => $categoryEnsaladas->id,
            'menu_id' => $menu->id,
            'order' => 20,
            'display_order' => 20,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuEnsaladas->products()->attach($productEnsalada->id);

        // Create order with TWO ENTRADA products
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => Carbon::parse('2025-10-14'),
            'dispatch_date' => Carbon::parse('2025-10-14'),
            'status' => OrderStatus::PENDING->value,
            'total' => 5500.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productSopa->id,
            'quantity' => 1,
            'unit_price' => 3000.00,
            'total' => 3000.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productEnsalada->id,
            'quantity' => 1,
            'unit_price' => 2500.00,
            'total' => 2500.00,
        ]);

        // Authenticate user
        Sanctum::actingAs($user);

        // Try to update order status
        // Should FAIL (422) because default rule ENTRADA->ENTRADA is active
        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-14", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // ASSERTIONS - Should return 422 with ENTRADA exclusion message
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    "No puedes combinar las subcategorías: ENTRADA con ENTRADA.\n\n"
                ],
            ],
        ]);

        // Verify order status was NOT updated
        $order->refresh();
        $this->assertEquals(OrderStatus::PENDING->value, $order->status);
    }

    /**
     * Test 2: Company-specific rules override general rules - Multiple ENTRADA allowed (200).
     *
     * This test creates a company-specific OrderRule with:
     * - Same type as general rule (subcategory_exclusion)
     * - Lower priority number (higher priority): 50 vs 100
     * - Associated to the user's company via order_rule_companies pivot table
     * - Same exclusions EXCEPT ENTRADA->ENTRADA is removed
     *
     * Expected: Company-specific rule takes precedence, allowing multiple ENTRADA products.
     */
    public function test_company_specific_rules_override_general_rules_allowing_multiple_entrada(): void
    {
        // Get roles and permissions (created by BaseIndividualAgreementTest)
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // Create price list
        $priceList = PriceList::create([
            'name' => 'Test Price List Company Specific',
            'active' => true,
        ]);

        // Create company that will have specific rules
        $company = Company::create([
            'name' => 'Test Company Specific Rules',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'specific@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // Create branch
        $branch = Branch::create([
            'name' => 'Main Branch',
            'address' => 'Branch Address',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // Create user with validation enabled
        $user = User::create([
            'name' => 'Test User Company Specific',
            'nickname' => 'TEST.COMPANY.SPECIFIC',
            'email' => 'test.specific@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // CREATE COMPANY-SPECIFIC ORDER RULE
        // Priority 50 (lower number = higher priority than general rule which is 100)
        $companyOrderRule = OrderRule::create([
            'name' => 'Company Specific Subcategory Exclusion Rules',
            'description' => 'Custom rules for this specific company - allows multiple ENTRADA',
            'rule_type' => 'subcategory_exclusion',
            'role_id' => $agreementRole->id,
            'permission_id' => $individualPermission->id,
            'priority' => 50, // Higher priority than general rule (100)
            'is_active' => true,
        ]);

        // ASSOCIATE ORDER RULE TO COMPANY via pivot table
        $companyOrderRule->companies()->attach($company->id);

        // CREATE EXCLUSIONS - Same as base EXCEPT ENTRADA->ENTRADA is removed
        $companySpecificExclusions = [
            SubcategoryEnum::PLATO_DE_FONDO->value => [SubcategoryEnum::PLATO_DE_FONDO->value],
            // SubcategoryEnum::ENTRADA->value => [SubcategoryEnum::ENTRADA->value], // REMOVED - Allow multiple ENTRADA
            SubcategoryEnum::FRIA->value => [SubcategoryEnum::HIPOCALORICO->value],
            SubcategoryEnum::PAN_DE_ACOMPANAMIENTO->value => [SubcategoryEnum::SANDWICH->value],
        ];

        foreach ($companySpecificExclusions as $subcategoryName => $excludedSubcategories) {
            $subcategory = Subcategory::firstOrCreate(['name' => $subcategoryName]);

            foreach ($excludedSubcategories as $excludedSubcategoryName) {
                $excludedSubcategory = Subcategory::firstOrCreate(['name' => $excludedSubcategoryName]);

                OrderRuleSubcategoryExclusion::create([
                    'order_rule_id' => $companyOrderRule->id,
                    'subcategory_id' => $subcategory->id,
                    'excluded_subcategory_id' => $excludedSubcategory->id,
                ]);
            }
        }

        // CREATE COMPANY-SPECIFIC LIMIT RULE
        // Priority 50 (same as exclusion rule - higher priority than general rule which is 100)
        $companyLimitRule = OrderRule::create([
            'name' => 'Company Specific Product Limits',
            'description' => 'Custom product limits for this specific company - allows 2 ENTRADA',
            'rule_type' => 'product_limit_per_subcategory',
            'role_id' => $agreementRole->id,
            'permission_id' => $individualPermission->id,
            'priority' => 50, // Higher priority than general rule (100)
            'is_active' => true,
        ]);

        // ASSOCIATE LIMIT RULE TO COMPANY via pivot table
        $companyLimitRule->companies()->attach($company->id);

        // CREATE LIMITS - Allow 2 ENTRADA (instead of general limit of 1)
        $companySpecificLimits = [
            'ENTRADA' => 2,  // Company allows 2 ENTRADA products
            'CALIENTE' => 1, // SOPA has ENTRADA + CALIENTE
            'FRIA' => 1,     // ENSALADA has ENTRADA + FRIA
        ];

        foreach ($companySpecificLimits as $subcategoryName => $maxProducts) {
            $subcategory = Subcategory::firstOrCreate(['name' => $subcategoryName]);

            OrderRuleSubcategoryLimit::create([
                'order_rule_id' => $companyLimitRule->id,
                'subcategory_id' => $subcategory->id,
                'max_products' => $maxProducts,
            ]);
        }

        // Get subcategories (created by BaseIndividualAgreementTest and in the company rule creation above)
        $entradaSubcat = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $calienteSubcat = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $friaSubcat = Subcategory::firstOrCreate(['name' => 'FRIA']);

        // Create menu
        $menu = Menu::create([
            'title' => 'TEST MENU COMPANY SPECIFIC',
            'publication_date' => '2025-10-14',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // Category 1: SOPAS (ENTRADA + CALIENTE)
        $categorySopas = Category::create([
            'name' => 'TEST SOPAS COMPANY',
            'description' => 'Test category',
            'active' => true,
        ]);
        $categorySopas->subcategories()->attach([$entradaSubcat->id, $calienteSubcat->id]);

        $productSopa = Product::create([
            'name' => 'TEST - SOPA COMPANY',
            'description' => 'Test soup',
            'code' => 'TEST-SOPA-002',
            'category_id' => $categorySopas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productSopa->id,
            'unit_price' => 3000.00,
            'active' => true,
        ]);

        // Category 2: ENSALADAS (ENTRADA + FRIA)
        $categoryEnsaladas = Category::create([
            'name' => 'TEST ENSALADAS COMPANY',
            'description' => 'Test category',
            'active' => true,
        ]);
        $categoryEnsaladas->subcategories()->attach([$entradaSubcat->id, $friaSubcat->id]);

        $productEnsalada = Product::create([
            'name' => 'TEST - ENSALADA COMPANY',
            'description' => 'Test salad',
            'code' => 'TEST-ENSALADA-002',
            'category_id' => $categoryEnsaladas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productEnsalada->id,
            'unit_price' => 2500.00,
            'active' => true,
        ]);

        // Create CategoryMenus
        $categoryMenuSopas = CategoryMenu::create([
            'category_id' => $categorySopas->id,
            'menu_id' => $menu->id,
            'order' => 10,
            'display_order' => 10,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuSopas->products()->attach($productSopa->id);

        $categoryMenuEnsaladas = CategoryMenu::create([
            'category_id' => $categoryEnsaladas->id,
            'menu_id' => $menu->id,
            'order' => 20,
            'display_order' => 20,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuEnsaladas->products()->attach($productEnsalada->id);

        // Create order with TWO ENTRADA products
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => Carbon::parse('2025-10-14'),
            'dispatch_date' => Carbon::parse('2025-10-14'),
            'status' => OrderStatus::PENDING->value,
            'total' => 5500.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productSopa->id,
            'quantity' => 1,
            'unit_price' => 3000.00,
            'total' => 3000.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productEnsalada->id,
            'quantity' => 1,
            'unit_price' => 2500.00,
            'total' => 2500.00,
        ]);

        // Authenticate user
        Sanctum::actingAs($user);

        // Try to update order status
        // Should SUCCEED (200) because company-specific rule allows multiple ENTRADA
        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-14", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // ASSERTIONS - Should return 200 (success)
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Order status updated successfully',
        ]);

        // Verify order status WAS updated to PROCESSED
        $order->refresh();
        $this->assertEquals(OrderStatus::PROCESSED->value, $order->status);
    }
}
