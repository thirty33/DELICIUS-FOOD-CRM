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
use App\Models\Role;
use App\Models\Permission;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Production Bug Replica Test - Menu 288, Order 116, User KATHERINE.MARTINEZ
 *
 * PRODUCTION DATA (anonymized):
 * - User: TEST.INDIVIDUAL.USER (production: KATHERINE.MARTINEZ, ID: 510)
 * - Company: TEST COMPANY SPA (production: REDDOT CHILE SPA, ID: 586)
 * - Branch: TEST BRANCH (production: CENTRO, ID: 266)
 * - Menu: 288 - CONVENIO INDIVIDUAL NO HORECA 26/10/25
 * - Order: 116 (created: 2025-10-17 09:46:20, date: 2025-10-26)
 * - Price List: ID 10
 *
 * ORDER LINES IN PRODUCTION (Order 116):
 * 1. PPC - PECHUGA DE POLLO AL CURRY CON ARROZ ARABE (Product ID: 1427, Category: PLATOS VARIABLES PARA CALENTAR)
 *    - Subcategories: [PLATO DE FONDO]
 * 2. EXT - AMASADO DELICIUS MINI (Product ID: 1054, Category: ACOMPAÑAMIENTOS)
 *    - Subcategories: [PAN DE ACOMPAÑAMIENTO]
 * 3. PTR - BARRA DE CEREAL (Product ID: 1038, Category: POSTRES)
 *    - Subcategories: []
 * 4. BEB - FANTA 350 ML (Product ID: 1033, Category: BEBESTIBLES)
 *    - Subcategories: []
 * 5. ACM - CREMA DE ZAPALLO 300 GR (Product ID: 1057, Category: SOPAS Y CREMAS FIJAS PARA CALENTAR)
 *    - Subcategories: [ENTRADA, CALIENTE]
 *
 * ORDER RULES IN PRODUCTION (applied to this user):
 *
 * Rule ID 1: Exclusión general de subcategorías
 * - Type: subcategory_exclusion
 * - Priority: 100
 * - Role: Convenio (ID: 3), Permission: Individual (ID: 2)
 * - Companies: 0 (applies to all)
 * - Exclusions:
 *   - PLATO DE FONDO => PLATO DE FONDO
 *   - ENTRADA => ENTRADA
 *   - FRIA => HIPOCALORICO
 *   - PAN DE ACOMPAÑAMIENTO => SANDWICH
 *
 * Rule ID 3: Límite general de productos por subcategoría
 * - Type: product_limit_per_subcategory
 * - Priority: 100
 * - Role: Convenio (ID: 3), Permission: Individual (ID: 2)
 * - Companies: 0 (applies to all)
 * - Limits:
 *   - PLATO DE FONDO => Max: 1
 *   - ENTRADA => Max: 1
 *   - CALIENTE => Max: 1
 *   - FRIA => Max: 1
 *   - PAN DE ACOMPAÑAMIENTO => Max: 1
 *
 * SCENARIO:
 * User KATHERINE.MARTINEZ has a pending order (ID: 116) with date 2025-10-26
 * The order contains 5 order lines with products from different categories
 * User attempts to update order status from PENDING to PROCESSED
 *
 * EXPECTED:
 * API should return 200 OK with success message
 * Order status should be updated to PROCESSED
 *
 * API ENDPOINT:
 * POST /api/v1/orders/update-order-status/2025-10-26
 * Payload: {"status":"PROCESSED"}
 */
class OrderUpdateStatusMenu288Order116ReplicaTest extends BaseIndividualAgreementTest
{
    private User $testUser;
    private Company $testCompany;
    private Branch $testBranch;
    private PriceList $testPriceList;
    private Menu $testMenu;

    // Subcategories
    private Subcategory $entradaSubcategory;
    private Subcategory $calienteSubcategory;
    private Subcategory $friaSubcategory;
    private Subcategory $platoFondoSubcategory;
    private Subcategory $panAcompanamiento;
    private Subcategory $hipocalorico;
    private Subcategory $sandwich;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time to production order creation date
        Carbon::setTestNow('2025-10-17 00:00:00');

        // Create subcategories (matching production)
        $this->entradaSubcategory = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $this->calienteSubcategory = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $this->friaSubcategory = Subcategory::firstOrCreate(['name' => 'FRIA']);
        $this->platoFondoSubcategory = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);
        $this->panAcompanamiento = Subcategory::firstOrCreate(['name' => 'PAN DE ACOMPAÑAMIENTO']);
        $this->hipocalorico = Subcategory::firstOrCreate(['name' => 'HIPOCALORICO']);
        $this->sandwich = Subcategory::firstOrCreate(['name' => 'SANDWICH']);

        // Create price list (matching production: ID 10)
        $this->testPriceList = PriceList::create([
            'name' => 'TEST PRICE LIST 10',
            'active' => true,
        ]);

        // Create company (anonymized from: REDDOT CHILE SPA, ID: 586)
        $this->testCompany = Company::create([
            'name' => 'TEST COMPANY SPA',
            'fantasy_name' => 'TEST COMPANY CENTRO',
            'tax_id' => '12.345.678-9',
            'company_code' => '12.345.678-9CENTR',
            'address' => 'Test Address 123',
            'email' => 'test@testcompany.com',
            'phone' => '123456789',
            'active' => true,
            'price_list_id' => $this->testPriceList->id,
        ]);

        // Create branch (anonymized from: CENTRO, ID: 266)
        $this->testBranch = Branch::create([
            'name' => 'TEST BRANCH CENTRO',
            'address' => 'CENTRO',
            'company_id' => $this->testCompany->id,
            'min_price_order' => 1000.00,
            'active' => true,
        ]);

        // Get Role and Permission (created in BaseIndividualAgreementTest)
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // Create user (anonymized from: KATHERINE.MARTINEZ, ID: 510)
        $this->testUser = User::create([
            'name' => 'TEST INDIVIDUAL USER',
            'nickname' => 'TEST.INDIVIDUAL.USER',
            'email' => 'test.individual@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $this->testCompany->id,
            'branch_id' => $this->testBranch->id,
            'active' => true,
            'validate_subcategory_rules' => true,  // Critical: enable subcategory validation
        ]);

        // Attach role and permission to user
        $this->testUser->roles()->attach($agreementRole->id);
        $this->testUser->permissions()->attach($individualPermission->id);

        // Create menu (matching production: Menu 288)
        $this->testMenu = Menu::create([
            'title' => 'TEST CONVENIO INDIVIDUAL NO HORECA 26/10/25',
            'publication_date' => '2025-10-26',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // Create categories and products matching production menu 288
        $this->createProductionMenuStructure();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Override to match production Rule ID 3: Límite general de productos por subcategoría
     * Includes CALIENTE subcategory limit (missing in base test)
     */
    protected function getSubcategoryLimits(): array
    {
        return [
            'PLATO DE FONDO' => 1,
            'ENTRADA' => 1,
            'CALIENTE' => 1,  // Added to match production
            'FRIA' => 1,
            'PAN DE ACOMPAÑAMIENTO' => 1,
        ];
    }

    /**
     * Creates the exact menu structure from production Menu 288
     * with all categories, products, and relationships
     */
    private function createProductionMenuStructure(): void
    {
        // CATEGORY 0: MINI ENSALADAS DE ACOMPAÑAMIENTO (ID: 87) - HAS FRIA SUBCATEGORY
        $category0 = Category::create([
            'name' => 'MINI ENSALADAS DE ACOMPAÑAMIENTO',
            'description' => 'Mini ensaladas',
            'active' => true,
        ]);
        $category0->subcategories()->attach([
            $this->entradaSubcategory->id,
            $this->friaSubcategory->id,  // CRITICAL: This category has FRIA
        ]);

        // Product with FRIA subcategory and PRICE (available in menu)
        $product0a = Product::create([
            'name' => 'ACM - MINI ENSALADA ACEITUNAS Y HUEVO DURO',
            'description' => 'No description',
            'code' => 'ACM00000099',
            'category_id' => $category0->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0.00,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $product0a->id,
            'unit_price' => 40000.00,  // HAS PRICE - making it required
            'active' => true,
        ]);

        $product0b = Product::create([
            'name' => 'ACM - SIN ENTRADA',
            'description' => 'No description',
            'code' => 'ACM00000115',
            'category_id' => $category0->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0.00,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $product0b->id,
            'unit_price' => 40000.00,  // HAS PRICE
            'active' => true,
        ]);

        $categoryMenu0 = CategoryMenu::create([
            'category_id' => $category0->id,
            'menu_id' => $this->testMenu->id,
            'order' => 1,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 1,
            'mandatory_category' => false,
        ]);
        $categoryMenu0->products()->attach([$product0a->id, $product0b->id]);

        // CATEGORY 1: SOPAS Y CREMAS FIJAS PARA CALENTAR (ID: 80)
        $category1 = Category::create([
            'name' => 'SOPAS Y CREMAS FIJAS PARA CALENTAR',
            'description' => 'Sopas y cremas fijas',
            'active' => true,
        ]);
        $category1->subcategories()->attach([
            $this->entradaSubcategory->id,
            $this->calienteSubcategory->id,
        ]);

        $product1 = Product::create([
            'name' => 'ACM - CREMA DE ZAPALLO 300 GR',
            'description' => 'DELEITATE CON ESTA EXQUISITA CREMA HECHA CON EL MAS RICO ZAPALLO NATURAL CON UNA TEXTURA QUE AMARAS.',
            'code' => 'ACM00000006',
            'category_id' => $category1->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0.00,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $product1->id,
            'unit_price' => 3000.00,
            'active' => true,
        ]);

        $categoryMenu1 = CategoryMenu::create([
            'category_id' => $category1->id,
            'menu_id' => $this->testMenu->id,
            'order' => 2,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 2,
            'mandatory_category' => false,
        ]);
        $categoryMenu1->products()->attach($product1->id);

        // CATEGORY 2: PLATOS VARIABLES PARA CALENTAR (ID: 74)
        $category2 = Category::create([
            'name' => 'PLATOS VARIABLES PARA CALENTAR',
            'description' => 'Platos variables',
            'active' => true,
        ]);
        $category2->subcategories()->attach($this->platoFondoSubcategory->id);

        $product2 = Product::create([
            'name' => 'PPC - PECHUGA DE POLLO AL CURRY CON ARROZ ARABE',
            'description' => 'No description',
            'code' => 'PPC00000164',
            'category_id' => $category2->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0.00,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $product2->id,
            'unit_price' => 5000.00,
            'active' => true,
        ]);

        $categoryMenu2 = CategoryMenu::create([
            'category_id' => $category2->id,
            'menu_id' => $this->testMenu->id,
            'order' => 4,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 4,
            'mandatory_category' => false,
        ]);
        $categoryMenu2->products()->attach($product2->id);

        // CATEGORY 3: ACOMPAÑAMIENTOS (ID: 105)
        $category3 = Category::create([
            'name' => 'ACOMPAÑAMIENTOS',
            'description' => 'Acompañamientos',
            'active' => true,
        ]);
        $category3->subcategories()->attach($this->panAcompanamiento->id);

        $product3 = Product::create([
            'name' => 'EXT - AMASADO DELICIUS MINI',
            'description' => 'No description',
            'code' => 'EXT00000001',
            'category_id' => $category3->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0.00,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $product3->id,
            'unit_price' => 800.00,
            'active' => true,
        ]);

        $categoryMenu3 = CategoryMenu::create([
            'category_id' => $category3->id,
            'menu_id' => $this->testMenu->id,
            'order' => 23,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 23,
            'mandatory_category' => false,
        ]);
        $categoryMenu3->products()->attach($product3->id);

        // CATEGORY 4: POSTRES (ID: 101)
        $category4 = Category::create([
            'name' => 'POSTRES',
            'description' => 'Postres',
            'active' => true,
        ]);
        // No subcategories

        $product4 = Product::create([
            'name' => 'PTR - BARRA DE CEREAL',
            'description' => 'No description',
            'code' => 'PTR00000001',
            'category_id' => $category4->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0.00,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $product4->id,
            'unit_price' => 1200.00,
            'active' => true,
        ]);

        $categoryMenu4 = CategoryMenu::create([
            'category_id' => $category4->id,
            'menu_id' => $this->testMenu->id,
            'order' => 24,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 24,
            'mandatory_category' => false,
        ]);
        $categoryMenu4->products()->attach($product4->id);

        // CATEGORY 5: BEBESTIBLES (ID: 102)
        $category5 = Category::create([
            'name' => 'BEBESTIBLES',
            'description' => 'Bebestibles',
            'active' => true,
        ]);
        // No subcategories

        $product5 = Product::create([
            'name' => 'BEB - FANTA 350 ML',
            'description' => 'No description',
            'code' => 'BEB00000006',
            'category_id' => $category5->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0.00,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $product5->id,
            'unit_price' => 1500.00,
            'active' => true,
        ]);

        $categoryMenu5 = CategoryMenu::create([
            'category_id' => $category5->id,
            'menu_id' => $this->testMenu->id,
            'order' => 25,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 25,
            'mandatory_category' => false,
        ]);
        $categoryMenu5->products()->attach($product5->id);
    }

    /**
     * Test order update status from PENDING to PROCESSED
     * Replicates exact scenario from production Order 116
     */
    public function test_order_update_status_menu_288_order_116_replica(): void
    {
        $orderDate = '2025-10-26';

        // Get products created in menu structure
        $cremaDeZapallo = Product::where('code', 'ACM00000006')->first();
        $pechugarPollo = Product::where('code', 'PPC00000164')->first();
        $amasadoMini = Product::where('code', 'EXT00000001')->first();
        $barraCereal = Product::where('code', 'PTR00000001')->first();
        $fanta = Product::where('code', 'BEB00000006')->first();

        // Create order matching production Order 116
        $order = Order::create([
            'user_id' => $this->testUser->id,
            'branch_id' => $this->testBranch->id,
            'date' => Carbon::parse($orderDate),
            'order_date' => Carbon::parse($orderDate),
            'dispatch_date' => Carbon::parse($orderDate),
            'status' => OrderStatus::PENDING->value,
            'total' => 11500.00,
        ]);

        // Create order lines matching production Order 116
        // Line 1: PPC - PECHUGA DE POLLO AL CURRY CON ARROZ ARABE
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $pechugarPollo->id,
            'quantity' => 1,
            'unit_price' => 5000.00,
            'total' => 5000.00,
        ]);

        // Line 2: EXT - AMASADO DELICIUS MINI
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $amasadoMini->id,
            'quantity' => 1,
            'unit_price' => 800.00,
            'total' => 800.00,
        ]);

        // Line 3: PTR - BARRA DE CEREAL
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $barraCereal->id,
            'quantity' => 1,
            'unit_price' => 1200.00,
            'total' => 1200.00,
        ]);

        // Line 4: BEB - FANTA 350 ML
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $fanta->id,
            'quantity' => 1,
            'unit_price' => 1500.00,
            'total' => 1500.00,
        ]);

        // Line 5: ACM - CREMA DE ZAPALLO 300 GR
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $cremaDeZapallo->id,
            'quantity' => 1,
            'unit_price' => 3000.00,
            'total' => 3000.00,
        ]);

        // Authenticate user
        Sanctum::actingAs($this->testUser);

        // Execute the exact API call from production
        $response = $this->postJson("/api/v1/orders/update-order-status/{$orderDate}", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // Assertions - EXPECT 200 OK (correct behavior)
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Order status updated successfully',
        ]);

        // Verify order status was updated
        $order->refresh();
        $this->assertEquals(OrderStatus::PROCESSED->value, $order->status);

        // Verify all order lines are intact
        $this->assertCount(5, $order->orderLines);
    }
}
