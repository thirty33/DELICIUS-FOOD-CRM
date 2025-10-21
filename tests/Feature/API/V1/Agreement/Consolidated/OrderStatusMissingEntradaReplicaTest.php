<?php

namespace Tests\Feature\API\V1\Agreement\Consolidated;

use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\Menu;
use App\Models\Order;
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
 * Production Replica Test - Order Status Update Missing ENTRADA
 *
 * PRODUCTION DATA (anonymized):
 * - User: TEST.CONSOLIDATED.USER.4 (production user ID: 185, nickname: MBO.MILITARES)
 * - Company: TEST CONSOLIDATED COMPANY 4 S.A. (production company ID: 558)
 * - Menu: production menu ID 328 (date: 2025-10-26)
 * - Order: production order ID 161 (3 products, status: PENDING)
 * - Role: Convenio (ID: 3)
 * - Permission: Consolidado (ID: 1)
 *
 * EXACT PRODUCTION ORDER STRUCTURE:
 * Order 161 has 3 products:
 * 1. PPC - PECHUGA DE POLLO AL CURRY CON ARROZ ARABE
 *    - Category: PLATOS VARIABLES PARA CALENTAR
 *    - Subcategories: PLATO DE FONDO
 *
 * 2. EXT - AMASADO DELICIUS MINI
 *    - Category: ACOMPAÑAMIENTOS
 *    - Subcategories: PAN DE ACOMPAÑAMIENTO
 *
 * 3. PTR - BARRA DE CEREAL
 *    - Category: POSTRES
 *    - Subcategories: [] (NO subcategories)
 *
 * MENU 328 STRUCTURE:
 * - Has categories with ENTRADA subcategory (MINI ENSALADAS, SOPAS FIJAS, SOPAS VARIABLES)
 * - Has categories without subcategories (POSTRES, BEBESTIBLES, OTROS)
 *
 * COMPANY-SPECIFIC ORDER RULE (production Rule ID: 5, Priority: 1):
 * - ENTRADA → ENTRADA (Subcategory → Subcategory)
 * - ENTRADA → POSTRES (Subcategory → Category)
 *
 * CURRENT PRODUCTION BEHAVIOR:
 * - POST /api/v1/orders/update-order-status/2025-10-26 returns 422
 * - Error: "Tu menú necesita algunos elementos para estar completo: Entrada."
 * - Reason: Menu has ENTRADA subcategory but order doesn't have any ENTRADA product
 *
 * EXPECTED BEHAVIOR:
 * - Should return 200 OK
 * - Order has all required elements:
 *   ✓ PLATO DE FONDO (required)
 *   ✓ PAN DE ACOMPAÑAMIENTO (required)
 *   ✓ POSTRES (category without subcategories, required)
 *   ✗ ENTRADA (missing, but menu has it)
 *
 * API ENDPOINT:
 * POST /api/v1/orders/update-order-status/2025-10-26
 * Payload: {"status": "PROCESSED"}
 */
class OrderStatusMissingEntradaReplicaTest extends BaseConsolidatedAgreementTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-10-26 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Override parent to NOT create general rules - this test uses company-specific rules only
     */
    protected function getSubcategoryExclusions(): array
    {
        return []; // No general exclusions
    }

    protected function getSubcategoryLimits(): array
    {
        return []; // No general limits
    }

    public function test_order_status_update_should_succeed_without_entrada(): void
    {
        // 1. GET ROLES AND PERMISSIONS (from parent setUp)
        $role = Role::where('name', RoleName::AGREEMENT->value)->first();
        $permission = Permission::where('name', PermissionName::CONSOLIDADO->value)->first();

        // 2. CREATE COMPANY-SPECIFIC DATA
        $priceList = PriceList::create([
            'name' => 'TEST Price List 4',
            'code' => 'TEST_PL_004',
            'description' => 'Test price list for consolidated company 4',
            'is_active' => true,
        ]);

        $company = Company::create([
            'name' => 'TEST CONSOLIDATED COMPANY 4 S.A.',
            'tax_id' => '33.444.555-6',
            'company_code' => 'TEST_CONS_004',
            'fantasy_name' => 'Test Consolidated 4',
            'address' => 'Test Address 202',
            'email' => 'contact@testconsolidated4.com',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'name' => 'Test Branch 4',
            'address' => 'Test Branch Address 4',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
            'is_main_branch' => true,
        ]);

        // 3. CREATE COMPANY-SPECIFIC RULE (matching production Rule ID 5)
        $companySpecificRule = OrderRule::create([
            'name' => 'Test Company-Specific Exclusion Rules 4',
            'description' => 'ENTRADA exclusions for test company 4',
            'rule_type' => 'subcategory_exclusion',
            'role_id' => $role->id,
            'permission_id' => $permission->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $companySpecificRule->companies()->attach($company->id);

        $this->createPolymorphicExclusions($companySpecificRule, [
            [
                'source_type' => Subcategory::class,
                'source_name' => 'ENTRADA',
                'excluded_type' => Subcategory::class,
                'excluded_name' => 'ENTRADA',
            ],
            [
                'source_type' => Subcategory::class,
                'source_name' => 'ENTRADA',
                'excluded_type' => Category::class,
                'excluded_name' => 'POSTRES',
            ],
        ]);

        // 4. CREATE USER
        $user = User::create([
            'name' => 'Test Consolidated User 4',
            'nickname' => 'TEST.CONSOLIDATED.USER.4',
            'email' => 'test.consolidated4@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($role->id);
        $user->permissions()->attach($permission->id);

        // 5. CREATE SUBCATEGORIES
        $entradaSubcategory = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $friaSubcategory = Subcategory::firstOrCreate(['name' => 'FRIA']);
        $calienteSubcategory = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $platoDeFondoSubcategory = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);
        $panAcompSubcategory = Subcategory::firstOrCreate(['name' => 'PAN DE ACOMPAÑAMIENTO']);

        // 6. CREATE CATEGORIES (matching production menu 328)
        // Categories WITH ENTRADA subcategory
        $miniEnsaladasCategory = Category::create([
            'name' => 'MINI ENSALADAS DE ACOMPAÑAMIENTO',
            'description' => 'Mini salads',
            'is_active' => true,
        ]);
        $miniEnsaladasCategory->subcategories()->attach([
            $entradaSubcategory->id,
            $friaSubcategory->id,
        ]);

        $sopasFijasCategory = Category::create([
            'name' => 'SOPAS Y CREMAS FIJAS PARA CALENTAR',
            'description' => 'Fixed soups',
            'is_active' => true,
        ]);
        $sopasFijasCategory->subcategories()->attach([
            $entradaSubcategory->id,
            $calienteSubcategory->id,
        ]);

        $sopasVariablesCategory = Category::create([
            'name' => 'SOPAS Y CREMAS VARIABLES PARA CALENTAR',
            'description' => 'Variable soups',
            'is_active' => true,
        ]);
        $sopasVariablesCategory->subcategories()->attach([
            $entradaSubcategory->id,
            $calienteSubcategory->id,
        ]);

        // Categories WITHOUT ENTRADA
        $platosVariablesCategory = Category::create([
            'name' => 'PLATOS VARIABLES PARA CALENTAR',
            'description' => 'Variable hot dishes',
            'is_active' => true,
        ]);
        $platosVariablesCategory->subcategories()->attach([
            $platoDeFondoSubcategory->id,
        ]);

        $acompanamientosCategory = Category::create([
            'name' => 'ACOMPAÑAMIENTOS',
            'description' => 'Side dishes',
            'is_active' => true,
        ]);
        $acompanamientosCategory->subcategories()->attach([
            $panAcompSubcategory->id,
        ]);

        // Categories WITHOUT subcategories
        $postresCategory = Category::firstOrCreate(
            ['name' => 'POSTRES'],
            [
                'description' => 'Desserts',
                'is_active' => true,
            ]
        );

        $bebestiblesCategory = Category::firstOrCreate(
            ['name' => 'BEBESTIBLES'],
            [
                'description' => 'Beverages',
                'is_active' => true,
            ]
        );

        $otrosCategory = Category::firstOrCreate(
            ['name' => 'OTROS'],
            [
                'description' => 'Others',
                'is_active' => true,
            ]
        );

        // 7. CREATE PRODUCTS
        $product1 = Product::create([
            'name' => 'Test Hot Dish',
            'description' => 'Test hot dish product',
            'code' => 'TEST_HOT_003',
            'category_id' => $platosVariablesCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1->id,
            'sale_price' => 45000.00,
        ]);

        $product2 = Product::create([
            'name' => 'Test Bread',
            'description' => 'Test bread product',
            'code' => 'TEST_BREAD_003',
            'category_id' => $acompanamientosCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product2->id,
            'sale_price' => 8000.00,
        ]);

        $product3 = Product::create([
            'name' => 'Test Cereal Bar',
            'description' => 'Test cereal bar product',
            'code' => 'TEST_CEREAL_002',
            'category_id' => $postresCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product3->id,
            'sale_price' => 15000.00,
        ]);

        // Create products for categories with ENTRADA (for menu, but not in order)
        $product4 = Product::create([
            'name' => 'Test Mini Salad',
            'description' => 'Test mini salad product',
            'code' => 'TEST_SALAD_002',
            'category_id' => $miniEnsaladasCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product4->id,
            'sale_price' => 25000.00,
        ]);

        // 8. CREATE MENU (including categories with ENTRADA)
        $menu = Menu::create([
            'title' => 'Test Menu 2025-10-26 v3',
            'publication_date' => Carbon::parse('2025-10-26'),
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
            'deadline_time' => '23:59:00',
        ]);

        // Add categories with ENTRADA to menu
        $categoryMenu1 = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $miniEnsaladasCategory->id,
            'is_mandatory' => false,
            'order' => 1,
            'show_all_products' => true,
        ]);
        $categoryMenu1->products()->attach($product4->id);

        // Add categories WITHOUT ENTRADA to menu
        $categoryMenu2 = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $platosVariablesCategory->id,
            'is_mandatory' => false,
            'order' => 2,
            'show_all_products' => true,
        ]);
        $categoryMenu2->products()->attach($product1->id);

        $categoryMenu3 = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $acompanamientosCategory->id,
            'is_mandatory' => false,
            'order' => 3,
            'show_all_products' => true,
        ]);
        $categoryMenu3->products()->attach($product2->id);

        $categoryMenu4 = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $postresCategory->id,
            'is_mandatory' => false,
            'order' => 4,
            'show_all_products' => true,
        ]);
        $categoryMenu4->products()->attach($product3->id);

        // 9. CREATE ORDER (matching production - NO ENTRADA products)
        $order = Order::create([
            'user_id' => $user->id,
            'dispatch_date' => Carbon::parse('2025-10-26'),
            'status' => 'pending',
        ]);

        // Product 1: Hot Dish (PLATO DE FONDO)
        $order->orderLines()->create([
            'product_id' => $product1->id,
            'quantity' => 1,
            'unit_price' => 45000.00,
        ]);

        // Product 2: Bread (PAN DE ACOMPAÑAMIENTO)
        $order->orderLines()->create([
            'product_id' => $product2->id,
            'quantity' => 1,
            'unit_price' => 8000.00,
        ]);

        // Product 3: Cereal Bar (POSTRES)
        $order->orderLines()->create([
            'product_id' => $product3->id,
            'quantity' => 1,
            'unit_price' => 15000.00,
        ]);

        // NOTE: Order does NOT have ENTRADA product, but menu has categories with ENTRADA subcategory

        // 10. AUTHENTICATE USER
        Sanctum::actingAs($user);

        // 11. UPDATE ORDER STATUS TO PROCESSED
        $date = '2025-10-26';
        $response = $this->postJson("/api/v1/orders/update-order-status/{$date}", [
            'status' => 'PROCESSED',
        ]);

        // 12. ASSERTIONS - EXPECT 200 OK
        // Currently production returns 422: "Tu menú necesita algunos elementos para estar completo: Entrada."
        // But this should succeed because ENTRADA is optional (not a required subcategory)
        $response->assertStatus(200);

        // Verify order status was updated
        $order->refresh();
        $this->assertEquals('PROCESSED', $order->status);

        // Verify response structure
        $response->assertJsonStructure([
            'message',
            'data' => [
                'id',
                'dispatch_date',
                'status',
                'order_lines',
            ],
        ]);
    }
}