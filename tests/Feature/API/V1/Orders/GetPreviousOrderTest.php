<?php

namespace Tests\Feature\API\V1\Orders;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Category;
use App\Models\Product;
use App\Models\PriceListLine;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Order;
use App\Models\OrderLine;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Test: Get Previous Order API
 *
 * SCENARIO:
 * - User has multiple orders on different dates
 * - User requests the previous order for a specific date
 * - API returns the immediately previous order (closest date before the requested date)
 *
 * API ENDPOINT:
 * GET /api/v1/orders/get-previous-order/{date}
 */
class GetPreviousOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-02-01 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test that the API returns the previous order correctly
     */
    public function test_get_previous_order_returns_correct_order(): void
    {
        // === SETUP: CREATE ROLES AND PERMISSIONS ===
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === SETUP: CREATE COMPANY AND PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'description' => 'Test price list',
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
            'description' => 'Test company',
            'active' => true,
            'tax_id' => '555000111',
            'price_list_id' => $priceList->id,
        ]);

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
        ]);

        $user->roles()->attach($cafeRole->id);
        $user->permissions()->attach($consolidadoPermission->id);

        // === SETUP: CREATE CATEGORY AND PRODUCTS ===
        $category = Category::create([
            'name' => 'TEST CATEGORY',
            'description' => 'Test category',
            'active' => true,
        ]);

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

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1->id,
            'unit_price' => 500000,
            'active' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product2->id,
            'unit_price' => 300000,
            'active' => true,
        ]);

        // === SETUP: CREATE MULTIPLE ORDERS ON DIFFERENT DATES ===
        // Order 1: 2026-01-25 (oldest)
        $order1 = Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::PROCESSED->value,
            'branch_id' => $branch->id,
            'dispatch_date' => Carbon::parse('2026-01-25'),
        ]);

        OrderLine::create([
            'order_id' => $order1->id,
            'product_id' => $product1->id,
            'quantity' => 1,
            'unit_price' => 500000,
        ]);

        // Order 2: 2026-01-28 (middle - should be returned)
        $order2 = Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::PROCESSED->value,
            'branch_id' => $branch->id,
            'dispatch_date' => Carbon::parse('2026-01-28'),
        ]);

        OrderLine::create([
            'order_id' => $order2->id,
            'product_id' => $product2->id,
            'quantity' => 2,
            'unit_price' => 300000,
        ]);

        // Order 3: 2026-02-01 (current date - not previous)
        $order3 = Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::PENDING->value,
            'branch_id' => $branch->id,
            'dispatch_date' => Carbon::parse('2026-02-01'),
        ]);

        // === AUTHENTICATE USER ===
        Sanctum::actingAs($user);

        // === ACTION: GET PREVIOUS ORDER FOR 2026-02-01 ===
        $response = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01');

        // === ASSERTIONS ===
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'data' => [
                'id',
                'total',
                'status',
                'dispatch_date',
                'order_lines',
            ],
        ]);

        // Should return order from 2026-01-28 (immediately previous)
        $this->assertEquals($order2->id, $response->json('data.id'));
    }

    /**
     * Test that the API returns 200 with null data when no previous order exists
     */
    public function test_get_previous_order_returns_null_when_no_previous_order(): void
    {
        // === SETUP ===
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        $priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'description' => 'Test price list',
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
            'description' => 'Test company',
            'active' => true,
            'tax_id' => '555000111',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'name' => 'TEST BRANCH',
            'address' => 'Test Branch Address 456',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        $user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.USER',
            'email' => 'test.user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'phone_number' => '555000222',
            'active' => true,
        ]);

        $user->roles()->attach($cafeRole->id);
        $user->permissions()->attach($consolidadoPermission->id);

        Sanctum::actingAs($user);

        // === ACTION: GET PREVIOUS ORDER WHEN USER HAS NO ORDERS ===
        $response = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01');

        // === ASSERTIONS ===
        $response->assertStatus(200);
        $this->assertNull($response->json('data'));
    }

    /**
     * Test that invalid date format returns validation error
     */
    public function test_get_previous_order_validates_date_format(): void
    {
        // === SETUP ===
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        $priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'description' => 'Test price list',
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
            'description' => 'Test company',
            'active' => true,
            'tax_id' => '555000111',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'name' => 'TEST BRANCH',
            'address' => 'Test Branch Address 456',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        $user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.USER',
            'email' => 'test.user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'phone_number' => '555000222',
            'active' => true,
        ]);

        $user->roles()->attach($cafeRole->id);
        $user->permissions()->attach($consolidadoPermission->id);

        Sanctum::actingAs($user);

        // === ACTION: GET PREVIOUS ORDER WITH INVALID DATE FORMAT ===
        $response = $this->getJson('/api/v1/orders/get-previous-order/invalid-date');

        // === ASSERTIONS ===
        $response->assertStatus(422);
    }

    /**
     * Test that unauthenticated request returns 401
     */
    public function test_get_previous_order_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01');

        $response->assertStatus(401);
    }

    /**
     * Test that the response includes order lines with products
     */
    public function test_get_previous_order_includes_order_lines_with_products(): void
    {
        // === SETUP ===
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        $priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'description' => 'Test price list',
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
            'description' => 'Test company',
            'active' => true,
            'tax_id' => '555000111',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'name' => 'TEST BRANCH',
            'address' => 'Test Branch Address 456',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        $user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.USER',
            'email' => 'test.user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'phone_number' => '555000222',
            'active' => true,
        ]);

        $user->roles()->attach($cafeRole->id);
        $user->permissions()->attach($consolidadoPermission->id);

        $category = Category::create([
            'name' => 'TEST CATEGORY',
            'description' => 'Test category',
            'active' => true,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test product description',
            'code' => 'TEST-PROD-001',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 500000,
            'active' => true,
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::PROCESSED->value,
            'branch_id' => $branch->id,
            'dispatch_date' => Carbon::parse('2026-01-28'),
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => 500000,
        ]);

        Sanctum::actingAs($user);

        // === ACTION ===
        $response = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01');

        // === ASSERTIONS ===
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'order_lines' => [
                    '*' => [
                        'id',
                        'quantity',
                        'product_id',
                        'product' => [
                            'id',
                            'name',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $response->json('data.order_lines'));
        $this->assertEquals($product->id, $response->json('data.order_lines.0.product.id'));
        $this->assertEquals(3, $response->json('data.order_lines.0.quantity'));
    }
}