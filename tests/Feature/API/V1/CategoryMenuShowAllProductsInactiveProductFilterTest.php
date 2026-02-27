<?php

namespace Tests\Feature\API\V1;

use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\Menu;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bug: PriceListFilter (show_all_products=true) does not check products.active,
 * causing category_menus to pass the filter when only inactive products have prices.
 * The eager load constraint on `category` DOES check products.active, so the category
 * loads as null. The frontend receives a category_menu with category=null and shows
 * "No disponible".
 *
 * Production case: Category 82 "PRODUCTOS GRANEL" in menu 1260.
 * Product 1117 (inactive) has price in list 57, products 1120/1122/1952 (active) only
 * have prices in list 52.
 *
 * EXPECTED: category_menu should be excluded from results entirely when
 * show_all_products=true and no ACTIVE products have prices in the user's price list.
 */
class CategoryMenuShowAllProductsInactiveProductFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $testUser;

    private Company $testCompany;

    private PriceList $testPriceList;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-02-27 10:00:00');

        $this->testPriceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'active' => true,
        ]);

        $this->testCompany = Company::create([
            'name' => 'TEST COMPANY',
            'fantasy_name' => 'TEST',
            'rut' => '11111111-1',
            'address' => 'Test Address',
            'phone' => '+56900000000',
            'email' => 'test@test.com',
            'active' => true,
            'price_list_id' => $this->testPriceList->id,
        ]);

        $this->testUser = User::create([
            'name' => 'TEST USER',
            'nickname' => 'TEST.USER',
            'email' => 'testuser@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->testCompany->id,
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * When show_all_products=true and the only product with a price in the user's
     * price list is INACTIVE, the category_menu must NOT appear in the API response.
     */
    public function test_show_all_products_excludes_category_when_only_inactive_products_have_prices(): void
    {
        $menu = Menu::create([
            'title' => 'TEST MENU',
            'publication_date' => '2026-02-28',
            'active' => true,
        ]);

        $otherPriceList = PriceList::create([
            'name' => 'OTHER PRICE LIST',
            'active' => true,
        ]);

        // Category with active products that have prices in a DIFFERENT list
        $category = Category::create([
            'name' => 'TEST PRODUCTOS GRANEL',
            'is_active' => true,
        ]);

        $activeProduct1 = Product::create([
            'name' => 'ACTIVE PRODUCT 1',
            'description' => 'Active product description',
            'code' => 'ACT001',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Active product has price in OTHER list, not user's list
        PriceListLine::create([
            'price_list_id' => $otherPriceList->id,
            'product_id' => $activeProduct1->id,
            'unit_price' => 5000,
            'active' => true,
        ]);

        // Inactive product with price in user's list (the root cause of the bug)
        $inactiveProduct = Product::create([
            'name' => 'INACTIVE PRODUCT WITH PRICE',
            'description' => 'Inactive product description',
            'code' => 'INACT001',
            'category_id' => $category->id,
            'active' => false,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $inactiveProduct->id,
            'unit_price' => 3000,
            'active' => true,
        ]);

        // CategoryMenu with show_all_products = true
        CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => 100,
            'show_all_products' => true,
            'is_active' => true,
        ]);

        // A second category that SHOULD appear (active product with price in user's list)
        $validCategory = Category::create([
            'name' => 'TEST VALID CATEGORY',
            'is_active' => true,
        ]);

        $validProduct = Product::create([
            'name' => 'VALID PRODUCT',
            'description' => 'Valid product description',
            'code' => 'VAL001',
            'category_id' => $validCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->testPriceList->id,
            'product_id' => $validProduct->id,
            'unit_price' => 2000,
            'active' => true,
        ]);

        CategoryMenu::create([
            'category_id' => $validCategory->id,
            'menu_id' => $menu->id,
            'display_order' => 200,
            'show_all_products' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->testUser);

        $response = $this->getJson("/api/v1/categories/{$menu->id}?page=1");

        $response->assertStatus(200);

        $data = $response->json('data.data');

        // Should return only the valid category, not the one with only inactive priced products
        $this->assertCount(1, $data, 'Should return only 1 category (the one with active priced products)');

        // The returned category must have a non-null category object
        $this->assertNotNull($data[0]['category'], 'Category object must not be null');
        $this->assertEquals('TEST VALID CATEGORY', $data[0]['category']['name']);

        // Verify that no category_menu in the response has category=null
        $nullCategories = collect($data)->filter(fn ($cm) => $cm['category'] === null);
        $this->assertCount(0, $nullCategories, 'No category_menu should have category=null in the response');
    }
}
