<?php

namespace Tests\Feature\API\V1;

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
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Test for Category Menu API Product Display Order
 *
 * This test validates that products returned by the API are ordered
 * by the display_order field in the pivot table (category_menu_product).
 *
 * SCENARIOS:
 * 1. CategoryMenu with show_all_products = false
 *    - Products should be ordered by pivot display_order
 *
 * 2. CategoryMenu with show_all_products = true
 *    - Products with custom display_order should appear first
 *    - Products with default order (9999) should appear last
 *
 * EXPECTED BEHAVIOR:
 * - API should return products ordered by display_order ASC
 * - This allows administrators to control product display order per menu
 *
 * TDD RED PHASE:
 * - This test defines the expected behavior
 * - Test will FAIL initially if the API doesn't order by display_order
 */
class CategoryMenuProductDisplayOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $testUser;
    private Company $testCompany;
    private PriceList $testPriceList;
    private Role $agreementRole;
    private Permission $consolidatedPermission;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2025-10-20 10:00:00');

        // Create roles and permissions
        $this->agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $this->consolidatedPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // Create company
        $this->testCompany = Company::create([
            'name' => 'TEST COMPANY DISPLAY ORDER',
            'fantasy_name' => 'TEST DISPLAY',
            'address' => 'Test Address 123',
            'email' => 'test@displayorder.com',
            'phone_number' => '123456789',
            'registration_number' => 'REG-DISPLAY-001',
            'description' => 'Test company for display order',
            'active' => true,
            'tax_id' => '11.111.111-1',
        ]);

        // Create price list
        $this->testPriceList = PriceList::create([
            'name' => 'TEST DISPLAY ORDER PRICE LIST',
            'description' => 'Test price list',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $this->testCompany->update(['price_list_id' => $this->testPriceList->id]);

        // Create branch
        $branch = Branch::create([
            'name' => 'TEST DISPLAY ORDER BRANCH',
            'address' => 'Test Branch Address',
            'company_id' => $this->testCompany->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // Create user
        $this->testUser = User::create([
            'name' => 'TEST DISPLAY ORDER USER',
            'nickname' => 'TEST.DISPLAY',
            'email' => 'test.display@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->testCompany->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => false,
            'allow_late_orders' => true,
        ]);

        $this->testUser->roles()->attach($this->agreementRole->id);
        $this->testUser->permissions()->attach($this->consolidatedPermission->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test that products are ordered by display_order when show_all_products = false
     *
     * Creates a CategoryMenu with show_all_products = false and 5 products
     * with specific display_order values in the pivot table.
     *
     * Products are created in random order but should be returned
     * sorted by display_order ASC.
     */
    public function test_products_are_ordered_by_display_order_when_show_all_products_false(): void
    {
        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'TEST MENU DISPLAY ORDER',
            'description' => 'Test menu for display order',
            'publication_date' => '2025-10-21',
            'max_order_date' => '2025-10-20 18:00:00',
            'role_id' => $this->agreementRole->id,
            'permissions_id' => $this->consolidatedPermission->id,
            'active' => true,
        ]);

        $menu->companies()->attach($this->testCompany->id);

        // === CREATE CATEGORY ===
        $category = Category::create([
            'name' => 'TEST SANDWICHES',
            'description' => 'Test category for sandwiches',
            'is_active' => true,
        ]);

        // === CREATE 5 PRODUCTS WITH PRICES ===
        // Create products in random order (not in display order)
        $productsData = [
            ['name' => 'SANDWICH C', 'code' => 'SAND-C', 'expected_order' => 3],
            ['name' => 'SANDWICH A', 'code' => 'SAND-A', 'expected_order' => 1],
            ['name' => 'SANDWICH E', 'code' => 'SAND-E', 'expected_order' => 5],
            ['name' => 'SANDWICH B', 'code' => 'SAND-B', 'expected_order' => 2],
            ['name' => 'SANDWICH D', 'code' => 'SAND-D', 'expected_order' => 4],
        ];

        $products = [];
        foreach ($productsData as $data) {
            $product = Product::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => 'Test product',
                'category_id' => $category->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);

            PriceListLine::create([
                'price_list_id' => $this->testPriceList->id,
                'product_id' => $product->id,
                'unit_price' => 1000.00,
                'active' => true,
            ]);

            $products[$data['code']] = [
                'product' => $product,
                'display_order' => $data['expected_order'] * 10, // 10, 20, 30, 40, 50
            ];
        }

        // === CREATE CATEGORY MENU WITH show_all_products = false ===
        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => 100,
            'show_all_products' => false, // KEY: Only show attached products
            'is_active' => true,
            'mandatory_category' => false,
        ]);

        // Attach products with specific display_order in RANDOM order
        // This simulates real-world scenario where products are added at different times
        $categoryMenu->products()->attach($products['SAND-C']['product']->id, ['display_order' => 30]);
        $categoryMenu->products()->attach($products['SAND-A']['product']->id, ['display_order' => 10]);
        $categoryMenu->products()->attach($products['SAND-E']['product']->id, ['display_order' => 50]);
        $categoryMenu->products()->attach($products['SAND-B']['product']->id, ['display_order' => 20]);
        $categoryMenu->products()->attach($products['SAND-D']['product']->id, ['display_order' => 40]);

        // === MAKE API REQUEST ===
        Sanctum::actingAs($this->testUser);

        $response = $this->getJson("/api/v1/categories/{$menu->id}?page=1");

        // === ASSERTIONS ===
        $response->assertStatus(200);

        $responseData = $response->json('data.data');
        $this->assertCount(1, $responseData, 'Should return 1 category menu');

        $categoryMenuData = $responseData[0];

        // When show_all_products = false, products should be in the 'products' array
        $this->assertArrayHasKey('products', $categoryMenuData);
        $returnedProducts = $categoryMenuData['products'];

        $this->assertCount(5, $returnedProducts, 'Should return 5 products');

        // Verify products are ordered by display_order ASC
        $expectedOrder = ['SAND-A', 'SAND-B', 'SAND-C', 'SAND-D', 'SAND-E'];

        foreach ($returnedProducts as $index => $product) {
            $this->assertEquals(
                $expectedOrder[$index],
                $product['code'],
                "Product at position {$index} should be {$expectedOrder[$index]}, but got {$product['code']}. " .
                "Products should be ordered by display_order ASC."
            );
        }
    }

    /**
     * Test that products are ordered by display_order when show_all_products = true
     *
     * Creates a CategoryMenu with show_all_products = true and products
     * with custom display_order values. Products with custom order should
     * appear first, followed by products with default order (9999).
     */
    public function test_products_are_ordered_by_display_order_when_show_all_products_true(): void
    {
        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'TEST MENU DISPLAY ORDER ALL PRODUCTS',
            'description' => 'Test menu for display order with all products',
            'publication_date' => '2025-10-21',
            'max_order_date' => '2025-10-20 18:00:00',
            'role_id' => $this->agreementRole->id,
            'permissions_id' => $this->consolidatedPermission->id,
            'active' => true,
        ]);

        $menu->companies()->attach($this->testCompany->id);

        // === CREATE CATEGORY ===
        $category = Category::create([
            'name' => 'TEST BEVERAGES',
            'description' => 'Test category for beverages',
            'is_active' => true,
        ]);

        // === CREATE 6 PRODUCTS WITH PRICES ===
        // 3 products will have custom display_order
        // 3 products will have default display_order (9999)
        $productsData = [
            ['name' => 'JUICE ORANGE', 'code' => 'JUI-ORA', 'custom_order' => 20],
            ['name' => 'JUICE APPLE', 'code' => 'JUI-APP', 'custom_order' => 10],
            ['name' => 'JUICE GRAPE', 'code' => 'JUI-GRA', 'custom_order' => 30],
            ['name' => 'WATER STILL', 'code' => 'WAT-STI', 'custom_order' => null], // Default 9999
            ['name' => 'WATER SPARKLING', 'code' => 'WAT-SPA', 'custom_order' => null], // Default 9999
            ['name' => 'SODA COLA', 'code' => 'SOD-COL', 'custom_order' => null], // Default 9999
        ];

        $products = [];
        foreach ($productsData as $data) {
            $product = Product::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => 'Test product',
                'category_id' => $category->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);

            PriceListLine::create([
                'price_list_id' => $this->testPriceList->id,
                'product_id' => $product->id,
                'unit_price' => 500.00,
                'active' => true,
            ]);

            $products[$data['code']] = [
                'product' => $product,
                'custom_order' => $data['custom_order'],
            ];
        }

        // === CREATE CATEGORY MENU WITH show_all_products = true ===
        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => 100,
            'show_all_products' => true, // KEY: Show ALL products from category
            'is_active' => true,
            'mandatory_category' => false,
        ]);

        // Attach ALL products - some with custom order, some with default (9999)
        $categoryMenu->products()->attach($products['JUI-ORA']['product']->id, ['display_order' => 20]);
        $categoryMenu->products()->attach($products['JUI-APP']['product']->id, ['display_order' => 10]);
        $categoryMenu->products()->attach($products['JUI-GRA']['product']->id, ['display_order' => 30]);
        $categoryMenu->products()->attach($products['WAT-STI']['product']->id, ['display_order' => 9999]);
        $categoryMenu->products()->attach($products['WAT-SPA']['product']->id, ['display_order' => 9999]);
        $categoryMenu->products()->attach($products['SOD-COL']['product']->id, ['display_order' => 9999]);

        // === MAKE API REQUEST ===
        Sanctum::actingAs($this->testUser);

        $response = $this->getJson("/api/v1/categories/{$menu->id}?page=1");

        // === ASSERTIONS ===
        $response->assertStatus(200);

        $responseData = $response->json('data.data');
        $this->assertCount(1, $responseData, 'Should return 1 category menu');

        $categoryMenuData = $responseData[0];

        // When show_all_products = true, products should be in 'category.products'
        $this->assertArrayHasKey('category', $categoryMenuData);
        $this->assertArrayHasKey('products', $categoryMenuData['category']);
        $returnedProducts = $categoryMenuData['category']['products'];

        $this->assertCount(6, $returnedProducts, 'Should return 6 products');

        // Verify products with custom order come first (10, 20, 30)
        // followed by products with default order (9999)
        $expectedFirstThree = ['JUI-APP', 'JUI-ORA', 'JUI-GRA'];

        for ($i = 0; $i < 3; $i++) {
            $this->assertEquals(
                $expectedFirstThree[$i],
                $returnedProducts[$i]['code'],
                "Product at position {$i} should be {$expectedFirstThree[$i]}, but got {$returnedProducts[$i]['code']}. " .
                "Products with custom display_order should appear first, ordered by display_order ASC."
            );
        }

        // Verify products with default order (9999) come last
        $lastThreeCodes = array_map(fn($p) => $p['code'], array_slice($returnedProducts, 3));
        $expectedLastThree = ['WAT-STI', 'WAT-SPA', 'SOD-COL'];

        foreach ($expectedLastThree as $code) {
            $this->assertContains(
                $code,
                $lastThreeCodes,
                "Product {$code} with default order (9999) should be in the last 3 products."
            );
        }
    }

    /**
     * Test mixed categories - one with show_all_products=true, one with false
     *
     * Creates two CategoryMenus in the same menu:
     * - First with show_all_products = false
     * - Second with show_all_products = true
     *
     * Both should return products ordered by display_order.
     */
    public function test_mixed_categories_both_ordered_by_display_order(): void
    {
        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'TEST MENU MIXED CATEGORIES',
            'description' => 'Test menu with mixed category settings',
            'publication_date' => '2025-10-21',
            'max_order_date' => '2025-10-20 18:00:00',
            'role_id' => $this->agreementRole->id,
            'permissions_id' => $this->consolidatedPermission->id,
            'active' => true,
        ]);

        $menu->companies()->attach($this->testCompany->id);

        // === CREATE CATEGORY 1 (show_all_products = false) ===
        $category1 = Category::create([
            'name' => 'TEST MAIN DISHES',
            'description' => 'Test category for main dishes',
            'is_active' => true,
        ]);

        // Create 3 products for category 1
        $mainDishes = [];
        $mainDishesData = [
            ['name' => 'PASTA CARBONARA', 'code' => 'MAIN-C', 'order' => 30],
            ['name' => 'PASTA BOLOGNESE', 'code' => 'MAIN-B', 'order' => 20],
            ['name' => 'PASTA ALFREDO', 'code' => 'MAIN-A', 'order' => 10],
        ];

        foreach ($mainDishesData as $data) {
            $product = Product::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => 'Test product',
                'category_id' => $category1->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);

            PriceListLine::create([
                'price_list_id' => $this->testPriceList->id,
                'product_id' => $product->id,
                'unit_price' => 2000.00,
                'active' => true,
            ]);

            $mainDishes[$data['code']] = ['product' => $product, 'order' => $data['order']];
        }

        // Create CategoryMenu 1 with show_all_products = false
        $categoryMenu1 = CategoryMenu::create([
            'category_id' => $category1->id,
            'menu_id' => $menu->id,
            'display_order' => 100,
            'show_all_products' => false,
            'is_active' => true,
            'mandatory_category' => false,
        ]);

        // Attach products in reverse order (C, B, A) but with correct display_order
        $categoryMenu1->products()->attach($mainDishes['MAIN-C']['product']->id, ['display_order' => 30]);
        $categoryMenu1->products()->attach($mainDishes['MAIN-B']['product']->id, ['display_order' => 20]);
        $categoryMenu1->products()->attach($mainDishes['MAIN-A']['product']->id, ['display_order' => 10]);

        // === CREATE CATEGORY 2 (show_all_products = true) ===
        $category2 = Category::create([
            'name' => 'TEST DESSERTS',
            'description' => 'Test category for desserts',
            'is_active' => true,
        ]);

        // Create 4 products for category 2
        $desserts = [];
        $dessertsData = [
            ['name' => 'CAKE CHOCOLATE', 'code' => 'DES-CHO', 'order' => 20],
            ['name' => 'CAKE VANILLA', 'code' => 'DES-VAN', 'order' => 10],
            ['name' => 'ICE CREAM', 'code' => 'DES-ICE', 'order' => 9999],
            ['name' => 'FRUIT SALAD', 'code' => 'DES-FRU', 'order' => 9999],
        ];

        foreach ($dessertsData as $data) {
            $product = Product::create([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => 'Test product',
                'category_id' => $category2->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);

            PriceListLine::create([
                'price_list_id' => $this->testPriceList->id,
                'product_id' => $product->id,
                'unit_price' => 1500.00,
                'active' => true,
            ]);

            $desserts[$data['code']] = ['product' => $product, 'order' => $data['order']];
        }

        // Create CategoryMenu 2 with show_all_products = true
        $categoryMenu2 = CategoryMenu::create([
            'category_id' => $category2->id,
            'menu_id' => $menu->id,
            'display_order' => 200,
            'show_all_products' => true,
            'is_active' => true,
            'mandatory_category' => false,
        ]);

        // Attach all products with their display_order
        foreach ($desserts as $code => $data) {
            $categoryMenu2->products()->attach($data['product']->id, ['display_order' => $data['order']]);
        }

        // === MAKE API REQUEST ===
        Sanctum::actingAs($this->testUser);

        $response = $this->getJson("/api/v1/categories/{$menu->id}?page=1");

        // === ASSERTIONS ===
        $response->assertStatus(200);

        $responseData = $response->json('data.data');
        $this->assertCount(2, $responseData, 'Should return 2 category menus');

        // === VERIFY CATEGORY 1 (show_all_products = false) ===
        $categoryMenu1Data = collect($responseData)->firstWhere('category.name', 'TEST MAIN DISHES');
        $this->assertNotNull($categoryMenu1Data, 'Should find TEST MAIN DISHES category');
        $this->assertFalse($categoryMenu1Data['show_all_products']);

        $mainDishProducts = $categoryMenu1Data['products'];
        $this->assertCount(3, $mainDishProducts);

        // Verify order: A, B, C (by display_order 10, 20, 30)
        $this->assertEquals('MAIN-A', $mainDishProducts[0]['code']);
        $this->assertEquals('MAIN-B', $mainDishProducts[1]['code']);
        $this->assertEquals('MAIN-C', $mainDishProducts[2]['code']);

        // === VERIFY CATEGORY 2 (show_all_products = true) ===
        $categoryMenu2Data = collect($responseData)->firstWhere('category.name', 'TEST DESSERTS');
        $this->assertNotNull($categoryMenu2Data, 'Should find TEST DESSERTS category');
        $this->assertTrue($categoryMenu2Data['show_all_products']);

        $dessertProducts = $categoryMenu2Data['category']['products'];
        $this->assertCount(4, $dessertProducts);

        // Verify first two products have custom order (10, 20)
        $this->assertEquals('DES-VAN', $dessertProducts[0]['code'], 'First dessert should be DES-VAN (order 10)');
        $this->assertEquals('DES-CHO', $dessertProducts[1]['code'], 'Second dessert should be DES-CHO (order 20)');

        // Verify last two products have default order (9999)
        $lastTwoCodes = [$dessertProducts[2]['code'], $dessertProducts[3]['code']];
        $this->assertContains('DES-ICE', $lastTwoCodes, 'DES-ICE should be in last two');
        $this->assertContains('DES-FRU', $lastTwoCodes, 'DES-FRU should be in last two');
    }
}