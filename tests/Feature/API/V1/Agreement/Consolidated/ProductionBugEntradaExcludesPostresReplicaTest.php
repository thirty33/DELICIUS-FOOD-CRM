<?php

namespace Tests\Feature\API\V1\Agreement\Consolidated;

use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\Menu;
use App\Models\OrderRule;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\BaseConsolidatedAgreementTest;

/**
 * Production Bug Replica Test - ENTRADA Subcategory Excludes POSTRES Category
 *
 * PRODUCTION DATA (anonymized):
 * - User: TEST.CONSOLIDATED.USER (production: MBO.MILITARES, ID: 185)
 * - Company: TEST CONSOLIDATED COMPANY S.A. (production: COMERCIALIZADORA DE VESTUARIO S A, ID: 558)
 * - Menu: 324 (date: 2025-10-22)
 * - Order: 155
 * - Role: Convenio (ID: 3)
 * - Permission: Consolidado (ID: 1)
 *
 * SCENARIO:
 * 1. User creates order with product from ENTRADA subcategory (ACM - MINI ENSALADA A LA CHILENA)
 * 2. User attempts to add product from POSTRES category (PTR - BARRA DE CEREAL)
 * 3. Company-specific rule: ENTRADA (Subcategory) → POSTRES (Category) exclusion exists
 *
 * EXPECTED:
 * - Request should return 422 status
 * - Error message should indicate ENTRADA cannot be combined with POSTRES
 *
 * ACTUAL BUG (before fix):
 * - SubcategoryExclusion validator only checks Subcategory → Subcategory exclusions
 * - Polymorphic Subcategory → Category exclusions are ignored
 * - Request returns 200 OK (should fail with 422)
 *
 * API ENDPOINT:
 * POST /api/v1/orders/date/{date}
 * Request: {"products": [{"product_id": 1038, "quantity": 1}]}
 *
 * ROOT CAUSE:
 * - OrderRuleRepository::getSubcategoryExclusionsForUser() filters to ONLY Subcategory → Subcategory
 * - Polymorphic exclusions (Subcategory → Category) are not processed
 * - Need separate validator or extend SubcategoryExclusion to handle mixed polymorphic exclusions
 */
class ProductionBugEntradaExcludesPostresReplicaTest extends BaseConsolidatedAgreementTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-10-22 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_entrada_subcategory_should_exclude_postres_category(): void
    {
        // 1. GET ROLES AND PERMISSIONS (from parent setUp)
        $role = Role::where('name', RoleName::AGREEMENT->value)->first();
        $permission = Permission::where('name', PermissionName::CONSOLIDADO->value)->first();

        // 2. CREATE COMPANY-SPECIFIC DATA
        $priceList = PriceList::create([
            'name' => 'TEST MBO Price List',
            'code' => 'TEST_MBO',
            'description' => 'Test price list for consolidated agreement',
            'is_active' => true,
        ]);

        $company = Company::create([
            'name' => 'TEST CONSOLIDATED COMPANY S.A.',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TEST_CONS_001',
            'fantasy_name' => 'Test Consolidated Co',
            'address' => 'Test Address 123',
            'email' => 'contact@testconsolidated.com',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'name' => 'Test Main Branch',
            'address' => 'Test Branch Address',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
            'is_main_branch' => true,
        ]);

        // 3. CREATE COMPANY-SPECIFIC RULE WITH ENTRADA → POSTRES EXCLUSION
        $companySpecificRule = OrderRule::create([
            'name' => 'Company-Specific Exclusions - TEST CONSOLIDATED COMPANY',
            'description' => 'ENTRADA subcategory excludes POSTRES category',
            'rule_type' => 'subcategory_exclusion',
            'role_id' => $role->id,
            'permission_id' => $permission->id,
            'priority' => 50, // Higher priority (lower number) than general rules (100)
            'is_active' => true,
        ]);

        // Associate rule with company
        $companySpecificRule->companies()->attach($company->id);

        // Create polymorphic exclusion: ENTRADA (Subcategory) → POSTRES (Category)
        $this->createPolymorphicExclusions($companySpecificRule, [
            [
                'source_type' => Subcategory::class,
                'source_name' => 'ENTRADA',
                'excluded_type' => Category::class,
                'excluded_name' => 'POSTRES',
            ],
        ]);

        // 4. CREATE USER
        $user = User::create([
            'name' => 'Test Consolidated User',
            'nickname' => 'TEST.CONSOLIDATED.USER',
            'email' => 'test.consolidated@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'validate_subcategory_rules' => true, // IMPORTANT: Enable validation
        ]);

        $user->roles()->attach($role->id);
        $user->permissions()->attach($permission->id);

        // 5. CREATE CATEGORIES WITH SUBCATEGORIES
        // ENTRADA subcategory
        $entradaSubcategory = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $friaSubcategory = Subcategory::firstOrCreate(['name' => 'FRIA']);

        // Category with ENTRADA subcategory
        $miniEnsaladasCategory = Category::create([
            'name' => 'MINI ENSALADAS DE ACOMPAÑAMIENTO',
            'description' => 'Mini salads category',
            'order' => 1,
            'is_active' => true,
        ]);

        $miniEnsaladasCategory->subcategories()->attach([
            $entradaSubcategory->id,
            $friaSubcategory->id,
        ]);

        // POSTRES category (NO subcategories - this is key)
        // Note: May already exist from polymorphic exclusion creation
        $postresCategory = Category::firstOrCreate(
            ['name' => 'POSTRES'], // Search criteria
            [ // Default values if creating
                'description' => 'Desserts category',
                'is_active' => true,
            ]
        );

        // 6. CREATE PRODUCTS
        // Product 1: ACM - MINI ENSALADA A LA CHILENA (has ENTRADA subcategory)
        $entradaProduct = Product::create([
            'name' => 'ACM - MINI ENSALADA A LA CHILENA',
            'description' => 'Chilean style mini salad',
            'code' => 'ACM_ENSALADA_001',
            'category_id' => $miniEnsaladasCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $entradaProduct->id,
            'sale_price' => 30000.00,
        ]);

        // Product 2: PTR - BARRA DE CEREAL (POSTRES category, NO subcategories)
        $postresProduct = Product::create([
            'name' => 'PTR - BARRA DE CEREAL',
            'description' => 'Cereal bar dessert',
            'code' => 'PTR_BARRA_001',
            'category_id' => $postresCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $postresProduct->id,
            'sale_price' => 50000.00,
        ]);

        // 7. CREATE MENU
        $menu = Menu::create([
            'title' => 'Test Menu 2025-10-22',
            'publication_date' => Carbon::parse('2025-10-22'),
            'role_id' => $role->id,
            'permissions_id' => $permission->id, // Note: permissions_id (plural)
            'active' => true,
            'deadline_time' => '23:59:00',
        ]);

        // Create CategoryMenu for both categories
        $categoryMenuEntrada = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $miniEnsaladasCategory->id,
            'is_mandatory' => false,
            'order' => 1,
            'show_all_products' => true,
        ]);

        $categoryMenuPostres = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $postresCategory->id,
            'is_mandatory' => false,
            'order' => 2,
            'show_all_products' => true,
        ]);

        // Attach products to CategoryMenu
        $categoryMenuEntrada->products()->attach($entradaProduct->id);
        $categoryMenuPostres->products()->attach($postresProduct->id);

        // 8. AUTHENTICATE USER
        Sanctum::actingAs($user);

        // 9. REPLICATE THE EXACT API CALLS FROM PRODUCTION
        $date = '2025-10-22';

        // Step 1: Create order with ENTRADA product (should succeed)
        $response = $this->postJson("/api/v1/orders/create-or-update-order/{$date}", [
            'order_lines' => [
                [
                    'id' => $entradaProduct->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(200);

        // Step 2: Add POSTRES product to order (should FAIL with 422)
        $response = $this->postJson("/api/v1/orders/create-or-update-order/{$date}", [
            'order_lines' => [
                [
                    'id' => $postresProduct->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        // 10. ASSERTIONS - EXPECT 422 (exclusion should be enforced)
        // THIS TEST WILL FAIL UNTIL THE BUG IS FIXED
        // Current behavior: Returns 200 OK (bug - should return 422)
        // Expected behavior: Returns 422 with exclusion error message
        $response->assertStatus(422);

        // Verify error message mentions the exclusion
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'message',
            ],
        ]);

        // The error message should mention ENTRADA and POSTRES cannot be combined
        $errorMessage = $response->json('errors.message.0');
        $this->assertStringContainsString('ENTRADA', $errorMessage);
        $this->assertStringContainsString('POSTRES', $errorMessage);
    }
}