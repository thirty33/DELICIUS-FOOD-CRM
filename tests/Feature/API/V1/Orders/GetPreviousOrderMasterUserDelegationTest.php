<?php

namespace Tests\Feature\API\V1\Orders;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Category;
use App\Models\CategoryLine;
use App\Models\CategoryMenu;
use App\Models\Menu;
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
 * Test: Get Previous Order API - Master User Delegation
 *
 * Validates that master_user can retrieve the previous order
 * of a delegate user via the delegate_user query parameter.
 *
 * API ENDPOINT:
 * GET /api/v1/orders/get-previous-order/{date}?delegate_user={nickname}
 */
class GetPreviousOrderMasterUserDelegationTest extends TestCase
{
    use RefreshDatabase;

    private Role $cafeRole;
    private Permission $consolidadoPermission;
    private PriceList $priceList;
    private Company $company;
    private Branch $branch;
    private Category $category;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-02-01 10:00:00'));

        $this->cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $this->consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        $this->priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'description' => 'Test price list',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $this->company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'fantasy_name' => 'TEST COMPANY',
            'address' => 'Test Address 123',
            'email' => 'test@company.com',
            'phone_number' => '555000111',
            'registration_number' => 'REG-TEST-001',
            'description' => 'Test company',
            'active' => true,
            'tax_id' => '555000111',
            'price_list_id' => $this->priceList->id,
        ]);

        $this->branch = Branch::create([
            'name' => 'TEST BRANCH',
            'address' => 'Test Branch Address 456',
            'company_id' => $this->company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        $this->category = Category::create([
            'name' => 'TEST CATEGORY',
            'description' => 'Test category',
            'active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test product description',
            'code' => 'TEST-PROD-001',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->priceList->id,
            'product_id' => $this->product->id,
            'unit_price' => 500000,
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createUser(array $overrides = []): User
    {
        $defaults = [
            'name' => 'Test User',
            'nickname' => 'TEST.USER',
            'email' => 'test.user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'phone_number' => '555000222',
            'active' => true,
            'master_user' => false,
        ];

        $user = User::create(array_merge($defaults, $overrides));
        $user->roles()->attach($this->cafeRole->id);
        $user->permissions()->attach($this->consolidadoPermission->id);

        return $user;
    }

    private function createOrderForUser(User $user, string $dispatchDate, int $quantity = 1): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::PROCESSED->value,
            'branch_id' => $this->branch->id,
            'dispatch_date' => Carbon::parse($dispatchDate),
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 500000,
        ]);

        return $order;
    }

    /**
     * Master user with delegate_user returns the delegate's previous order
     */
    public function test_master_user_gets_delegate_previous_order(): void
    {
        $masterUser = $this->createUser([
            'name' => 'Master User',
            'nickname' => 'TEST.MASTER',
            'email' => 'master@test.com',
            'master_user' => true,
        ]);

        $delegateUser = $this->createUser([
            'name' => 'Delegate User',
            'nickname' => 'TEST.DELEGATE',
            'email' => 'delegate@test.com',
            'master_user' => false,
        ]);

        // Create orders for both users
        $this->createOrderForUser($masterUser, '2026-01-25', 5);
        $delegateOrder = $this->createOrderForUser($delegateUser, '2026-01-28', 3);

        Sanctum::actingAs($masterUser);

        $response = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01?delegate_user=TEST.DELEGATE');

        $response->assertStatus(200);
        $this->assertEquals($delegateOrder->id, $response->json('data.id'));
        $this->assertEquals(3, $response->json('data.order_lines.0.quantity'));
    }

    /**
     * Master user without delegate_user param returns 422 error
     */
    public function test_master_user_without_delegate_param_returns_error(): void
    {
        $masterUser = $this->createUser([
            'name' => 'Master User',
            'nickname' => 'TEST.MASTER',
            'email' => 'master@test.com',
            'master_user' => true,
        ]);

        $this->createOrderForUser($masterUser, '2026-01-28');

        Sanctum::actingAs($masterUser);

        $response = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01');

        $response->assertStatus(422);
    }

    /**
     * Non-master user trying to use delegate_user returns 422 error
     */
    public function test_non_master_user_cannot_use_delegate_param(): void
    {
        $regularUser = $this->createUser([
            'name' => 'Regular User',
            'nickname' => 'TEST.REGULAR',
            'email' => 'regular@test.com',
            'master_user' => false,
        ]);

        $otherUser = $this->createUser([
            'name' => 'Other User',
            'nickname' => 'TEST.OTHER',
            'email' => 'other@test.com',
        ]);

        $this->createOrderForUser($otherUser, '2026-01-28');

        Sanctum::actingAs($regularUser);

        $response = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01?delegate_user=TEST.OTHER');

        $response->assertStatus(422);
    }

    /**
     * Master user cannot delegate to user in a different company
     */
    public function test_master_user_cannot_delegate_to_different_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'OTHER COMPANY S.A.',
            'fantasy_name' => 'OTHER COMPANY',
            'address' => 'Other Address 789',
            'email' => 'other@company.com',
            'phone_number' => '555000333',
            'registration_number' => 'REG-OTHER-001',
            'description' => 'Other company',
            'active' => true,
            'tax_id' => '555000333',
            'price_list_id' => $this->priceList->id,
        ]);

        $otherBranch = Branch::create([
            'name' => 'OTHER BRANCH',
            'address' => 'Other Branch Address',
            'company_id' => $otherCompany->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        $masterUser = $this->createUser([
            'name' => 'Master User',
            'nickname' => 'TEST.MASTER',
            'email' => 'master@test.com',
            'master_user' => true,
        ]);

        $otherCompanyUser = $this->createUser([
            'name' => 'Other Company User',
            'nickname' => 'TEST.OTHERCO',
            'email' => 'otherco@test.com',
            'company_id' => $otherCompany->id,
            'branch_id' => $otherBranch->id,
        ]);

        $this->createOrderForUser($otherCompanyUser, '2026-01-28');

        Sanctum::actingAs($masterUser);

        $response = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01?delegate_user=TEST.OTHERCO');

        $response->assertStatus(422);
    }

    /**
     * Master user delegation with nonexistent delegate_user returns 422
     */
    public function test_master_user_with_nonexistent_delegate_returns_error(): void
    {
        $masterUser = $this->createUser([
            'name' => 'Master User',
            'nickname' => 'TEST.MASTER',
            'email' => 'master@test.com',
            'master_user' => true,
        ]);

        Sanctum::actingAs($masterUser);

        $response = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01?delegate_user=NONEXISTENT.USER');

        $response->assertStatus(422);
    }

    /**
     * Master user delegation returns 200 with null data when delegate has no previous orders
     */
    public function test_master_user_delegation_returns_null_when_delegate_has_no_orders(): void
    {
        $masterUser = $this->createUser([
            'name' => 'Master User',
            'nickname' => 'TEST.MASTER',
            'email' => 'master@test.com',
            'master_user' => true,
        ]);

        $delegateUser = $this->createUser([
            'name' => 'Delegate User',
            'nickname' => 'TEST.DELEGATE',
            'email' => 'delegate@test.com',
        ]);

        // Master has orders, but delegate does not
        $this->createOrderForUser($masterUser, '2026-01-28');

        Sanctum::actingAs($masterUser);

        $response = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01?delegate_user=TEST.DELEGATE');

        $response->assertStatus(200);
        $this->assertNull($response->json('data'));
    }

    /**
     * Full flow: master gets delegate's previous order, then loads those products
     * via create-or-update-order. The new order must belong to the delegate, not the master.
     *
     * Simulates the frontend flow:
     * 1. GET /api/v1/orders/get-previous-order/{date}?delegate_user=DELEGATE
     * 2. POST /api/v1/orders/create-or-update-order/{date}?delegate_user=DELEGATE
     * 3. Verify the created order belongs to the delegate user
     */
    public function test_master_user_full_flow_get_previous_and_create_order_for_delegate(): void
    {
        // === SETUP: MENU REQUIRED FOR CREATE-OR-UPDATE-ORDER ===
        $menu = Menu::create([
            'title' => 'TEST MENU',
            'description' => 'Test menu',
            'publication_date' => '2026-02-01',
            'max_order_date' => '2026-02-01 23:59:00',
            'role_id' => $this->cafeRole->id,
            'permissions_id' => $this->consolidadoPermission->id,
            'active' => true,
        ]);

        CategoryLine::create([
            'category_id' => $this->category->id,
            'weekday' => 'sunday',
            'preparation_days' => 0,
            'maximum_order_time' => '23:59:00',
            'active' => true,
        ]);

        $categoryMenu = CategoryMenu::create([
            'category_id' => $this->category->id,
            'menu_id' => $menu->id,
            'order' => 1,
            'show_all_products' => true,
        ]);

        $categoryMenu->products()->attach($this->product->id);

        // === SETUP: USERS ===
        $masterUser = $this->createUser([
            'name' => 'Master User',
            'nickname' => 'TEST.MASTER',
            'email' => 'master@test.com',
            'master_user' => true,
        ]);

        $delegateUser = $this->createUser([
            'name' => 'Delegate User',
            'nickname' => 'TEST.DELEGATE',
            'email' => 'delegate@test.com',
            'master_user' => false,
        ]);

        // === SETUP: DELEGATE HAS A PREVIOUS ORDER ===
        $previousOrder = $this->createOrderForUser($delegateUser, '2026-01-28', 3);

        Sanctum::actingAs($masterUser);

        // === STEP 1: GET PREVIOUS ORDER FOR DELEGATE ===
        $getResponse = $this->getJson('/api/v1/orders/get-previous-order/2026-02-01?delegate_user=TEST.DELEGATE');

        $getResponse->assertStatus(200);
        $this->assertEquals($previousOrder->id, $getResponse->json('data.id'));

        $previousOrderLines = $getResponse->json('data.order_lines');
        $this->assertNotEmpty($previousOrderLines);

        // === STEP 2: CREATE ORDER WITH THOSE PRODUCTS FOR DELEGATE ===
        $orderLines = array_map(function ($line) {
            return [
                'id' => $line['product_id'],
                'quantity' => $line['quantity'],
            ];
        }, $previousOrderLines);

        $postResponse = $this->postJson(
            '/api/v1/orders/create-or-update-order/2026-02-01?delegate_user=TEST.DELEGATE',
            ['order_lines' => $orderLines]
        );

        $postResponse->assertStatus(200);

        // === STEP 3: VERIFY ORDER BELONGS TO DELEGATE, NOT MASTER ===
        $createdOrderId = $postResponse->json('data.id');
        $createdOrder = Order::find($createdOrderId);

        $this->assertEquals($delegateUser->id, $createdOrder->user_id,
            'Order must belong to the delegate user, not the master user');
        $this->assertNotEquals($masterUser->id, $createdOrder->user_id,
            'Order must NOT belong to the master user');

        // Verify order lines match what was sent
        $this->assertEquals(
            count($orderLines),
            $createdOrder->orderLines->count(),
            'Order must have the same number of lines as the previous order'
        );

        // Verify the product and quantity match
        $createdLine = $createdOrder->orderLines->first();
        $this->assertEquals($this->product->id, $createdLine->product_id);
        $this->assertEquals(3, $createdLine->quantity);

        // Verify master has NO order for this date
        $masterOrder = Order::where('user_id', $masterUser->id)
            ->where('dispatch_date', '2026-02-01')
            ->first();
        $this->assertNull($masterOrder, 'Master user must NOT have an order for this date');
    }
}