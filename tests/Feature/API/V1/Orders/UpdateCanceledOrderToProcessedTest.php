<?php

namespace Tests\Feature\API\V1\Orders;

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
use App\Models\Role;
use App\Models\Permission;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\CategoryLine;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * TDD Test: Prevent Updating Canceled Order Status to Processed
 *
 * SCENARIO:
 * - Create an order with CANCELED status
 * - Add products to the order
 * - Attempt to change status to PROCESSED via API
 * - Call POST /api/v1/orders/update-order-status/2025-11-01
 * - With payload: {"status": "PROCESSED"}
 *
 * EXPECTED BEHAVIOR (TDD - not yet implemented):
 * - API should return 422 Unprocessable Entity
 * - Response should contain error message
 * - Order status in database should remain CANCELED
 *
 * CURRENT BEHAVIOR (before fix):
 * - API returns 200 OK and allows the status change
 *
 * API ENDPOINT:
 * POST /api/v1/orders/update-order-status/{date}
 * Payload: {"status": "PROCESSED"}
 */
class UpdateCanceledOrderToProcessedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2025-11-01 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test that a canceled order CANNOT be updated to PROCESSED status
     * TDD: This test defines the expected behavior that needs to be implemented
     */
    public function test_canceled_order_cannot_be_updated_to_processed_status(): void
    {
        // === SETUP: CREATE ROLES AND PERMISSIONS ===
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === SETUP: CREATE COMPANY AND PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'description' => 'Test price list for canceled order',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'fantasy_name' => 'TEST COMPANY',
            'address' => 'Test Address 123',
            'email' => 'test@company.com',
            'phone_number' => '555000111',
            'registration_number' => 'REG-TEST-001',
            'description' => 'Test company for canceled order update',
            'active' => true,
            'tax_id' => '555000111',
            'price_list_id' => $priceList->id,
        ]);

        // === SETUP: CREATE BRANCH ===
        $branch = Branch::create([
            'name' => 'TEST BRANCH',
            'address' => 'Test Branch Address 456',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // === SETUP: CREATE USER ===
        $user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.USER',
            'email' => 'test.user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'phone_number' => '555000222',
            'active' => true,
            'validate_min_price' => false,
            'validate_subcategory_rules' => false,
        ]);

        $user->roles()->attach($cafeRole->id);
        $user->permissions()->attach($consolidadoPermission->id);

        // === SETUP: CREATE CATEGORY ===
        $category = Category::create([
            'name' => 'TEST CATEGORY',
            'description' => 'Test category',
            'active' => true,
        ]);

        // === SETUP: CREATE CATEGORY LINE (required for menu availability) ===
        CategoryLine::create([
            'category_id' => $category->id,
            'weekday' => 'saturday', // 2025-11-01 is Saturday
            'preparation_days' => 1,
            'maximum_order_time' => '15:00:00',
            'active' => true,
        ]);

        // === SETUP: CREATE PRODUCTS ===
        $product1 = Product::create([
            'name' => 'Test Product 1',
            'description' => 'Test product 1 description',
            'code' => 'TEST-PROD-001',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $product2 = Product::create([
            'name' => 'Test Product 2',
            'description' => 'Test product 2 description',
            'code' => 'TEST-PROD-002',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // === SETUP: CREATE PRICE LIST LINES ===
        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1->id,
            'unit_price' => 500000, // $5,000.00
            'active' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product2->id,
            'unit_price' => 300000, // $3,000.00
            'active' => true,
        ]);

        // === SETUP: CREATE MENU ===
        $menu = Menu::create([
            'title' => 'Test Menu November 2025',
            'description' => 'Test menu for canceled order update',
            'publication_date' => Carbon::parse('2025-11-01'),
            'max_order_date' => Carbon::parse('2025-11-01 14:00:00'),
            'role_id' => $cafeRole->id,
            'permissions_id' => $consolidadoPermission->id,
            'active' => true,
        ]);

        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => 1,
            'active' => true,
        ]);

        $categoryMenu->products()->attach([$product1->id, $product2->id]);

        // === SETUP: CREATE ORDER WITH CANCELED STATUS ===
        $order = Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::CANCELED->value,
            'branch_id' => $branch->id,
            'dispatch_date' => Carbon::parse('2025-11-01'),
            'total' => 0,
        ]);

        // === SETUP: ADD PRODUCTS TO ORDER ===
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product1->id,
            'quantity' => 2,
            'unit_price' => 500000,
            'total_price' => 1000000,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product2->id,
            'quantity' => 3,
            'unit_price' => 300000,
            'total_price' => 900000,
        ]);

        // === AUTHENTICATE USER ===
        Sanctum::actingAs($user);

        // === ACTION: UPDATE ORDER STATUS TO PROCESSED ===
        $response = $this->postJson('/api/v1/orders/update-order-status/2025-11-01', [
            'status' => 'PROCESSED',
        ]);

        // === ASSERTIONS (TDD - Expected Behavior) ===
        // The API should reject updating a CANCELED order to PROCESSED
        $response->assertStatus(422);

        // Should return an error message
        $response->assertJsonStructure([
            'message',
            'errors',
        ]);

        // Verify order status in database remains CANCELED
        $order->refresh();
        $this->assertEquals(OrderStatus::CANCELED->value, $order->status);
    }
}
