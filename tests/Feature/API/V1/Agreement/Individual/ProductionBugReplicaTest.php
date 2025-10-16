<?php

namespace Tests\Feature\API\V1\Agreement\Individual;

use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderRule;
use App\Models\OrderRuleSubcategoryLimit;
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
use Tests\BaseIndividualAgreementTest;

/**
 * Production Bug Replica Test - Company Specific Rules Case
 *
 * This test replicates EXACTLY a production scenario:
 *
 * PRODUCTION DATA (anonymized):
 * - User: TEST.INDIVIDUAL.USER (production user ID: 547)
 * - Company: TEST COMPANY S.A. (production company ID: 588)
 * - Role: Convenio, Permission: Individual
 * - validate_subcategory_rules: TRUE
 *
 * EXISTING ORDER (Order 120):
 * - Product 1307: PPC - CERDO AL JUGO ARVEJADO (Subcategories: PLATO DE FONDO)
 * - Product 1057: ACM - CREMA DE ZAPALLO 300 GR (Subcategories: ENTRADA, CALIENTE)
 *
 * PRODUCT TO ADD:
 * - Product 1063: ACM - CREMA DE ESPINACA CON POLLO 300 GR (Subcategories: ENTRADA, CALIENTE)
 *
 * ACTIVE RULES (Exclusions):
 * - Exclusion Rule 1 (General, Priority 100): ENTRADA => ENTRADA exclusion
 * - Exclusion Rule 2 (Company-Specific, Priority 99): Does NOT include ENTRADA => ENTRADA exclusion
 *
 * ACTIVE RULES (Limits):
 * - Limit Rule 1 (General, Priority 100): Max 1 ENTRADA product
 * - Limit Rule 2 (Company-Specific, Priority 99): Max 2 ENTRADA products
 *
 * EXPECTED:
 * - Company-specific rules (Priority 99) should override general rules (Priority 100)
 * - Should allow adding second ENTRADA product (company-specific limit is 2)
 * - API should return 200 OK
 *
 * ACTUAL BUG:
 * - Returns 422: "Solo puedes elegir un ENTRADA por pedido."
 * - Caused by OneProductPerSubcategory validation ignoring database rules
 */
class ProductionBugReplicaTest extends BaseIndividualAgreementTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-10-19 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function getSubcategoryExclusions(): array
    {
        // Base class will create general rule, we'll override with company-specific
        return [
            'PLATO DE FONDO' => ['PLATO DE FONDO'],
            'ENTRADA' => ['ENTRADA'],  // ← General rule HAS this
            'FRIA' => ['HIPOCALORICO'],
            'PAN DE ACOMPAÑAMIENTO' => ['SANDWICH'],
        ];
    }

    protected function getSubcategoryLimits(): array
    {
        // Default limits: max 1 for most, but max 2 for ENTRADA (company-specific case)
        return [
            'PLATO DE FONDO' => 1,
            'ENTRADA' => 1,  // ← Company-specific rule allows 2 ENTRADA products
            'FRIA' => 1,
            'PAN DE ACOMPAÑAMIENTO' => 1,
        ];
    }

    public function test_company_specific_rule_should_allow_multiple_entrada_products(): void
    {
        // Get roles (created by parent setUp)
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // CREATE COMPANY-SPECIFIC RULE (simulating company-specific Rule ID 2, Priority 99)
        $companyRule = OrderRule::create([
            'name' => 'Exclusión de categorías (Empresa Específica)',
            'rule_type' => 'subcategory_exclusion',
            'role_id' => $agreementRole->id,
            'permission_id' => $individualPermission->id,
            'priority' => 99, // Higher priority than general (100)
            'is_active' => true,
        ]);

        // Create company
        $priceList = PriceList::create(['name' => 'Test Company Price List', 'active' => true]);

        $company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'fantasy_name' => 'TEST CO',
            'address' => 'Test Address',
            'email' => 'test@testcompany.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'name' => 'TEST BRANCH',
            'address' => 'Branch Address',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // Associate company-specific rule
        $companyRule->companies()->attach($company->id);

        // Company-specific rule does NOT have ENTRADA => ENTRADA exclusion
        $this->createSubcategoryExclusions($companyRule, [
            'PLATO DE FONDO' => ['PLATO DE FONDO'],
            // NO 'ENTRADA' => ['ENTRADA'] ← Key difference!
            'FRIA' => ['HIPOCALORICO'],
            'PAN DE ACOMPAÑAMIENTO' => ['SANDWICH'],
        ]);

        // CREATE COMPANY-SPECIFIC LIMIT RULE (Priority 99 - same as exclusion rule)
        $companyLimitRule = OrderRule::create([
            'name' => 'Límite de productos por subcategoría (Empresa Específica)',
            'rule_type' => 'product_limit_per_subcategory',
            'role_id' => $agreementRole->id,
            'permission_id' => $individualPermission->id,
            'priority' => 99, // Same priority as exclusion rule
            'is_active' => true,
        ]);

        // Associate company with limit rule
        $companyLimitRule->companies()->attach($company->id);

        // Company-specific rule allows 2 ENTRADA products and 2 CALIENTE products (overrides general limit of 1)
        $this->createSubcategoryLimits($companyLimitRule, [
            'PLATO DE FONDO' => 1,
            'ENTRADA' => 2,  // ← Company allows 2 ENTRADA products!
            'CALIENTE' => 2, // ← Both ENTRADA products also have CALIENTE
            'FRIA' => 1,
            'PAN DE ACOMPAÑAMIENTO' => 1,
        ]);

        // Create user
        $user = User::create([
            'name' => 'Test Individual User',
            'nickname' => 'TEST.INDIVIDUAL.USER',
            'email' => 'test.individual@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // Create subcategories
        $entradaSubcat = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $calienteSubcat = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);

        // Create menu for 2025-10-19
        $menu = Menu::create([
            'title' => 'MENU 202 REPLICA',
            'publication_date' => '2025-10-19',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // Category 1: PLATOS VARIABLES PARA CALENTAR (Product 1307 replica)
        $platosCategory = Category::create([
            'name' => 'PLATOS VARIABLES PARA CALENTAR',
            'is_active' => true,
        ]);
        $platosCategory->subcategories()->attach($platoFondoSubcat->id);

        $product1 = Product::create([
            'name' => 'PPC - CERDO AL JUGO ARVEJADO CON ARROZ CASERO',
            'description' => 'Cerdo al jugo con arvejado',
            'code' => 'PPC-1307',
            'category_id' => $platosCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1->id,
            'unit_price' => 5000.00,
            'active' => true,
        ]);

        // Category 2: SOPAS Y CREMAS FIJAS PARA CALENTAR (Product 1057 replica)
        $sopasFijasCategory = Category::create([
            'name' => 'SOPAS Y CREMAS FIJAS PARA CALENTAR',
            'is_active' => true,
        ]);
        $sopasFijasCategory->subcategories()->attach([$entradaSubcat->id, $calienteSubcat->id]);

        $product2 = Product::create([
            'name' => 'ACM - CREMA DE ZAPALLO 300 GR',
            'description' => 'Crema de zapallo caliente',
            'code' => 'ACM-1057',
            'category_id' => $sopasFijasCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product2->id,
            'unit_price' => 3000.00,
            'active' => true,
        ]);

        // Category 3: SOPAS Y CREMAS VARIABLES PARA CALENTAR (Product 1063 replica)
        $sopasVariablesCategory = Category::create([
            'name' => 'SOPAS Y CREMAS VARIABLES PARA CALENTAR',
            'is_active' => true,
        ]);
        $sopasVariablesCategory->subcategories()->attach([$entradaSubcat->id, $calienteSubcat->id]);

        $product3 = Product::create([
            'name' => 'ACM - CREMA DE ESPINACA CON POLLO 300 GR',
            'description' => 'Crema de espinaca con pollo',
            'code' => 'ACM-1063',
            'category_id' => $sopasVariablesCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product3->id,
            'unit_price' => 3500.00,
            'active' => true,
        ]);

        // Create CategoryMenus
        $cm1 = CategoryMenu::create([
            'category_id' => $platosCategory->id,
            'menu_id' => $menu->id,
            'order' => 10,
            'display_order' => 10,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm1->products()->attach($product1->id);

        $cm2 = CategoryMenu::create([
            'category_id' => $sopasFijasCategory->id,
            'menu_id' => $menu->id,
            'order' => 20,
            'display_order' => 20,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm2->products()->attach($product2->id);

        $cm3 = CategoryMenu::create([
            'category_id' => $sopasVariablesCategory->id,
            'menu_id' => $menu->id,
            'order' => 30,
            'display_order' => 30,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm3->products()->attach($product3->id);

        // Authenticate user
        Sanctum::actingAs($user);

        // Step 1: Create order with Product 1 (PLATO DE FONDO) and Product 2 (first ENTRADA)
        $response = $this->postJson("/api/v1/orders/create-or-update-order/2025-10-19", [
            'order_lines' => [
                ['id' => $product1->id, 'quantity' => 1, 'partially_scheduled' => false],
                ['id' => $product2->id, 'quantity' => 1, 'partially_scheduled' => false],
            ],
        ]);

        $response->assertStatus(200);

        // Step 2: Try to add Product 3 (second ENTRADA product)
        // This replicates the exact production API call: POST with only the new product
        $response = $this->postJson("/api/v1/orders/create-or-update-order/2025-10-19", [
            'order_lines' => [
                ['id' => $product3->id, 'quantity' => 1, 'partially_scheduled' => false],
            ],
        ]);

        // EXPECTED: 200 OK (company-specific rule allows multiple ENTRADA)
        // ACTUAL IN PRODUCTION: 422 "Solo puedes elegir un ENTRADA por pedido."
        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Verify order has all 3 products
        $order = Order::where('user_id', $user->id)
            ->whereDate('dispatch_date', '2025-10-19')
            ->first();
        $this->assertNotNull($order);
        $this->assertEquals(3, $order->orderLines->count());

        // Verify we have 2 products with ENTRADA subcategory
        $entradaProducts = $order->orderLines->filter(function ($orderLine) {
            return $orderLine->product->category->subcategories->contains('name', 'ENTRADA');
        });

        $this->assertEquals(2, $entradaProducts->count(), 'Should have 2 ENTRADA products (CREMA DE ZAPALLO + CREMA DE ESPINACA)');
    }
}
