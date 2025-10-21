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
 * Production Replica Test - Order Status Update Success
 *
 * PRODUCTION DATA (anonymized):
 * - User: TEST.CONSOLIDATED.USER.2 (production user ID: 185, nickname: MBO.MILITARES)
 * - Company: TEST CONSOLIDATED COMPANY 2 S.A. (production company ID: 558)
 * - Menu: production menu ID 328 (date: 2025-10-26, 26 category menus)
 * - Order: production order ID 161 (3 products, status: PENDING)
 * - Role: Convenio (ID: 3)
 * - Permission: Consolidado (ID: 1)
 *
 * EXACT PRODUCTION ORDER STRUCTURE:
 * Order 161 has 3 products:
 * 1. ACM - MINI ENSALADA ACEITUNAS Y HUEVO DURO
 *    - Category: MINI ENSALADAS DE ACOMPAÑAMIENTO (ID: 87)
 *    - Subcategories: ENTRADA, FRIA
 *    - Quantity: 1, Unit Price: 50000
 *
 * 2. PPC - PECHUGA DE POLLO AL CURRY CON ARROZ ARABE
 *    - Category: PLATOS VARIABLES PARA CALENTAR (ID: 74)
 *    - Subcategories: PLATO DE FONDO
 *    - Quantity: 1, Unit Price: 385000
 *
 * 3. EXT - AMASADO DELICIUS MINI
 *    - Category: ACOMPAÑAMIENTOS (ID: 105)
 *    - Subcategories: PAN DE ACOMPAÑAMIENTO
 *    - Quantity: 1, Unit Price: 10000
 *
 * COMPANY-SPECIFIC ORDER RULE (production Rule ID: 5, Priority: 1):
 * - ENTRADA → ENTRADA (Subcategory → Subcategory)
 * - ENTRADA → POSTRES (Subcategory → Category) - Polymorphic exclusion
 *
 * EXPECTED BEHAVIOR:
 * - POST /api/v1/orders/update-order-status/2025-10-26 with status=PROCESSED should return 200 OK
 * - Order should be updated to PROCESSED status
 * - No validation errors (products don't violate exclusion rules - no POSTRES in order)
 *
 * API ENDPOINT:
 * POST /api/v1/orders/update-order-status/2025-10-26
 * Payload: {"status": "PROCESSED"}
 */
class OrderStatusUpdateSuccessReplicaTest extends BaseConsolidatedAgreementTest
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

    public function test_order_status_update_should_succeed_with_valid_products(): void
    {
        // 1. GET ROLES AND PERMISSIONS (from parent setUp)
        $role = Role::where('name', RoleName::AGREEMENT->value)->first();
        $permission = Permission::where('name', PermissionName::CONSOLIDADO->value)->first();

        // 2. CREATE COMPANY-SPECIFIC DATA
        $priceList = PriceList::create([
            'name' => 'TEST Price List 2',
            'code' => 'TEST_PL_002',
            'description' => 'Test price list for consolidated company 2',
            'is_active' => true,
        ]);

        $company = Company::create([
            'name' => 'TEST CONSOLIDATED COMPANY 2 S.A.',
            'tax_id' => '11.222.333-4',
            'company_code' => 'TEST_CONS_002',
            'fantasy_name' => 'Test Consolidated 2',
            'address' => 'Test Address 789',
            'email' => 'contact@testconsolidated2.com',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'name' => 'Test Branch 2',
            'address' => 'Test Branch Address 2',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
            'is_main_branch' => true,
        ]);

        // 3. CREATE COMPANY-SPECIFIC RULE (matching production Rule ID 5)
        $companySpecificRule = OrderRule::create([
            'name' => 'Test Company-Specific Exclusion Rules',
            'description' => 'ENTRADA exclusions for test company 2',
            'rule_type' => 'subcategory_exclusion',
            'role_id' => $role->id,
            'permission_id' => $permission->id,
            'priority' => 1, // Highest priority (matches production)
            'is_active' => true,
        ]);

        // Associate rule with company
        $companySpecificRule->companies()->attach($company->id);

        // Create BOTH polymorphic exclusions from production
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
            'name' => 'Test Consolidated User 2',
            'nickname' => 'TEST.CONSOLIDATED.USER.2',
            'email' => 'test.consolidated2@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'validate_subcategory_rules' => true, // IMPORTANT: Enable validation
        ]);

        $user->roles()->attach($role->id);
        $user->permissions()->attach($permission->id);

        // 5. CREATE SUBCATEGORIES
        $entradaSubcategory = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $friaSubcategory = Subcategory::firstOrCreate(['name' => 'FRIA']);
        $platoDeFondoSubcategory = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);
        $panAcompSubcategory = Subcategory::firstOrCreate(['name' => 'PAN DE ACOMPAÑAMIENTO']);

        // 6. CREATE CATEGORIES
        $miniEnsaladasCategory = Category::create([
            'name' => 'MINI ENSALADAS DE ACOMPAÑAMIENTO',
            'description' => 'Mini salads',
            'is_active' => true,
        ]);
        $miniEnsaladasCategory->subcategories()->attach([
            $entradaSubcategory->id,
            $friaSubcategory->id,
        ]);

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

        // POSTRES category (NO subcategories - this is required by AtLeastOneProductByCategory validator)
        $postresCategory = Category::firstOrCreate(
            ['name' => 'POSTRES'],
            [
                'description' => 'Desserts',
                'is_active' => true,
            ]
        );
        // POSTRES has NO subcategories attached

        // 7. CREATE PRODUCTS
        $product1 = Product::create([
            'name' => 'Test Mini Salad',
            'description' => 'Test mini salad product',
            'code' => 'TEST_SALAD_001',
            'category_id' => $miniEnsaladasCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1->id,
            'sale_price' => 25000.00,
        ]);

        $product2 = Product::create([
            'name' => 'Test Hot Dish',
            'description' => 'Test hot dish product',
            'code' => 'TEST_HOT_001',
            'category_id' => $platosVariablesCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product2->id,
            'sale_price' => 45000.00,
        ]);

        $product3 = Product::create([
            'name' => 'Test Bread',
            'description' => 'Test bread product',
            'code' => 'TEST_BREAD_001',
            'category_id' => $acompanamientosCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product3->id,
            'sale_price' => 8000.00,
        ]);

        // Product 4: POSTRES - Required by validator (category without subcategories)
        $product4 = Product::create([
            'name' => 'Test Dessert',
            'description' => 'Test dessert product',
            'code' => 'TEST_DESSERT_001',
            'category_id' => $postresCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product4->id,
            'sale_price' => 15000.00,
        ]);

        // 8. CREATE MENU
        $menu = Menu::create([
            'title' => 'Test Menu 2025-10-26',
            'publication_date' => Carbon::parse('2025-10-26'),
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
            'deadline_time' => '23:59:00',
        ]);

        // Create CategoryMenus
        $categoryMenu1 = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $miniEnsaladasCategory->id,
            'is_mandatory' => false,
            'order' => 1,
            'show_all_products' => true,
        ]);
        $categoryMenu1->products()->attach($product1->id);

        $categoryMenu2 = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $platosVariablesCategory->id,
            'is_mandatory' => false,
            'order' => 2,
            'show_all_products' => true,
        ]);
        $categoryMenu2->products()->attach($product2->id);

        $categoryMenu3 = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $acompanamientosCategory->id,
            'is_mandatory' => false,
            'order' => 3,
            'show_all_products' => true,
        ]);
        $categoryMenu3->products()->attach($product3->id);

        // Add POSTRES category to menu (required by AtLeastOneProductByCategory validator)
        $categoryMenu4 = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $postresCategory->id,
            'is_mandatory' => false,
            'order' => 4,
            'show_all_products' => true,
        ]);
        $categoryMenu4->products()->attach($product4->id);

        // 9. CREATE ORDER WITH ORDER LINES (matching production order 161 - 3 products only)
        $order = Order::create([
            'user_id' => $user->id,
            'dispatch_date' => Carbon::parse('2025-10-26'),
            'status' => 'pending',
        ]);

        // Product 1: Mini Salad (ENTRADA, FRIA)
        $order->orderLines()->create([
            'product_id' => $product1->id,
            'quantity' => 1,
            'unit_price' => 25000.00,
        ]);

        // Product 2: Hot Dish (PLATO DE FONDO)
        $order->orderLines()->create([
            'product_id' => $product2->id,
            'quantity' => 1,
            'unit_price' => 45000.00,
        ]);

        // Product 3: Bread (PAN DE ACOMPAÑAMIENTO)
        $order->orderLines()->create([
            'product_id' => $product3->id,
            'quantity' => 1,
            'unit_price' => 8000.00,
        ]);

        // NOTE: Production order does NOT have POSTRES product (only 3 products total)

        // 10. AUTHENTICATE USER
        Sanctum::actingAs($user);

        // 11. UPDATE ORDER STATUS TO PROCESSED
        $date = '2025-10-26';
        $response = $this->postJson("/api/v1/orders/update-order-status/{$date}", [
            'status' => 'PROCESSED',
        ]);

        // 12. ASSERTIONS - EXPECT 200 OK (order should be processed successfully)
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