<?php

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\PriceList;
use App\Models\Menu;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Product;
use App\Models\PriceListLine;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

class CategoryMenuPriceListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $testUser;
    private Company $testCompany;
    private PriceList $testPriceList;
    private Subcategory $entradaSubcategory;
    private Subcategory $calienteSubcategory;
    private Subcategory $friaSubcategory;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time to 2025-10-13 (test creation date)
        Carbon::setTestNow('2025-10-13 00:00:00');

        // Create company
        $this->testCompany = Company::create([
            'name' => 'TEST COMPANY LIMITADA',
            'fantasy_name' => 'TEST COMPANY',
            'rut' => '12345678-9',
            'address' => 'Test Address 123',
            'phone' => '+56912345678',
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

        // Create user
        $this->testUser = User::create([
            'name' => 'TEST USER',
            'nickname' => 'TEST.USER',
            'email' => 'testuser@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $this->testCompany->id,
            'active' => true,
        ]);

        // Create subcategories
        $this->entradaSubcategory = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $this->calienteSubcategory = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $this->friaSubcategory = Subcategory::firstOrCreate(['name' => 'FRIA']);
    }

    protected function tearDown(): void
    {
        // Release frozen time after each test
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test menu with products that have prices (like menu 204)
     * This menu should return categories with products
     */
    public function test_menu_with_priced_products_returns_categories_with_products(): void
    {
        // Create menu (emulating menu 204)
        $menu = Menu::create([
            'title' => 'TEST MENU WITH PRICES',
            'publication_date' => '2025-10-14',
            'active' => true,
        ]);

        // Create Category 1: SOPAS Y CREMAS VARIABLES
        $category1 = Category::create([
            'name' => 'TEST SOPAS Y CREMAS VARIABLES',
            'description' => 'Test category',
            'active' => true,
        ]);
        $category1->subcategories()->attach([
            $this->entradaSubcategory->id,
            $this->calienteSubcategory->id,
        ]);

        // Create Product 1 with price
        $product1 = Product::create([
            'name' => 'TEST - CONSOME DE POLLO',
            'description' => 'Test product description',
            'code' => 'TEST001',
            'category_id' => $category1->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create price list line for product 1
        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $product1->id,
            'unit_price' => 20000.00,
            'active' => true,
        ]);

        // Create Category 2: MINI ENSALADAS
        $category2 = Category::create([
            'name' => 'TEST MINI ENSALADAS',
            'description' => 'Test category',
            'active' => true,
        ]);
        $category2->subcategories()->attach([
            $this->entradaSubcategory->id,
            $this->friaSubcategory->id,
        ]);

        // Create Product 2 with price
        $product2 = Product::create([
            'name' => 'TEST - MINI ENSALADA CHOCLO',
            'description' => 'Test product description',
            'code' => 'TEST002',
            'category_id' => $category2->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create price list line for product 2
        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $product2->id,
            'unit_price' => 20000.00,
            'active' => true,
        ]);

        // Create CategoryMenu entries
        $categoryMenu1 = CategoryMenu::create([
            'category_id' => $category1->id,
            'menu_id' => $menu->id,
            'order' => 10,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 10,
        ]);

        $categoryMenu2 = CategoryMenu::create([
            'category_id' => $category2->id,
            'menu_id' => $menu->id,
            'order' => 20,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 20,
        ]);

        // Attach products to CategoryMenu via pivot table
        $categoryMenu1->products()->attach($product1->id);
        $categoryMenu2->products()->attach($product2->id);

        // Authenticate user
        Sanctum::actingAs($this->testUser);

        // Make API request
        $response = $this->getJson("/api/v1/categories/{$menu->id}?page=1");

        // Assertions
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'data' => [
                    '*' => [
                        'id',
                        'category',
                        'products',
                    ],
                ],
            ],
        ]);

        $data = $response->json('data.data');

        // Should return 2 categories with ENTRADA subcategory
        $this->assertCount(2, $data);

        // Verify both categories have products
        foreach ($data as $categoryMenu) {
            // Check if category has ENTRADA subcategory
            $hasEntrada = collect($categoryMenu['category']['subcategories'])
                ->contains('name', 'ENTRADA');

            if ($hasEntrada) {
                // For categories with ENTRADA subcategory
                // If show_all_products is false, products should be in the products array at root level
                if (!$categoryMenu['show_all_products']) {
                    $this->assertGreaterThan(
                        0,
                        count($categoryMenu['products']),
                        "Category {$categoryMenu['category']['name']} should have products in 'products' array"
                    );
                } else {
                    // If show_all_products is true, products should be in category.products
                    $this->assertGreaterThan(
                        0,
                        count($categoryMenu['category']['products']),
                        "Category {$categoryMenu['category']['name']} should have products in 'category.products' array"
                    );
                }
            }
        }
    }

    /**
     * Test menu with inactive products should NOT return inactive products
     * Only active products with prices should be returned
     */
    public function test_menu_with_inactive_products_does_not_return_inactive_products(): void
    {
        // Create menu
        $menu = Menu::create([
            'title' => 'TEST MENU WITH INACTIVE PRODUCTS',
            'publication_date' => '2025-10-14',
            'active' => true,
        ]);

        // Create Category 1: SOPAS Y CREMAS VARIABLES
        $category1 = Category::create([
            'name' => 'TEST SOPAS Y CREMAS VARIABLES',
            'description' => 'Test category',
            'active' => true,
        ]);
        $category1->subcategories()->attach([
            $this->entradaSubcategory->id,
            $this->calienteSubcategory->id,
        ]);

        // Create Product 1 ACTIVE with price
        $activeProduct = Product::create([
            'name' => 'TEST - CONSOME DE POLLO ACTIVE',
            'description' => 'Test product description',
            'code' => 'TEST_ACTIVE_001',
            'category_id' => $category1->id,
            'active' => true,  // ACTIVE
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create price list line for active product
        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $activeProduct->id,
            'unit_price' => 20000.00,
            'active' => true,
        ]);

        // Create Product 2 INACTIVE with price
        $inactiveProduct = Product::create([
            'name' => 'TEST - CREMA DE ESPINACA INACTIVE',
            'description' => 'Test product description',
            'code' => 'TEST_INACTIVE_001',
            'category_id' => $category1->id,
            'active' => false,  // INACTIVE
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create price list line for inactive product (has price but product is inactive)
        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $inactiveProduct->id,
            'unit_price' => 25000.00,
            'active' => true,
        ]);

        // Create CategoryMenu entry
        $categoryMenu1 = CategoryMenu::create([
            'category_id' => $category1->id,
            'menu_id' => $menu->id,
            'order' => 10,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 10,
        ]);

        // Attach BOTH products to CategoryMenu (active and inactive)
        $categoryMenu1->products()->attach($activeProduct->id);
        $categoryMenu1->products()->attach($inactiveProduct->id);

        // Authenticate user
        Sanctum::actingAs($this->testUser);

        // Make API request
        $response = $this->getJson("/api/v1/categories/{$menu->id}?page=1");

        // Assertions
        $response->assertStatus(200);

        $data = $response->json('data.data');

        // Should return 1 category with ENTRADA subcategory
        $this->assertCount(1, $data);

        $categoryData = $data[0];

        // Category should have products
        $this->assertArrayHasKey('products', $categoryData);
        $products = $categoryData['products'];

        // Should only return 1 product (the active one)
        $this->assertCount(
            1,
            $products,
            'Should only return active products, inactive products should be filtered out'
        );

        // Verify the returned product is the active one
        $this->assertEquals(
            'TEST_ACTIVE_001',
            $products[0]['code'],
            'Returned product should be the active one'
        );

        // Verify inactive product is NOT in the response
        $productCodes = collect($products)->pluck('code')->toArray();
        $this->assertNotContains(
            'TEST_INACTIVE_001',
            $productCodes,
            'Inactive product should NOT be returned'
        );
    }

    /**
     * Test menu with products that DON'T have prices (like menu 188)
     * This menu should NOT return categories without priced products
     */
    public function test_menu_without_priced_products_does_not_return_categories(): void
    {
        // Create menu (emulating menu 188)
        $menu = Menu::create([
            'title' => 'TEST MENU WITHOUT PRICES',
            'publication_date' => '2025-10-19',
            'active' => true,
        ]);

        // Create Category 1: SOPAS Y CREMAS VARIABLES
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
            'code' => 'TEST003',
            'category_id' => $category1->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create a different price list line (NOT for test user's price list)
        $otherPriceList = PriceList::create([
            'name' => 'OTHER PRICE LIST',
            'active' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $otherPriceList->id,
            'product_id' => $product1->id,
            'unit_price' => 15000.00,
            'active' => true,
        ]);

        // Create Category 2: MINI ENSALADAS
        $category2 = Category::create([
            'name' => 'TEST MINI ENSALADAS SIN PRECIO',
            'description' => 'Test category',
            'active' => true,
        ]);
        $category2->subcategories()->attach([
            $this->entradaSubcategory->id,
            $this->friaSubcategory->id,
        ]);

        // Create Product 2 WITHOUT price for test user's price list
        $product2 = Product::create([
            'name' => 'TEST - MINI ENSALADA LECHUGA',
            'description' => 'Test product description',
            'code' => 'TEST004',
            'category_id' => $category2->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // No price list line for test user's price list

        // Create CategoryMenu entries
        $categoryMenu1 = CategoryMenu::create([
            'category_id' => $category1->id,
            'menu_id' => $menu->id,
            'order' => 10,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 10,
        ]);

        $categoryMenu2 = CategoryMenu::create([
            'category_id' => $category2->id,
            'menu_id' => $menu->id,
            'order' => 20,
            'show_all_products' => false,
            'is_active' => true,
            'display_order' => 20,
        ]);

        // Attach products to CategoryMenu via pivot table
        $categoryMenu1->products()->attach($product1->id);
        $categoryMenu2->products()->attach($product2->id);

        // Now create a "SIN" product with price (this should NOT make categories appear)
        $sinProduct = Product::create([
            'name' => 'TEST - SIN SOPA',
            'description' => 'Test product description',
            'code' => 'TEST005',
            'category_id' => $category1->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $sinProduct->id,
            'unit_price' => 5000.00,
            'active' => true,
        ]);

        // NOTE: SIN product is NOT attached to CategoryMenu pivot table

        // Authenticate user
        Sanctum::actingAs($this->testUser);

        // Make API request
        $response = $this->getJson("/api/v1/categories/{$menu->id}?page=1");

        // Assertions
        $response->assertStatus(200);

        $data = $response->json('data.data');

        // Should NOT return categories with ENTRADA subcategory if their products (in pivot) don't have prices
        $entradaCategories = collect($data)->filter(function ($categoryMenu) {
            return collect($categoryMenu['category']['subcategories'])
                ->contains('name', 'ENTRADA');
        });

        $this->assertCount(
            0,
            $entradaCategories,
            'Categories with ENTRADA subcategory should NOT be returned if products in pivot do not have prices'
        );
    }
}
