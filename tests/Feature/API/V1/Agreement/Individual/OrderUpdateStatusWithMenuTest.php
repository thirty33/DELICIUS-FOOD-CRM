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

class OrderUpdateStatusWithMenuTest extends BaseIndividualAgreementTest
{

    private User $testUser;
    private Company $testCompany;
    private PriceList $testPriceList;
    private Subcategory $entradaSubcategory;
    private Subcategory $calienteSubcategory;
    private Subcategory $friaSubcategory;
    private Subcategory $platoFondoSubcategory;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time to 2025-10-14 (test creation date)
        Carbon::setTestNow('2025-10-14 00:00:00');

        // Create company
        $this->testCompany = Company::create([
            'name' => 'TEST COMPANY LIMITADA',
            'fantasy_name' => 'TEST COMPANY',
            'address' => 'Test Address 123',
            'email' => 'test@company.com',
            'active' => true,
        ]);

        // Create price list
        $this->testPriceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'active' => true,
        ]);

        // Associate price list to company
        $this->testCompany->update(['price_list_id' => $this->testPriceList->id]);

        // Create branch
        $branch = Branch::create([
            'name' => 'TEST BRANCH',
            'address' => 'Test Branch Address',
            'company_id' => $this->testCompany->id,
            'min_price_order' => 1000.00,
            'active' => true,
        ]);

        // Get Role and Permission (created in BaseIndividualAgreementTest)
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // Create user (emulating agreement individual user like OTERO)
        $this->testUser = User::create([
            'name' => 'TEST USER',
            'nickname' => 'TEST.USER',
            'email' => 'testuser@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $this->testCompany->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,  // Enable subcategory validation like OTERO user
        ]);

        // Attach role and permission to user
        $this->testUser->roles()->attach($agreementRole->id);
        $this->testUser->permissions()->attach($individualPermission->id);

        // Create subcategories
        $this->entradaSubcategory = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $this->calienteSubcategory = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $this->friaSubcategory = Subcategory::firstOrCreate(['name' => 'FRIA']);
        $this->platoFondoSubcategory = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);
    }

    protected function tearDown(): void
    {
        // Release frozen time after each test
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test order update status succeeds when menu has categories with ENTRADA subcategory
     * but no products with prices in pivot (like menu 188)
     *
     * This test emulates the scenario where:
     * - Menu has categories with ENTRADA subcategory
     * - Those categories have products in pivot but without prices
     * - BUG FIX: The validation should now PASS because products without prices shouldn't be required
     *
     * BEFORE BUG FIX: System incorrectly required ENTRADA (returned 422)
     * AFTER BUG FIX: System correctly allows order without ENTRADA (returns 200)
     */
    public function test_order_update_status_succeeds_when_menu_entrada_products_have_no_price(): void
    {
        $date = '2025-10-19';

        // Get role and permission IDs for menu
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // Create menu (emulating menu 188 - that doesn't work)
        $menu = Menu::create([
            'title' => 'TEST MENU WITHOUT ENTRADA PRICES',
            'publication_date' => $date,
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // Create Category 1: SOPAS Y CREMAS (ENTRADA subcategory)
        $category1 = Category::create([
            'name' => 'TEST SOPAS Y CREMAS SIN PRECIO',
            'description' => 'Test category',
            'active' => true,
        ]);
        $category1->subcategories()->attach([
            $this->entradaSubcategory->id,
            $this->calienteSubcategory->id,
        ]);

        // Create Product 1 WITHOUT price for test user's price list
        $product1 = Product::create([
            'name' => 'TEST - CREMA DE ESPINACA',
            'description' => 'Test product description',
            'code' => 'TEST001',
            'category_id' => $category1->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create CategoryMenu for ENTRADA
        $categoryMenu1 = CategoryMenu::create([
            'category_id' => $category1->id,
            'menu_id' => $menu->id,
            'order' => 10,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 10,
            'mandatory_category' => true,
        ]);

        // Attach product to CategoryMenu (but product has no price)
        $categoryMenu1->products()->attach($product1->id);

        // Create Category 2: PLATO DE FONDO (with priced product)
        $category2 = Category::create([
            'name' => 'TEST PLATOS DE FONDO',
            'description' => 'Test category',
            'active' => true,
        ]);
        $category2->subcategories()->attach($this->platoFondoSubcategory->id);

        $product2 = Product::create([
            'name' => 'TEST - POLLO AL HORNO',
            'description' => 'Test product description',
            'code' => 'TEST002',
            'category_id' => $category2->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
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
            'menu_id' => $menu->id,
            'order' => 20,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 20,
            'mandatory_category' => true,
        ]);

        $categoryMenu2->products()->attach($product2->id);

        // Create order with only PLATO DE FONDO product (no ENTRADA)
        $order = Order::create([
            'user_id' => $this->testUser->id,
            'order_date' => Carbon::parse($date),
            'dispatch_date' => Carbon::parse($date),
            'status' => OrderStatus::PENDING->value,
            'total' => 5000.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product2->id,
            'quantity' => 1,
            'unit_price' => 5000.00,
            'total' => 5000.00,
        ]);

        // Authenticate user
        Sanctum::actingAs($this->testUser);

        // Try to update order status to PROCESSED
        $response = $this->postJson("/api/v1/orders/update-order-status/{$date}", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // Assertions - BUG FIX COMPLETE
        // System now correctly returns 200 because ENTRADA products have no prices in pivot
        // Products without prices should NOT be required in the order
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Order status updated successfully',
        ]);
    }
}
