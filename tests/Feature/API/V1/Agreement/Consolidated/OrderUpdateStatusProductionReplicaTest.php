<?php

namespace Tests\Feature\API\V1\Agreement\Consolidated;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Menu;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Product;
use App\Models\PriceListLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Subcategory;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Production Bug Replica Test - Consolidated Agreement Order Update Status
 *
 * This test replicates EXACTLY a production scenario for order status updates:
 *
 * PRODUCTION DATA (anonymized):
 * - User: MASTER.CONSOLIDATED.USER (production user ID: 380)
 * - Company: TEST CONSOLIDATED COMPANY (production company ID: 594)
 * - Role: Convenio (Agreement), Permission: Consolidado (Consolidated)
 * - Order: production order ID 109
 * - Menu: production menu ID 308
 * - Dispatch Date: 2025-10-20
 *
 * ORDER DETAILS (4 products, 20 total items):
 * - Product 1: Sandwich type A (qty: 5) - Category: SANDWICH INTEGRAL (Subcategory: PLATO DE FONDO)
 * - Product 2: Sandwich type B (qty: 5) - Category: SANDWICH BLANCO (Subcategory: PLATO DE FONDO)
 * - Product 3: Juice boxes (qty: 6) - Category: BEVERAGES (no subcategory)
 * - Product 4: Soda bottles (qty: 4) - Category: BEVERAGES (no subcategory)
 *
 * QUANTITY VALIDATION (grouped by subcategory):
 * - PLATO DE FONDO group: 5 + 5 = 10 units (both sandwich categories)
 * - BEVERAGES category: 6 + 4 = 10 units
 * - Total: 10 = 10 ✓ (validation should pass)
 *
 * API ENDPOINT:
 * POST /api/v1/orders/update-order-status/2025-10-20
 * Payload: {"status":"PROCESSED"}
 *
 * EXPECTED:
 * - Should return 200 OK
 * - Order status should change from PENDING to PROCESSED
 * - All order lines should remain intact
 */
class OrderUpdateStatusProductionReplicaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-10-20 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_consolidated_agreement_order_should_update_status_to_processed(): void
    {
        // === CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $consolidatedPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === CREATE COMPANY AND PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'Test Consolidated Price List',
            'active' => true,
        ]);

        $company = Company::create([
            'name' => 'TEST CONSOLIDATED COMPANY S.A.',
            'fantasy_name' => 'TEST CONS COLD MEALS',
            'tax_id' => '12.345.678-9',
            'company_code' => '12.345.678-9COLD',
            'address' => 'Test Address 123',
            'email' => 'test@testcompany.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'name' => 'TEST CONSOLIDATED BRANCH',
            'address' => 'Branch Address 456',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // === CREATE USER (Convenio Consolidado) ===
        $user = User::create([
            'name' => 'Test Consolidated User',
            'nickname' => 'TEST.CONSOLIDATED.USER',
            'email' => 'test.consolidated@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true, // Enable subcategory grouping validation
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($consolidatedPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONSOLIDATED COLD MEAL MENU 20/10/25',
            'publication_date' => '2025-10-20',
            'role_id' => $agreementRole->id,
            'permissions_id' => $consolidatedPermission->id,
            'active' => true,
        ]);

        // === CREATE SUBCATEGORIES ===
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);
        $sandwichSubcat = Subcategory::firstOrCreate(['name' => 'SANDWICH']);

        // === CREATE CATEGORIES ===
        $categorySandwichIntegral = Category::create([
            'name' => 'SANDWICH INTEGRAL',
            'is_active' => true,
        ]);
        // Both sandwich categories have PLATO DE FONDO subcategory (they group together)
        $categorySandwichIntegral->subcategories()->attach([$platoFondoSubcat->id, $sandwichSubcat->id]);

        $categorySandwichBlanco = Category::create([
            'name' => 'SANDWICH BLANCO',
            'is_active' => true,
        ]);
        $categorySandwichBlanco->subcategories()->attach([$platoFondoSubcat->id, $sandwichSubcat->id]);

        $categoryBeverages = Category::create([
            'name' => 'BEVERAGES',
            'is_active' => true,
        ]);
        // BEVERAGES has no subcategories (remains separate group)

        // === CREATE PRODUCTS ===
        $product1 = Product::create([
            'name' => 'SND - Integral Ham & Cheese Sandwich',
            'description' => 'Whole wheat ham and cheese sandwich',
            'code' => 'SND-INT-001',
            'category_id' => $categorySandwichIntegral->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1->id,
            'unit_price' => 1500.00,
            'active' => true,
        ]);

        $product2 = Product::create([
            'name' => 'SND - White Bread Egg Sandwich',
            'description' => 'White bread egg sandwich',
            'code' => 'SND-WHT-002',
            'category_id' => $categorySandwichBlanco->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product2->id,
            'unit_price' => 1400.00,
            'active' => true,
        ]);

        $product3 = Product::create([
            'name' => 'BEV - Assorted Juice Boxes 220 ML',
            'description' => 'Assorted fruit juice boxes',
            'code' => 'BEV-JCE-003',
            'category_id' => $categoryBeverages->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product3->id,
            'unit_price' => 800.00,
            'active' => true,
        ]);

        $product4 = Product::create([
            'name' => 'BEV - Zero Sugar Soda 350 ML',
            'description' => 'Zero sugar soda bottle',
            'code' => 'BEV-SDA-004',
            'category_id' => $categoryBeverages->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product4->id,
            'unit_price' => 1000.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENUS ===
        $cm1 = CategoryMenu::create([
            'category_id' => $categorySandwichIntegral->id,
            'menu_id' => $menu->id,
            'order' => 10,
            'display_order' => 10,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm1->products()->attach($product1->id);

        $cm2 = CategoryMenu::create([
            'category_id' => $categorySandwichBlanco->id,
            'menu_id' => $menu->id,
            'order' => 20,
            'display_order' => 20,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm2->products()->attach($product2->id);

        $cm3 = CategoryMenu::create([
            'category_id' => $categoryBeverages->id,
            'menu_id' => $menu->id,
            'order' => 30,
            'display_order' => 30,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm3->products()->attach([$product3->id, $product4->id]);

        // === CREATE ORDER (Replicating production order 109) ===
        $order = Order::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'dispatch_date' => '2025-10-20',
            'status' => 'PENDING',
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product1->id,
            'quantity' => 5,
            'unit_price' => 1500.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product2->id,
            'quantity' => 5,
            'unit_price' => 1400.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product3->id,
            'quantity' => 6,
            'unit_price' => 800.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product4->id,
            'quantity' => 4,
            'unit_price' => 1000.00,
        ]);

        // === AUTHENTICATE USER ===
        Sanctum::actingAs($user);

        // === TEST: Update order status to PROCESSED ===
        $response = $this->postJson('/api/v1/orders/update-order-status/2025-10-20', [
            'status' => 'PROCESSED',
        ]);

        // === ASSERTIONS ===
        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Verify order status was updated
        $order->refresh();
        $this->assertEquals('PROCESSED', $order->status);

        // Verify all order lines are still present
        $this->assertEquals(4, $order->orderLines->count());

        // Verify total quantities match production
        $totalQuantity = $order->orderLines->sum('quantity');
        $this->assertEquals(20, $totalQuantity, 'Total quantity should be 20 items');
    }
}
