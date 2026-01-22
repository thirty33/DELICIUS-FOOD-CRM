<?php

namespace Tests\Feature\Commands;

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
use App\Models\Parameter;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Jobs\OrderCategoryMenuProductsByBestSellingJob;
use Carbon\Carbon;

/**
 * Integration Test for OrderCategoryMenuProductsCommand
 *
 * Tests:
 * 1. Command only processes Cafe menus with products_ordered = false
 * 2. Command excludes past menus (publication_date < today)
 * 3. Command respects the limit option
 * 4. Job orders products correctly based on sales data
 * 5. Job marks menu as products_ordered = true
 * 6. Command does not reprocess menus already ordered
 */
class OrderCategoryMenuProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Role $cafeRole;
    protected Role $otherRole;
    protected Permission $consolidadoPermission;
    protected Company $company;
    protected PriceList $priceList;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $this->cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $this->otherRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $this->consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // Create company and price list
        $this->priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'description' => 'Test price list',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $this->company = Company::create([
            'name' => 'TEST COMPANY',
            'fantasy_name' => 'TEST',
            'address' => 'Test Address 123',
            'email' => 'test@test.com',
            'phone_number' => '123456789',
            'registration_number' => 'REG-001',
            'description' => 'Test company',
            'active' => true,
            'tax_id' => '123456789',
            'price_list_id' => $this->priceList->id,
        ]);

        $this->branch = Branch::create([
            'name' => 'TEST BRANCH',
            'address' => 'Test Branch Address',
            'company_id' => $this->company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // Ensure parameters exist
        $this->createParameters();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Create the required parameters for the command
     */
    protected function createParameters(): void
    {
        Parameter::firstOrCreate(
            ['name' => Parameter::BEST_SELLING_CATEGORY_AUTO_GENERATE],
            [
                'description' => 'Auto generate best selling category',
                'value_type' => 'boolean',
                'value' => '1',
                'active' => true,
            ]
        );

        Parameter::firstOrCreate(
            ['name' => Parameter::BEST_SELLING_CATEGORY_DATE_RANGE_DAYS],
            [
                'description' => 'Date range days',
                'value_type' => 'integer',
                'value' => '30',
                'active' => true,
            ]
        );
    }

    /**
     * Create a Cafe user with orders
     */
    protected function createCafeUser(): User
    {
        $user = User::create([
            'name' => 'TEST CAFE USER',
            'nickname' => 'TEST.CAFE',
            'email' => 'testcafe@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'active' => true,
        ]);

        $user->roles()->attach($this->cafeRole->id);
        $user->permissions()->attach($this->consolidadoPermission->id);

        return $user;
    }

    /**
     * Create a category with products
     */
    protected function createCategoryWithProducts(string $categoryName, int $productCount = 3, bool $isDynamic = false): array
    {
        $category = Category::create([
            'name' => $categoryName,
            'description' => "Test category: {$categoryName}",
            'is_active' => true,
            'is_dynamic' => $isDynamic,
        ]);

        $products = [];
        for ($i = 1; $i <= $productCount; $i++) {
            $product = Product::create([
                'name' => "{$categoryName} - Product {$i}",
                'code' => strtoupper(substr($categoryName, 0, 3)) . "-{$i}-" . uniqid(),
                'description' => "Test product {$i} from {$categoryName}",
                'category_id' => $category->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);

            PriceListLine::create([
                'price_list_id' => $this->priceList->id,
                'product_id' => $product->id,
                'unit_price' => 100000 * $i,
                'active' => true,
            ]);

            $products[] = $product;
        }

        return ['category' => $category, 'products' => $products];
    }

    /**
     * Create a Cafe menu with products_ordered setting
     */
    protected function createCafeMenu(string $publicationDate, string $title = null, bool $productsOrdered = false): Menu
    {
        return Menu::create([
            'title' => $title ?? "TEST CAFE MENU {$publicationDate}",
            'description' => null,
            'publication_date' => $publicationDate,
            'max_order_date' => Carbon::parse($publicationDate)->subDay()->setTime(15, 30)->format('Y-m-d H:i:s'),
            'role_id' => $this->cafeRole->id,
            'permissions_id' => $this->consolidadoPermission->id,
            'active' => true,
            'products_ordered' => $productsOrdered,
        ]);
    }

    /**
     * Create orders for a user to simulate sales
     */
    protected function createOrders(User $user, array $productQuantities, string $orderDate): void
    {
        $order = Order::create([
            'user_id' => $user->id,
            'dispatch_date' => Carbon::parse($orderDate)->addDay()->format('Y-m-d'),
            'status' => OrderStatus::PROCESSED->value,
            'total' => 0,
        ]);

        $order->created_at = Carbon::parse($orderDate);
        $order->saveQuietly();

        $total = 0;
        foreach ($productQuantities as $productId => $quantity) {
            $priceListLine = PriceListLine::where('product_id', $productId)->first();

            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $priceListLine->unit_price,
                'partially_scheduled' => false,
            ]);

            $total += $priceListLine->unit_price * $quantity;
        }

        $order->update(['total' => $total]);
    }

    // =========================================================================
    // TEST 1: Command only processes Cafe menus with products_ordered = false
    // =========================================================================

    public function test_command_only_processes_menus_with_products_ordered_false(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create menu with products_ordered = false (should be processed)
        $menuToProcess = $this->createCafeMenu('2026-01-23', 'MENU TO PROCESS', false);

        // Create menu with products_ordered = true (should NOT be processed)
        $menuAlreadyOrdered = $this->createCafeMenu('2026-01-24', 'MENU ALREADY ORDERED', true);

        $this->artisan('menus:order-category-products')
            ->assertSuccessful()
            ->expectsOutput('Found 1 Cafe menus to process.')
            ->expectsOutput('Dispatched 1 jobs successfully.');

        Queue::assertPushed(OrderCategoryMenuProductsByBestSellingJob::class, 1);

        Queue::assertPushed(OrderCategoryMenuProductsByBestSellingJob::class, function ($job) use ($menuToProcess) {
            return $this->getJobMenuId($job) === $menuToProcess->id;
        });

        Queue::assertNotPushed(OrderCategoryMenuProductsByBestSellingJob::class, function ($job) use ($menuAlreadyOrdered) {
            return $this->getJobMenuId($job) === $menuAlreadyOrdered->id;
        });
    }

    // =========================================================================
    // TEST 2: Command excludes past menus
    // =========================================================================

    public function test_command_excludes_past_menus(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create past menu (should NOT be processed)
        $pastMenu = $this->createCafeMenu('2026-01-20', 'PAST MENU', false);

        // Create today menu (should be processed)
        $todayMenu = $this->createCafeMenu('2026-01-22', 'TODAY MENU', false);

        // Create future menu (should be processed)
        $futureMenu = $this->createCafeMenu('2026-01-25', 'FUTURE MENU', false);

        $this->artisan('menus:order-category-products')
            ->assertSuccessful()
            ->expectsOutput('Found 2 Cafe menus to process.')
            ->expectsOutput('Dispatched 2 jobs successfully.');

        Queue::assertPushed(OrderCategoryMenuProductsByBestSellingJob::class, 2);

        Queue::assertNotPushed(OrderCategoryMenuProductsByBestSellingJob::class, function ($job) use ($pastMenu) {
            return $this->getJobMenuId($job) === $pastMenu->id;
        });
    }

    // =========================================================================
    // TEST 3: Command respects the limit option
    // =========================================================================

    public function test_command_respects_limit_option(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create 8 future menus
        for ($i = 0; $i < 8; $i++) {
            $date = Carbon::parse('2026-01-22')->addDays($i)->format('Y-m-d');
            $this->createCafeMenu($date, "MENU {$i}", false);
        }

        // Run with default limit (5)
        $this->artisan('menus:order-category-products')
            ->assertSuccessful()
            ->expectsOutput('Found 5 Cafe menus to process.')
            ->expectsOutput('Dispatched 5 jobs successfully.');

        Queue::assertPushed(OrderCategoryMenuProductsByBestSellingJob::class, 5);
    }

    // =========================================================================
    // TEST 4: Job orders products correctly based on sales data
    // =========================================================================

    public function test_job_orders_products_by_sales(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create category with 3 products
        $data = $this->createCategoryWithProducts('SANDWICHES', 3);
        $category = $data['category'];
        $products = $data['products'];

        // Create Cafe user and orders
        $user = $this->createCafeUser();

        // Create sales: Product 3 = 50, Product 1 = 30, Product 2 = 10
        $this->createOrders($user, [
            $products[2]->id => 50, // Most sold
            $products[0]->id => 30, // Second
            $products[1]->id => 10, // Third
        ], '2026-01-15');

        // Create menu and category_menu with products
        $menu = $this->createCafeMenu('2026-01-23', 'TEST MENU', false);

        $categoryMenu = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $category->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        // Attach products with initial order (1, 2, 3)
        $categoryMenu->products()->attach([
            $products[0]->id => ['display_order' => 1],
            $products[1]->id => ['display_order' => 2],
            $products[2]->id => ['display_order' => 3],
        ]);

        // Run command (jobs run synchronously in tests)
        $this->artisan('menus:order-category-products')
            ->assertSuccessful();

        // Verify products are now ordered by sales ascending (least sold first): 2, 1, 3
        $orderedProducts = $categoryMenu->products()
            ->orderBy('category_menu_product.display_order')
            ->get();

        $this->assertEquals($products[1]->id, $orderedProducts[0]->id, 'Product 2 should be first (10 sales - least sold)');
        $this->assertEquals($products[0]->id, $orderedProducts[1]->id, 'Product 1 should be second (30 sales)');
        $this->assertEquals($products[2]->id, $orderedProducts[2]->id, 'Product 3 should be third (50 sales - most sold)');
    }

    // =========================================================================
    // TEST 5: Job marks menu as products_ordered = true
    // =========================================================================

    public function test_job_marks_menu_as_products_ordered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create category with products
        $data = $this->createCategoryWithProducts('SANDWICHES', 2);

        // Create menu
        $menu = $this->createCafeMenu('2026-01-23', 'TEST MENU', false);

        // Create category_menu
        $categoryMenu = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $data['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $categoryMenu->products()->attach([
            $data['products'][0]->id => ['display_order' => 1],
            $data['products'][1]->id => ['display_order' => 2],
        ]);

        // Verify menu is not ordered yet
        $this->assertFalse($menu->products_ordered);

        // Run command
        $this->artisan('menus:order-category-products')
            ->assertSuccessful();

        // Refresh and verify menu is now marked as ordered
        $menu->refresh();
        $this->assertTrue($menu->products_ordered);
    }

    // =========================================================================
    // TEST 6: Command does not reprocess menus already ordered
    // =========================================================================

    public function test_command_does_not_reprocess_already_ordered_menus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create category with products
        $data = $this->createCategoryWithProducts('SANDWICHES', 2);

        // Create user with sales
        $user = $this->createCafeUser();
        $this->createOrders($user, [
            $data['products'][0]->id => 10,
            $data['products'][1]->id => 5,
        ], '2026-01-15');

        // Create first menu
        $menu1 = $this->createCafeMenu('2026-01-23', 'MENU 1', false);

        $categoryMenu1 = CategoryMenu::create([
            'menu_id' => $menu1->id,
            'category_id' => $data['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $categoryMenu1->products()->attach([
            $data['products'][0]->id => ['display_order' => 1],
            $data['products'][1]->id => ['display_order' => 2],
        ]);

        // Run command first time
        $this->artisan('menus:order-category-products')
            ->assertSuccessful()
            ->expectsOutput('Found 1 Cafe menus to process.');

        // Verify menu is marked as ordered
        $menu1->refresh();
        $this->assertTrue($menu1->products_ordered);

        // Create second menu
        $menu2 = $this->createCafeMenu('2026-01-24', 'MENU 2', false);

        $categoryMenu2 = CategoryMenu::create([
            'menu_id' => $menu2->id,
            'category_id' => $data['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $categoryMenu2->products()->attach([
            $data['products'][0]->id => ['display_order' => 1],
            $data['products'][1]->id => ['display_order' => 2],
        ]);

        // Run command second time - should only process menu2
        $this->artisan('menus:order-category-products')
            ->assertSuccessful()
            ->expectsOutput('Found 1 Cafe menus to process.');

        // Verify menu2 is now marked as ordered
        $menu2->refresh();
        $this->assertTrue($menu2->products_ordered);
    }

    // =========================================================================
    // TEST 7: Job excludes dynamic category_menus
    // =========================================================================

    public function test_job_excludes_dynamic_category_menus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create regular category
        $regularData = $this->createCategoryWithProducts('REGULAR', 2, false);

        // Create dynamic category
        $dynamicData = $this->createCategoryWithProducts('DYNAMIC', 2, true);

        // Create user with sales
        $user = $this->createCafeUser();
        $this->createOrders($user, [
            $regularData['products'][1]->id => 20, // More sales for product 2
            $regularData['products'][0]->id => 5,
            $dynamicData['products'][1]->id => 100, // High sales but should be ignored
            $dynamicData['products'][0]->id => 50,
        ], '2026-01-15');

        // Create menu
        $menu = $this->createCafeMenu('2026-01-23', 'TEST MENU', false);

        // Create regular category_menu
        $regularCategoryMenu = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $regularData['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $regularCategoryMenu->products()->attach([
            $regularData['products'][0]->id => ['display_order' => 1],
            $regularData['products'][1]->id => ['display_order' => 2],
        ]);

        // Create dynamic category_menu
        $dynamicCategoryMenu = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $dynamicData['category']->id,
            'display_order' => 2,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $dynamicCategoryMenu->products()->attach([
            $dynamicData['products'][0]->id => ['display_order' => 1],
            $dynamicData['products'][1]->id => ['display_order' => 2],
        ]);

        // Run command
        $this->artisan('menus:order-category-products')
            ->assertSuccessful();

        // Verify regular category products are reordered (least sold first)
        $orderedRegular = $regularCategoryMenu->products()
            ->orderBy('category_menu_product.display_order')
            ->get();

        $this->assertEquals($regularData['products'][0]->id, $orderedRegular[0]->id, 'Regular product 1 should be first (5 sales - least sold)');
        $this->assertEquals($regularData['products'][1]->id, $orderedRegular[1]->id, 'Regular product 2 should be second (20 sales - most sold)');

        // Verify dynamic category products order is unchanged
        $orderedDynamic = $dynamicCategoryMenu->products()
            ->orderBy('category_menu_product.display_order')
            ->get();

        $this->assertEquals($dynamicData['products'][0]->id, $orderedDynamic[0]->id, 'Dynamic product 1 should still be first');
        $this->assertEquals($dynamicData['products'][1]->id, $orderedDynamic[1]->id, 'Dynamic product 2 should still be second');
    }

    // =========================================================================
    // TEST 8: Command does not run when auto-generate is disabled
    // =========================================================================

    public function test_command_does_not_run_when_disabled(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Disable auto-generation
        Parameter::where('name', Parameter::BEST_SELLING_CATEGORY_AUTO_GENERATE)
            ->update(['value' => '0']);

        // Create menu
        $this->createCafeMenu('2026-01-23', 'TEST MENU', false);

        $this->artisan('menus:order-category-products')
            ->assertSuccessful()
            ->expectsOutput('Auto-ordering of category menu products is disabled.');

        Queue::assertNothingPushed();
    }

    /**
     * Helper to extract menu_id from job (uses reflection)
     */
    protected function getJobMenuId(OrderCategoryMenuProductsByBestSellingJob $job): int
    {
        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('menuId');
        $property->setAccessible(true);
        return $property->getValue($job);
    }
}