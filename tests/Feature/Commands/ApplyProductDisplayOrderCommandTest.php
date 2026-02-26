<?php

namespace Tests\Feature\Commands;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\Menu;
use App\Models\Parameter;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TDD Red Phase - ApplyProductDisplayOrderCommand Tests
 *
 * These tests validate the behavior of the new command that copies
 * products.display_order to category_menu_product.display_order.
 *
 * They will FAIL until:
 * 1. 'display_order' field is added to products table
 * 2. Parameter::PRODUCT_DISPLAY_ORDER_AUTO_APPLY constant is created
 * 3. ApplyProductDisplayOrderCommand is implemented
 * 4. ApplyProductDisplayOrderJob is implemented
 */
class ApplyProductDisplayOrderCommandTest extends TestCase
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

        $this->cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $this->otherRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $this->consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        $this->priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'description' => 'Test',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $this->company = Company::create([
            'name' => 'TEST COMPANY',
            'fantasy_name' => 'TEST',
            'address' => 'Test Address',
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
            'address' => 'Test Address',
            'company_id' => $this->company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        $this->createParameter();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createParameter(): void
    {
        Parameter::firstOrCreate(
            ['name' => Parameter::PRODUCT_DISPLAY_ORDER_AUTO_APPLY],
            [
                'description' => 'Auto apply product display order to menus',
                'value_type' => 'boolean',
                'value' => '1',
                'active' => true,
            ]
        );
    }

    protected function createCafeMenu(string $publicationDate, ?string $title = null, bool $productsOrdered = false): Menu
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

    protected function createCategoryWithProducts(string $categoryName, array $displayOrders, bool $isDynamic = false): array
    {
        $category = Category::create([
            'name' => $categoryName,
            'description' => "Test: {$categoryName}",
            'is_active' => true,
            'is_dynamic' => $isDynamic,
        ]);

        $products = [];
        foreach ($displayOrders as $i => $order) {
            $products[] = Product::create([
                'name' => "{$categoryName} - Product ".($i + 1),
                'code' => strtoupper(substr($categoryName, 0, 3)).'-'.($i + 1).'-'.uniqid(),
                'description' => 'Test product',
                'category_id' => $category->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
                'display_order' => $order,
            ]);
        }

        return ['category' => $category, 'products' => $products];
    }

    // =========================================================================
    // TEST 1: Applies product display_order to category_menu_product
    // =========================================================================

    public function test_applies_product_display_order_to_category_menu_products(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        // Products with display_order = 1, 3, 2
        $data = $this->createCategoryWithProducts('SANDWICHES', [1, 3, 2]);
        $menu = $this->createCafeMenu('2026-02-27');

        $categoryMenu = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $data['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        // Attach with arbitrary initial order (10, 20, 30)
        $categoryMenu->products()->attach([
            $data['products'][0]->id => ['display_order' => 10],
            $data['products'][1]->id => ['display_order' => 20],
            $data['products'][2]->id => ['display_order' => 30],
        ]);

        $this->artisan('menus:apply-product-display-order')
            ->assertSuccessful();

        // Verify display_order was copied from products table
        $pivotProducts = $categoryMenu->products()
            ->orderBy('category_menu_product.display_order')
            ->get();

        $this->assertEquals(1, $pivotProducts[0]->pivot->display_order, 'Product 1 should have display_order 1');
        $this->assertEquals(2, $pivotProducts[1]->pivot->display_order, 'Product 3 should have display_order 2');
        $this->assertEquals(3, $pivotProducts[2]->pivot->display_order, 'Product 2 should have display_order 3');
    }

    // =========================================================================
    // TEST 2: Skips menus already processed
    // =========================================================================

    public function test_skips_menus_already_processed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        $data = $this->createCategoryWithProducts('SANDWICHES', [5, 1]);

        // Menu already processed
        $menu = $this->createCafeMenu('2026-02-27', 'ALREADY PROCESSED', true);

        $categoryMenu = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $data['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $categoryMenu->products()->attach([
            $data['products'][0]->id => ['display_order' => 99],
            $data['products'][1]->id => ['display_order' => 88],
        ]);

        $this->artisan('menus:apply-product-display-order')
            ->assertSuccessful();

        // Pivot display_order should remain unchanged
        $pivot0 = $categoryMenu->products()->where('product_id', $data['products'][0]->id)->first()->pivot;
        $pivot1 = $categoryMenu->products()->where('product_id', $data['products'][1]->id)->first()->pivot;

        $this->assertEquals(99, $pivot0->display_order, 'Should not modify already processed menu');
        $this->assertEquals(88, $pivot1->display_order, 'Should not modify already processed menu');
    }

    // =========================================================================
    // TEST 3: Only processes Cafe menus
    // =========================================================================

    public function test_only_processes_cafe_menus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        $data = $this->createCategoryWithProducts('SANDWICHES', [1, 2]);

        // Agreement menu (not Cafe) - should NOT be processed
        $agreementMenu = Menu::create([
            'title' => 'AGREEMENT MENU',
            'publication_date' => '2026-02-27',
            'max_order_date' => '2026-02-26 15:30:00',
            'role_id' => $this->otherRole->id,
            'permissions_id' => $this->consolidadoPermission->id,
            'active' => true,
            'products_ordered' => false,
        ]);

        $categoryMenu = CategoryMenu::create([
            'menu_id' => $agreementMenu->id,
            'category_id' => $data['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $categoryMenu->products()->attach([
            $data['products'][0]->id => ['display_order' => 99],
            $data['products'][1]->id => ['display_order' => 88],
        ]);

        $this->artisan('menus:apply-product-display-order')
            ->assertSuccessful();

        // Agreement menu should NOT be processed
        $agreementMenu->refresh();
        $this->assertFalse($agreementMenu->products_ordered, 'Agreement menu should not be processed');

        // Pivot should remain unchanged
        $pivot0 = $categoryMenu->products()->where('product_id', $data['products'][0]->id)->first()->pivot;
        $this->assertEquals(99, $pivot0->display_order, 'Agreement menu products should not be modified');
    }

    // =========================================================================
    // TEST 4: Respects limit option
    // =========================================================================

    public function test_respects_limit_option(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        $data = $this->createCategoryWithProducts('SANDWICHES', [1]);

        // Create 3 pending menus
        $menu1 = $this->createCafeMenu('2026-02-27', 'MENU 1', false);
        $menu2 = $this->createCafeMenu('2026-02-28', 'MENU 2', false);
        $menu3 = $this->createCafeMenu('2026-03-01', 'MENU 3', false);

        foreach ([$menu1, $menu2, $menu3] as $menu) {
            $cm = CategoryMenu::create([
                'menu_id' => $menu->id,
                'category_id' => $data['category']->id,
                'display_order' => 1,
                'show_all_products' => false,
                'is_active' => true,
            ]);
            $cm->products()->attach([
                $data['products'][0]->id => ['display_order' => 99],
            ]);
        }

        $this->artisan('menus:apply-product-display-order', ['--limit' => 2])
            ->assertSuccessful();

        // Only 2 should be processed
        $processedCount = Menu::where('products_ordered', true)->count();
        $pendingCount = Menu::where('products_ordered', false)->count();

        $this->assertEquals(2, $processedCount, 'Should process only 2 menus');
        $this->assertEquals(1, $pendingCount, 'Third menu should remain pending');
    }

    // =========================================================================
    // TEST 5: Marks menu as processed after completion
    // =========================================================================

    public function test_marks_menu_as_processed_after_completion(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        $data = $this->createCategoryWithProducts('SANDWICHES', [3]);
        $menu = $this->createCafeMenu('2026-02-27', 'PROCESS ME', false);

        $categoryMenu = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $data['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $categoryMenu->products()->attach([
            $data['products'][0]->id => ['display_order' => 99],
        ]);

        $this->assertFalse($menu->products_ordered);

        $this->artisan('menus:apply-product-display-order')
            ->assertSuccessful();

        $menu->refresh();
        $this->assertTrue($menu->products_ordered, 'Menu should be marked as products_ordered after processing');
    }

    // =========================================================================
    // TEST 6: Excludes dynamic categories
    // =========================================================================

    public function test_excludes_dynamic_categories(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        $regularData = $this->createCategoryWithProducts('REGULAR', [2, 5], false);
        $dynamicData = $this->createCategoryWithProducts('DYNAMIC', [1, 3], true);

        $menu = $this->createCafeMenu('2026-02-27');

        $regularCm = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $regularData['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $regularCm->products()->attach([
            $regularData['products'][0]->id => ['display_order' => 99],
            $regularData['products'][1]->id => ['display_order' => 88],
        ]);

        $dynamicCm = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $dynamicData['category']->id,
            'display_order' => 2,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $dynamicCm->products()->attach([
            $dynamicData['products'][0]->id => ['display_order' => 99],
            $dynamicData['products'][1]->id => ['display_order' => 88],
        ]);

        $this->artisan('menus:apply-product-display-order')
            ->assertSuccessful();

        // Regular category: display_order should be updated from products
        $regularPivot0 = $regularCm->products()->where('product_id', $regularData['products'][0]->id)->first()->pivot;
        $regularPivot1 = $regularCm->products()->where('product_id', $regularData['products'][1]->id)->first()->pivot;
        $this->assertEquals(2, $regularPivot0->display_order, 'Regular product 1 should get display_order from products table');
        $this->assertEquals(5, $regularPivot1->display_order, 'Regular product 2 should get display_order from products table');

        // Dynamic category: display_order should remain unchanged
        $dynamicPivot0 = $dynamicCm->products()->where('product_id', $dynamicData['products'][0]->id)->first()->pivot;
        $dynamicPivot1 = $dynamicCm->products()->where('product_id', $dynamicData['products'][1]->id)->first()->pivot;
        $this->assertEquals(99, $dynamicPivot0->display_order, 'Dynamic product should not be modified');
        $this->assertEquals(88, $dynamicPivot1->display_order, 'Dynamic product should not be modified');
    }

    // =========================================================================
    // TEST 7: Uses default display_order when product has no value
    // =========================================================================

    public function test_uses_default_display_order_when_product_has_no_value(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        // Product with default display_order (9999)
        $data = $this->createCategoryWithProducts('SANDWICHES', [9999]);
        $menu = $this->createCafeMenu('2026-02-27');

        $categoryMenu = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $data['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $categoryMenu->products()->attach([
            $data['products'][0]->id => ['display_order' => 1],
        ]);

        $this->artisan('menus:apply-product-display-order')
            ->assertSuccessful();

        $pivot = $categoryMenu->products()->where('product_id', $data['products'][0]->id)->first()->pivot;
        $this->assertEquals(9999, $pivot->display_order, 'Should copy default 9999 from product to pivot');
    }

    // =========================================================================
    // TEST 8: Orders menus by created_at desc (most recent first)
    // =========================================================================

    public function test_orders_menus_by_created_at_desc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        $data = $this->createCategoryWithProducts('SANDWICHES', [1]);

        // Create older menu first
        $olderMenu = $this->createCafeMenu('2026-02-27', 'OLDER MENU', false);
        $olderMenu->created_at = Carbon::parse('2026-02-25 08:00:00');
        $olderMenu->saveQuietly();

        // Create newer menu
        $newerMenu = $this->createCafeMenu('2026-02-28', 'NEWER MENU', false);
        $newerMenu->created_at = Carbon::parse('2026-02-26 08:00:00');
        $newerMenu->saveQuietly();

        foreach ([$olderMenu, $newerMenu] as $menu) {
            $cm = CategoryMenu::create([
                'menu_id' => $menu->id,
                'category_id' => $data['category']->id,
                'display_order' => 1,
                'show_all_products' => false,
                'is_active' => true,
            ]);
            $cm->products()->attach([
                $data['products'][0]->id => ['display_order' => 99],
            ]);
        }

        // Limit to 1 - should process the NEWER menu first (created_at desc)
        $this->artisan('menus:apply-product-display-order', ['--limit' => 1])
            ->assertSuccessful();

        $newerMenu->refresh();
        $olderMenu->refresh();

        $this->assertTrue($newerMenu->products_ordered, 'Newer menu should be processed first');
        $this->assertFalse($olderMenu->products_ordered, 'Older menu should remain pending');
    }

    // =========================================================================
    // TEST 9: Does not run when parameter disabled
    // =========================================================================

    public function test_does_not_run_when_parameter_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-26 10:00:00'));

        // Disable the parameter
        Parameter::where('name', Parameter::PRODUCT_DISPLAY_ORDER_AUTO_APPLY)
            ->update(['value' => '0']);

        $data = $this->createCategoryWithProducts('SANDWICHES', [1]);
        $menu = $this->createCafeMenu('2026-02-27', 'SHOULD NOT PROCESS', false);

        $categoryMenu = CategoryMenu::create([
            'menu_id' => $menu->id,
            'category_id' => $data['category']->id,
            'display_order' => 1,
            'show_all_products' => false,
            'is_active' => true,
        ]);

        $categoryMenu->products()->attach([
            $data['products'][0]->id => ['display_order' => 99],
        ]);

        $this->artisan('menus:apply-product-display-order')
            ->assertSuccessful()
            ->expectsOutput('Auto-apply of product display order is disabled.');

        // Menu should NOT be processed
        $menu->refresh();
        $this->assertFalse($menu->products_ordered, 'Menu should not be processed when parameter is disabled');

        // Pivot should remain unchanged
        $pivot = $categoryMenu->products()->where('product_id', $data['products'][0]->id)->first()->pivot;
        $this->assertEquals(99, $pivot->display_order, 'Pivot should not be modified when parameter is disabled');
    }
}
