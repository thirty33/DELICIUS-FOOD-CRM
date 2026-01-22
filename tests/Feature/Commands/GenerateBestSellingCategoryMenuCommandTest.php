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
use Illuminate\Support\Facades\Config;
use App\Jobs\CreateBestSellingProductsCategoryMenuJob;
use App\Contracts\BestSellingProductsRepositoryInterface;
use Carbon\Carbon;

/**
 * Integration Test for GenerateBestSellingCategoryMenu Command
 *
 * Tests:
 * 1. Command does NOT process menus with publication_date before today
 * 2. Command processes ONLY 5 menus maximum per execution
 * 3. Dynamic category is created for each processed menu
 * 4. Best-selling products query returns correct data based on simulated orders
 */
class GenerateBestSellingCategoryMenuCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Role $cafeRole;
    protected Role $otherRole;
    protected Permission $consolidadoPermission;
    protected Company $company;
    protected PriceList $priceList;
    protected Branch $branch;
    protected Category $dynamicCategory;

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

        // Create or get dynamic category
        $this->dynamicCategory = Category::firstOrCreate(
            ['is_dynamic' => true],
            [
                'name' => 'Productos más vendidos',
                'description' => 'Dynamic category for best-selling products',
                'is_active' => true,
            ]
        );

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
            ['name' => Parameter::BEST_SELLING_CATEGORY_PRODUCTS_LIMIT],
            [
                'description' => 'Products limit',
                'value_type' => 'integer',
                'value' => '10',
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
    protected function createCategoryWithProducts(string $categoryName, int $productCount = 3): array
    {
        $category = Category::create([
            'name' => $categoryName,
            'description' => "Test category: {$categoryName}",
            'is_active' => true,
            'is_dynamic' => false,
        ]);

        $products = [];
        for ($i = 1; $i <= $productCount; $i++) {
            $product = Product::create([
                'name' => "{$categoryName} - Product {$i}",
                'code' => strtoupper(substr($categoryName, 0, 3)) . "-{$i}",
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
                'unit_price' => 100000 * $i, // $1,000, $2,000, $3,000
                'active' => true,
            ]);

            $products[] = $product;
        }

        return ['category' => $category, 'products' => $products];
    }

    /**
     * Create a Cafe menu
     */
    protected function createCafeMenu(string $publicationDate, string $title = null): Menu
    {
        return Menu::create([
            'title' => $title ?? "TEST CAFE MENU {$publicationDate}",
            'description' => null,
            'publication_date' => $publicationDate,
            'max_order_date' => Carbon::parse($publicationDate)->subDay()->setTime(15, 30)->format('Y-m-d H:i:s'),
            'role_id' => $this->cafeRole->id,
            'permissions_id' => $this->consolidadoPermission->id,
            'active' => true,
        ]);
    }

    /**
     * Create orders for a user to simulate best-selling products
     */
    protected function createOrders(User $user, array $productQuantities, string $orderDate): void
    {
        $order = Order::create([
            'user_id' => $user->id,
            'dispatch_date' => Carbon::parse($orderDate)->addDay()->format('Y-m-d'),
            'status' => OrderStatus::PROCESSED->value,
            'total' => 0,
        ]);

        // Force set created_at after creation (bypasses Eloquent timestamps)
        $order->created_at = Carbon::parse($orderDate);
        $order->saveQuietly();

        $total = 0;
        foreach ($productQuantities as $productId => $quantity) {
            $product = Product::find($productId);
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
    // TEST 1: Command does NOT process menus before today
    // =========================================================================

    public function test_command_does_not_process_menus_before_today(): void
    {
        Queue::fake();

        // Set current date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create menus: 2 past, 1 today, 2 future
        $pastMenu1 = $this->createCafeMenu('2026-01-20'); // PAST - should NOT be processed
        $pastMenu2 = $this->createCafeMenu('2026-01-21'); // PAST - should NOT be processed
        $todayMenu = $this->createCafeMenu('2026-01-22'); // TODAY - should be processed
        $futureMenu1 = $this->createCafeMenu('2026-01-23'); // FUTURE - should be processed
        $futureMenu2 = $this->createCafeMenu('2026-01-24'); // FUTURE - should be processed

        // Run command
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->expectsOutput('Found 3 Cafe menus to process.')
            ->expectsOutput('Dispatched 3 jobs successfully.');

        // Verify only 3 jobs dispatched (today + 2 future)
        Queue::assertPushed(CreateBestSellingProductsCategoryMenuJob::class, 3);

        // Verify past menus were NOT processed
        Queue::assertNotPushed(CreateBestSellingProductsCategoryMenuJob::class, function ($job) use ($pastMenu1) {
            return $this->getJobMenuId($job) === $pastMenu1->id;
        });

        Queue::assertNotPushed(CreateBestSellingProductsCategoryMenuJob::class, function ($job) use ($pastMenu2) {
            return $this->getJobMenuId($job) === $pastMenu2->id;
        });
    }

    // =========================================================================
    // TEST 2: Command processes ONLY 5 menus maximum
    // =========================================================================

    public function test_command_processes_only_5_menus_maximum(): void
    {
        Queue::fake();

        // Set current date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create 8 future menus
        for ($i = 0; $i < 8; $i++) {
            $date = Carbon::parse('2026-01-22')->addDays($i)->format('Y-m-d');
            $this->createCafeMenu($date, "MENU {$i}");
        }

        // Run command with default limit (5)
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->expectsOutput('Found 5 Cafe menus to process.')
            ->expectsOutput('Dispatched 5 jobs successfully.');

        // Verify exactly 5 jobs dispatched
        Queue::assertPushed(CreateBestSellingProductsCategoryMenuJob::class, 5);
    }

    // =========================================================================
    // TEST 3: Best-selling repository returns products correctly
    // =========================================================================

    public function test_best_selling_repository_returns_products(): void
    {
        // Set current date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create category with products
        $data = $this->createCategoryWithProducts('SANDWICHES', 3);
        $products = $data['products'];

        // Create Cafe user and orders
        $user = $this->createCafeUser();

        // Create orders within the last 30 days
        $this->createOrders($user, [
            $products[0]->id => 10,
            $products[1]->id => 5,
            $products[2]->id => 3,
        ], '2026-01-15');

        // Get repository and query
        $repository = app(BestSellingProductsRepositoryInterface::class);
        $startDate = Carbon::now()->subDays(30)->format('Y-m-d');
        $endDate = Carbon::now()->format('Y-m-d');

        $productIds = $repository->getBestSellingProductIdsByRole(
            RoleName::CAFE->value,
            $startDate,
            $endDate,
            10
        );

        // Verify products are found
        $this->assertNotEmpty($productIds, 'Repository should return best-selling products');
        $this->assertCount(3, $productIds);

        // Verify dynamic category exists
        $this->assertNotNull($this->dynamicCategory);
        $this->assertTrue($this->dynamicCategory->is_dynamic);
    }

    // =========================================================================
    // TEST 4: Dynamic category is created for each menu
    // =========================================================================

    public function test_dynamic_category_is_created_for_each_menu(): void
    {
        // Set current date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create category with products
        $data = $this->createCategoryWithProducts('SANDWICHES', 3);
        $products = $data['products'];

        // Create Cafe user and orders (to have best-selling products)
        $user = $this->createCafeUser();

        // Create orders within the last 30 days
        $this->createOrders($user, [
            $products[0]->id => 10, // Product 1: 10 units sold
            $products[1]->id => 5,  // Product 2: 5 units sold
            $products[2]->id => 3,  // Product 3: 3 units sold
        ], '2026-01-15');

        // Create 2 future menus
        $menu1 = $this->createCafeMenu('2026-01-23');
        $menu2 = $this->createCafeMenu('2026-01-24');

        // Verify no CategoryMenu exists for dynamic category before command
        $this->assertDatabaseMissing('category_menu', [
            'category_id' => $this->dynamicCategory->id,
            'menu_id' => $menu1->id,
        ]);
        $this->assertDatabaseMissing('category_menu', [
            'category_id' => $this->dynamicCategory->id,
            'menu_id' => $menu2->id,
        ]);

        // Run command (jobs will run synchronously due to phpunit.xml QUEUE_CONNECTION=sync)
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful();

        // Verify CategoryMenu was created for each menu
        $this->assertDatabaseHas('category_menu', [
            'category_id' => $this->dynamicCategory->id,
            'menu_id' => $menu1->id,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('category_menu', [
            'category_id' => $this->dynamicCategory->id,
            'menu_id' => $menu2->id,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        // Verify products were attached to the CategoryMenu
        $categoryMenu1 = CategoryMenu::where('menu_id', $menu1->id)
            ->where('category_id', $this->dynamicCategory->id)
            ->first();

        $this->assertNotNull($categoryMenu1);
        $this->assertEquals(3, $categoryMenu1->products->count());
    }

    // =========================================================================
    // TEST 5: Best-selling products query returns correct order by category
    // =========================================================================

    public function test_best_selling_products_query_returns_correct_order(): void
    {

        // Set current date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create categories with products
        $sandwiches = $this->createCategoryWithProducts('SANDWICHES', 2);
        $salads = $this->createCategoryWithProducts('ENSALADAS', 2);
        $desserts = $this->createCategoryWithProducts('POSTRES', 2);

        // Create Cafe user
        $user = $this->createCafeUser();

        // Create orders to establish best-sellers:
        // SANDWICHES total: 15 units (highest)
        // ENSALADAS total: 10 units (second)
        // POSTRES total: 5 units (third)
        $this->createOrders($user, [
            $sandwiches['products'][0]->id => 10, // Sandwich 1: 10 units
            $sandwiches['products'][1]->id => 5,  // Sandwich 2: 5 units
        ], '2026-01-10');

        $this->createOrders($user, [
            $salads['products'][0]->id => 7,      // Salad 1: 7 units
            $salads['products'][1]->id => 3,      // Salad 2: 3 units
        ], '2026-01-12');

        $this->createOrders($user, [
            $desserts['products'][0]->id => 3,    // Dessert 1: 3 units
            $desserts['products'][1]->id => 2,    // Dessert 2: 2 units
        ], '2026-01-14');

        // Create menu
        $menu = $this->createCafeMenu('2026-01-23');

        // Run command (jobs will run synchronously)
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful();

        // Get the created CategoryMenu
        $categoryMenu = CategoryMenu::where('menu_id', $menu->id)
            ->where('category_id', $this->dynamicCategory->id)
            ->first();

        $this->assertNotNull($categoryMenu);

        // Get products in order
        $attachedProducts = $categoryMenu->products()
            ->orderBy('category_menu_product.display_order')
            ->get();

        $this->assertEquals(6, $attachedProducts->count());

        // Verify order: SANDWICHES first (highest category sales), then ENSALADAS, then POSTRES
        // Within each category, ordered by individual sales

        // First 2 products should be from SANDWICHES (category with most sales)
        $this->assertEquals($sandwiches['category']->id, $attachedProducts[0]->category_id);
        $this->assertEquals($sandwiches['category']->id, $attachedProducts[1]->category_id);

        // Verify within SANDWICHES: Product 1 (10 units) before Product 2 (5 units)
        $this->assertEquals($sandwiches['products'][0]->id, $attachedProducts[0]->id);
        $this->assertEquals($sandwiches['products'][1]->id, $attachedProducts[1]->id);

        // Next 2 products should be from ENSALADAS
        $this->assertEquals($salads['category']->id, $attachedProducts[2]->category_id);
        $this->assertEquals($salads['category']->id, $attachedProducts[3]->category_id);

        // Last 2 products should be from POSTRES
        $this->assertEquals($desserts['category']->id, $attachedProducts[4]->category_id);
        $this->assertEquals($desserts['category']->id, $attachedProducts[5]->category_id);
    }

    // =========================================================================
    // TEST 5: Command does not run when auto-generate is disabled
    // =========================================================================

    public function test_command_does_not_run_when_auto_generate_disabled(): void
    {
        Queue::fake();

        // Set current date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Disable auto-generation
        Parameter::where('name', Parameter::BEST_SELLING_CATEGORY_AUTO_GENERATE)
            ->update(['value' => '0']);

        // Create future menu
        $this->createCafeMenu('2026-01-23');

        // Run command
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->expectsOutput('Auto-generation of best-selling category is disabled.');

        // Verify no jobs dispatched
        Queue::assertNothingPushed();
    }

    // =========================================================================
    // TEST 6: Command only processes Cafe menus (not other roles)
    // =========================================================================

    public function test_command_only_processes_cafe_menus(): void
    {
        Queue::fake();

        // Set current date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create Cafe menu
        $cafeMenu = $this->createCafeMenu('2026-01-23');

        // Create non-Cafe menu (different role)
        $otherMenu = Menu::create([
            'title' => 'OTHER ROLE MENU',
            'description' => null,
            'publication_date' => '2026-01-23',
            'max_order_date' => '2026-01-22 15:30:00',
            'role_id' => $this->otherRole->id,
            'permissions_id' => $this->consolidadoPermission->id,
            'active' => true,
        ]);

        // Run command
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->expectsOutput('Found 1 Cafe menus to process.')
            ->expectsOutput('Dispatched 1 jobs successfully.');

        // Verify only 1 job dispatched (Cafe menu only)
        Queue::assertPushed(CreateBestSellingProductsCategoryMenuJob::class, 1);
    }

    // =========================================================================
    // TEST 7: Command excludes menus that already have dynamic category
    // =========================================================================

    /**
     * Test that the command does not process the same menus twice.
     *
     * When a menu already has a CategoryMenu with a dynamic category,
     * it should be excluded from subsequent command executions.
     *
     * This validates the whereDoesntHave filter:
     * ->whereDoesntHave('categoryMenus.category', function ($query) {
     *     $query->where('is_dynamic', true);
     * })
     */
    public function test_command_excludes_menus_with_existing_dynamic_category(): void
    {
        // Set current date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create category with products for best-selling data
        $data = $this->createCategoryWithProducts('SANDWICHES', 3);
        $products = $data['products'];

        // Create Cafe user and orders
        $user = $this->createCafeUser();
        $this->createOrders($user, [
            $products[0]->id => 10,
            $products[1]->id => 5,
            $products[2]->id => 3,
        ], '2026-01-15');

        // Create first menu
        $menu1 = $this->createCafeMenu('2026-01-23', 'FIRST MENU');

        // Run command first time - should process menu1
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->expectsOutput('Found 1 Cafe menus to process.')
            ->expectsOutput('Dispatched 1 jobs successfully.');

        // Verify CategoryMenu was created for menu1
        $this->assertDatabaseHas('category_menu', [
            'category_id' => $this->dynamicCategory->id,
            'menu_id' => $menu1->id,
        ]);

        // Create second menu
        $menu2 = $this->createCafeMenu('2026-01-24', 'SECOND MENU');

        // Run command second time - should only process menu2, NOT menu1 again
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->expectsOutput('Found 1 Cafe menus to process.')
            ->expectsOutput('Dispatched 1 jobs successfully.');

        // Verify CategoryMenu was created for menu2
        $this->assertDatabaseHas('category_menu', [
            'category_id' => $this->dynamicCategory->id,
            'menu_id' => $menu2->id,
        ]);

        // Verify there's still only 1 CategoryMenu per menu (not duplicated)
        $menu1CategoryMenuCount = CategoryMenu::where('menu_id', $menu1->id)
            ->where('category_id', $this->dynamicCategory->id)
            ->count();

        $menu2CategoryMenuCount = CategoryMenu::where('menu_id', $menu2->id)
            ->where('category_id', $this->dynamicCategory->id)
            ->count();

        $this->assertEquals(1, $menu1CategoryMenuCount, 'Menu 1 should have exactly 1 dynamic CategoryMenu');
        $this->assertEquals(1, $menu2CategoryMenuCount, 'Menu 2 should have exactly 1 dynamic CategoryMenu');
    }

    /**
     * Test that when all menus already have dynamic categories, no jobs are dispatched.
     */
    public function test_command_returns_no_menus_when_all_have_dynamic_categories(): void
    {
        Queue::fake();

        // Set current date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create 3 future menus
        $menu1 = $this->createCafeMenu('2026-01-23', 'MENU 1');
        $menu2 = $this->createCafeMenu('2026-01-24', 'MENU 2');
        $menu3 = $this->createCafeMenu('2026-01-25', 'MENU 3');

        // Manually create dynamic CategoryMenu for all menus (simulating previous command runs)
        foreach ([$menu1, $menu2, $menu3] as $menu) {
            CategoryMenu::create([
                'menu_id' => $menu->id,
                'category_id' => $this->dynamicCategory->id,
                'display_order' => 1,
                'show_all_products' => false,
                'is_active' => true,
            ]);
        }

        // Run command - should find no menus to process
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->expectsOutput('No Cafe menus found.');

        // Verify no jobs dispatched
        Queue::assertNothingPushed();
    }

    /**
     * Test that the command processes only menus without dynamic categories
     * when there's a mix of menus with and without dynamic categories.
     */
    public function test_command_processes_only_menus_without_dynamic_categories(): void
    {
        Queue::fake();

        // Set current date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create 5 future menus
        $menu1 = $this->createCafeMenu('2026-01-23', 'MENU 1');
        $menu2 = $this->createCafeMenu('2026-01-24', 'MENU 2');
        $menu3 = $this->createCafeMenu('2026-01-25', 'MENU 3');
        $menu4 = $this->createCafeMenu('2026-01-26', 'MENU 4');
        $menu5 = $this->createCafeMenu('2026-01-27', 'MENU 5');

        // Manually create dynamic CategoryMenu for menus 1, 3, and 5
        foreach ([$menu1, $menu3, $menu5] as $menu) {
            CategoryMenu::create([
                'menu_id' => $menu->id,
                'category_id' => $this->dynamicCategory->id,
                'display_order' => 1,
                'show_all_products' => false,
                'is_active' => true,
            ]);
        }

        // Run command - should only find menus 2 and 4 (without dynamic category)
        $this->artisan('menus:generate-best-selling-category')
            ->assertSuccessful()
            ->expectsOutput('Found 2 Cafe menus to process.')
            ->expectsOutput('Dispatched 2 jobs successfully.');

        // Verify only 2 jobs dispatched
        Queue::assertPushed(CreateBestSellingProductsCategoryMenuJob::class, 2);

        // Verify jobs were dispatched for menu2 and menu4 only
        Queue::assertPushed(CreateBestSellingProductsCategoryMenuJob::class, function ($job) use ($menu2) {
            return $this->getJobMenuId($job) === $menu2->id;
        });

        Queue::assertPushed(CreateBestSellingProductsCategoryMenuJob::class, function ($job) use ($menu4) {
            return $this->getJobMenuId($job) === $menu4->id;
        });

        // Verify jobs were NOT dispatched for menus with existing dynamic category
        Queue::assertNotPushed(CreateBestSellingProductsCategoryMenuJob::class, function ($job) use ($menu1) {
            return $this->getJobMenuId($job) === $menu1->id;
        });

        Queue::assertNotPushed(CreateBestSellingProductsCategoryMenuJob::class, function ($job) use ($menu3) {
            return $this->getJobMenuId($job) === $menu3->id;
        });

        Queue::assertNotPushed(CreateBestSellingProductsCategoryMenuJob::class, function ($job) use ($menu5) {
            return $this->getJobMenuId($job) === $menu5->id;
        });
    }

    /**
     * Helper to extract menu_id from job (uses reflection)
     */
    protected function getJobMenuId(CreateBestSellingProductsCategoryMenuJob $job): int
    {
        $reflection = new \ReflectionClass($job);
        $property = $reflection->getProperty('menuId');
        $property->setAccessible(true);
        return $property->getValue($job);
    }
}
