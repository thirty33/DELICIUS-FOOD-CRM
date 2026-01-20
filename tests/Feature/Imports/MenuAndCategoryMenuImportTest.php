<?php

namespace Tests\Feature\Imports;

use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ImportProcess;
use App\Services\ImportService;
use App\Imports\MenusImport;
use App\Imports\CategoryMenuImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu and CategoryMenu Import Test
 *
 * This test validates that:
 * 1. A menu can be imported from an Excel file
 * 2. Category menus can be imported after the menu exists
 * 3. Data is stored correctly in the database
 *
 * Test Data:
 * - test_menus_import.xlsx: Contains 2 menus
 * - test_category_menu_import.xlsx: Contains 5 category menus for TEST MENU 01
 */
class MenuAndCategoryMenuImportTest extends TestCase
{
    use RefreshDatabase;

    private Role $role;
    private Permission $permission;

    protected function setUp(): void
    {
        parent::setUp();

        // Create required role and permission for menu imports
        $this->role = Role::create([
            'name' => 'Convenio',
            'guard_name' => 'web',
        ]);

        $this->permission = Permission::create([
            'name' => 'Individual',
            'guard_name' => 'web',
        ]);
    }

    /**
     * Helper: Create test categories
     */
    private function createTestCategories(): array
    {
        $categories = [];
        for ($i = 1; $i <= 5; $i++) {
            $categories[] = Category::create([
                'name' => "TEST CATEGORY {$i}",
                'code' => "TC{$i}",
                'description' => "Test Category {$i}",
                'active' => true,
            ]);
        }
        return $categories;
    }

    /**
     * Helper: Create test products for categories
     */
    private function createTestProducts(array $categories): array
    {
        $products = [];

        // Create products for category 3 (which needs specific products)
        $products[] = Product::create([
            'name' => 'Test Product 001',
            'description' => 'Product for testing',
            'code' => 'TESTPROD001',
            'category_id' => $categories[2]->id, // Category 3
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $products[] = Product::create([
            'name' => 'Test Product 002',
            'description' => 'Product for testing',
            'code' => 'TESTPROD002',
            'category_id' => $categories[2]->id, // Category 3
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create product for category 4
        $products[] = Product::create([
            'name' => 'Test Product 003',
            'description' => 'Product for testing',
            'code' => 'TESTPROD003',
            'category_id' => $categories[3]->id, // Category 4
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        return $products;
    }

    /**
     * Helper: Run menu import
     */
    private function runMenuImport(string $testFile): ImportProcess
    {
        $this->assertFileExists($testFile, "Test Excel file should exist at {$testFile}");

        $importService = app(ImportService::class);

        $importProcess = $importService->import(
            MenusImport::class,
            $testFile,
            ImportProcess::TYPE_MENUS
        );

        return $importProcess;
    }

    /**
     * Helper: Run category menu import
     */
    private function runCategoryMenuImport(string $testFile): ImportProcess
    {
        $this->assertFileExists($testFile, "Test Excel file should exist at {$testFile}");

        $importService = app(ImportService::class);

        $importProcess = $importService->import(
            CategoryMenuImport::class,
            $testFile,
            ImportProcess::TYPE_MENU_CATEGORIES
        );

        return $importProcess;
    }

    /**
     * Test: Import menus from Excel file
     */
    public function test_imports_menu_from_excel_file(): void
    {
        // Verify role and permission exist
        $this->assertNotNull(Role::where('name', 'Convenio')->first(), 'Role Convenio should exist');
        $this->assertNotNull(Permission::where('name', 'Individual')->first(), 'Permission Individual should exist');

        // Import menus
        $testFile = base_path('tests/Fixtures/test_menus_import.xlsx');
        $importProcess = $this->runMenuImport($testFile);

        // Verify import completed successfully
        $importService = app(ImportService::class);
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Menu import should complete successfully. Status: ' . $importProcess->status .
            ' Errors: ' . json_encode($importProcess->error_log)
        );

        // Verify menus were created
        $menu1 = Menu::where('title', 'TEST MENU 01')->first();
        $menu2 = Menu::where('title', 'TEST MENU 02')->first();

        $this->assertNotNull($menu1, 'Menu 01 should be created');
        $this->assertNotNull($menu2, 'Menu 02 should be created');

        // Verify menu 1 data
        $this->assertEquals('Menu description 1', $menu1->description);
        $this->assertEquals('2026-02-01', $menu1->publication_date);
        $this->assertEquals($this->role->id, $menu1->role_id);
        $this->assertEquals($this->permission->id, $menu1->permissions_id);
        $this->assertTrue($menu1->active);

        // Verify menu 2 data
        $this->assertEquals('Menu description 2', $menu2->description);
        $this->assertEquals('2026-02-02', $menu2->publication_date);
    }

    /**
     * Test: Import category menus after menu import
     */
    public function test_imports_five_category_menus_after_menu_import(): void
    {
        // Create categories first
        $categories = $this->createTestCategories();

        // Create products for categories that need specific products
        $products = $this->createTestProducts($categories);

        // Import menus first
        $menuFile = base_path('tests/Fixtures/test_menus_import.xlsx');
        $menuImportProcess = $this->runMenuImport($menuFile);

        $importService = app(ImportService::class);
        $this->assertTrue(
            $importService->wasSuccessful($menuImportProcess),
            'Menu import should complete successfully. Status: ' . $menuImportProcess->status .
            ' Errors: ' . json_encode($menuImportProcess->error_log)
        );

        // Verify menu was created
        $menu = Menu::where('title', 'TEST MENU 01')->first();
        $this->assertNotNull($menu, 'TEST MENU 01 should be created before importing category menus');

        // Import category menus
        $categoryMenuFile = base_path('tests/Fixtures/test_category_menu_import.xlsx');
        $categoryMenuImportProcess = $this->runCategoryMenuImport($categoryMenuFile);

        $this->assertTrue(
            $importService->wasSuccessful($categoryMenuImportProcess),
            'Category Menu import should complete successfully. Status: ' . $categoryMenuImportProcess->status .
            ' Errors: ' . json_encode($categoryMenuImportProcess->error_log)
        );

        // Verify 5 category menus were created for TEST MENU 01
        $categoryMenus = CategoryMenu::where('menu_id', $menu->id)->get();
        $this->assertEquals(5, $categoryMenus->count(), 'Should have 5 category menus');

        // Verify category menu data
        $categoryMenu1 = CategoryMenu::where('menu_id', $menu->id)
            ->where('category_id', $categories[0]->id)
            ->first();

        $this->assertNotNull($categoryMenu1, 'Category Menu 1 should exist');
        $this->assertTrue($categoryMenu1->show_all_products, 'Category 1 should show all products');
        $this->assertEquals(1, $categoryMenu1->display_order, 'Category 1 display order should be 1');
        $this->assertFalse($categoryMenu1->mandatory_category, 'Category 1 should not be mandatory');
        $this->assertTrue($categoryMenu1->is_active, 'Category 1 should be active');

        // Verify category menu 3 (with specific products)
        $categoryMenu3 = CategoryMenu::where('menu_id', $menu->id)
            ->where('category_id', $categories[2]->id)
            ->first();

        $this->assertNotNull($categoryMenu3, 'Category Menu 3 should exist');
        $this->assertFalse($categoryMenu3->show_all_products, 'Category 3 should NOT show all products');
        $this->assertEquals(3, $categoryMenu3->display_order, 'Category 3 display order should be 3');
        $this->assertTrue($categoryMenu3->mandatory_category, 'Category 3 should be mandatory');
        $this->assertTrue($categoryMenu3->is_active, 'Category 3 should be active');

        // Verify category menu 3 has 2 products associated
        $this->assertEquals(2, $categoryMenu3->products->count(), 'Category Menu 3 should have 2 products');

        // ===== TDD RED PHASE: Verify pivot display_order = 9999 for category menu 3 products =====
        // This will FAIL until the display_order column is added to category_menu_product pivot table
        foreach ($categoryMenu3->products as $product) {
            $this->assertEquals(
                9999,
                $product->pivot->display_order,
                "Product {$product->code} in CategoryMenu 3 should have pivot display_order = 9999. " .
                "TDD RED PHASE: This fails until display_order column is added to category_menu_product table."
            );
        }

        // Verify category menu 4 (with 1 specific product)
        $categoryMenu4 = CategoryMenu::where('menu_id', $menu->id)
            ->where('category_id', $categories[3]->id)
            ->first();

        $this->assertNotNull($categoryMenu4, 'Category Menu 4 should exist');
        $this->assertFalse($categoryMenu4->show_all_products, 'Category 4 should NOT show all products');
        $this->assertEquals(1, $categoryMenu4->products->count(), 'Category Menu 4 should have 1 product');

        // ===== TDD RED PHASE: Verify pivot display_order = 9999 for category menu 4 product =====
        foreach ($categoryMenu4->products as $product) {
            $this->assertEquals(
                9999,
                $product->pivot->display_order,
                "Product {$product->code} in CategoryMenu 4 should have pivot display_order = 9999. " .
                "TDD RED PHASE: This fails until display_order column is added to category_menu_product table."
            );
        }
    }

    /**
     * Test: Verify display order is set correctly for all category menus
     */
    public function test_category_menu_display_order_is_correct(): void
    {
        // Create categories first
        $categories = $this->createTestCategories();

        // Create products for categories that need specific products
        $products = $this->createTestProducts($categories);

        // Import menus first
        $menuFile = base_path('tests/Fixtures/test_menus_import.xlsx');
        $this->runMenuImport($menuFile);

        // Verify menu was created
        $menu = Menu::where('title', 'TEST MENU 01')->first();
        $this->assertNotNull($menu);

        // Import category menus
        $categoryMenuFile = base_path('tests/Fixtures/test_category_menu_import.xlsx');
        $categoryMenuImportProcess = $this->runCategoryMenuImport($categoryMenuFile);

        $importService = app(ImportService::class);
        $this->assertTrue(
            $importService->wasSuccessful($categoryMenuImportProcess),
            'Category Menu import should complete successfully'
        );

        // Verify display order for each category menu
        $categoryMenus = CategoryMenu::where('menu_id', $menu->id)
            ->orderBy('display_order')
            ->get();

        $expectedOrders = [1, 2, 3, 4, 5];
        foreach ($categoryMenus as $index => $categoryMenu) {
            $this->assertEquals(
                $expectedOrders[$index],
                $categoryMenu->display_order,
                "Category menu at index {$index} should have display_order {$expectedOrders[$index]}"
            );
        }
    }

    /**
     * TDD RED PHASE TEST
     *
     * Test: When importing CategoryMenu with show_all_products = true,
     * ALL products from that category should be attached to the pivot table.
     *
     * CURRENT BEHAVIOR (CategoryMenuImport.php:301-305):
     * - Only syncs products when show_all_products = false
     * - When show_all_products = true, NO pivot records are created
     *
     * EXPECTED BEHAVIOR:
     * - When show_all_products = true, the importer should:
     *   1. Find ALL products that belong to the category
     *   2. Create pivot records in category_menu_product for each product
     *
     * WHY THIS MATTERS:
     * - Ensures data consistency between UI display and database state
     * - When "show all products" is true, the pivot table should reflect this
     * - Allows queries on category_menu_product to work correctly regardless of show_all_products flag
     *
     * TEST DATA:
     * - Category: SHOW ALL CATEGORY with 5 products
     * - CategoryMenu: show_all_products = true
     * - Expected: 5 pivot records in category_menu_product
     *
     * THIS TEST WILL FAIL (RED PHASE) because the current implementation
     * does not sync products when show_all_products = true.
     */
    public function test_show_all_products_creates_pivot_records_for_all_category_products(): void
    {
        // ===== 1. CREATE CATEGORY WITH 5 PRODUCTS =====
        $category = Category::create([
            'name' => 'SHOW ALL CATEGORY',
            'code' => 'SAC',
            'description' => 'Category for testing show_all_products pivot creation',
            'active' => true,
        ]);

        // Create 5 products for this category
        $products = [];
        for ($i = 1; $i <= 5; $i++) {
            $products[] = Product::create([
                'name' => "Show All Product {$i}",
                'description' => "Product {$i} for show_all_products test",
                'code' => "SAPROD00{$i}",
                'category_id' => $category->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);
        }

        // Verify 5 products exist in this category
        $this->assertEquals(5, Product::where('category_id', $category->id)->count());

        // ===== 2. CREATE MENU =====
        $menu = Menu::create([
            'title' => 'SHOW ALL PRODUCTS MENU',
            'description' => 'Menu for testing show_all_products',
            'publication_date' => '2026-02-15',
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'max_order_date' => '2026-02-14 18:00:00',
            'active' => true,
        ]);

        // ===== 3. IMPORT CATEGORY MENU WITH show_all_products = true =====
        $testFile = base_path('tests/Fixtures/test_category_menu_show_all_products.xlsx');
        $importProcess = $this->runCategoryMenuImport($testFile);

        $importService = app(ImportService::class);
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'CategoryMenu import should complete successfully. Status: ' . $importProcess->status .
            ' Errors: ' . json_encode($importProcess->error_log)
        );

        // ===== 4. VERIFY CATEGORY MENU WAS CREATED =====
        $categoryMenu = CategoryMenu::where('menu_id', $menu->id)
            ->where('category_id', $category->id)
            ->first();

        $this->assertNotNull($categoryMenu, 'CategoryMenu should be created');
        $this->assertTrue($categoryMenu->show_all_products, 'show_all_products should be true');

        // ===== 5. VERIFY PIVOT RECORDS WERE CREATED FOR ALL 5 PRODUCTS =====
        // THIS IS THE KEY ASSERTION - Currently this will FAIL (RED PHASE)
        // because CategoryMenuImport does NOT sync products when show_all_products = true

        $pivotCount = $categoryMenu->products()->count();

        $this->assertEquals(
            5,
            $pivotCount,
            "When show_all_products = true, ALL 5 products from the category should be attached to the pivot table. " .
            "Currently found: {$pivotCount} pivot records. " .
            "This test is TDD RED PHASE - it will fail until CategoryMenuImport is updated to sync all products when show_all_products = true."
        );

        // Verify each product is attached
        foreach ($products as $product) {
            $this->assertTrue(
                $categoryMenu->products->contains('id', $product->id),
                "Product {$product->code} should be attached to the CategoryMenu pivot"
            );
        }

        // ===== TDD RED PHASE: Verify pivot display_order = 9999 for all products =====
        // This will FAIL until the display_order column is added to category_menu_product pivot table
        foreach ($categoryMenu->products as $product) {
            $this->assertEquals(
                9999,
                $product->pivot->display_order,
                "Product {$product->code} should have pivot display_order = 9999. " .
                "TDD RED PHASE: This fails until display_order column is added to category_menu_product table."
            );
        }
    }

    /**
     * TDD RED PHASE TEST
     *
     * Test: Import CategoryMenu with product display_order values from Excel
     *
     * NEW FORMAT:
     * The "Productos" column now supports a new format: "CODE:ORDER,CODE:ORDER"
     * Example: "PIVPROD001:10,PIVPROD002:20,PIVPROD003:5"
     *
     * This allows specifying the display_order for each product in the pivot table
     * (category_menu_product.display_order)
     *
     * CURRENT BEHAVIOR:
     * - Import only parses product codes: "PROD001,PROD002"
     * - All pivot records get default display_order = 9999
     *
     * EXPECTED BEHAVIOR:
     * - Import should parse the new format: "PROD001:10,PROD002:20"
     * - Pivot records should have the specified display_order values
     * - If no order specified (old format), use default 9999
     *
     * SCENARIO 2 (TDD RED PHASE):
     * When show_all_products = true AND products with display_order are specified:
     * - ALL products from the category should be attached
     * - The specified products should have their custom display_order
     * - The rest of the products should have default display_order (9999)
     */
    public function test_imports_product_display_order_from_new_format(): void
    {
        // ===== 1. CREATE CATEGORY 1: show_all_products = false =====
        $category1 = Category::create([
            'name' => 'PIVOT ORDER CATEGORY',
            'code' => 'POC',
            'description' => 'Category for testing pivot display_order import',
            'active' => true,
        ]);

        // ===== 2. CREATE 3 PRODUCTS FOR CATEGORY 1 =====
        $product1 = Product::create([
            'name' => 'Pivot Order Product 1',
            'description' => 'Product 1 for pivot order test',
            'code' => 'PIVPROD001',
            'category_id' => $category1->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $product2 = Product::create([
            'name' => 'Pivot Order Product 2',
            'description' => 'Product 2 for pivot order test',
            'code' => 'PIVPROD002',
            'category_id' => $category1->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $product3 = Product::create([
            'name' => 'Pivot Order Product 3',
            'description' => 'Product 3 for pivot order test',
            'code' => 'PIVPROD003',
            'category_id' => $category1->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // ===== 3. CREATE CATEGORY 2: show_all_products = true with custom orders =====
        $category2 = Category::create([
            'name' => 'SHOW ALL WITH ORDER CATEGORY',
            'code' => 'SAOC',
            'description' => 'Category for testing show_all_products with custom display_order',
            'active' => true,
        ]);

        // ===== 4. CREATE 5 PRODUCTS FOR CATEGORY 2 (3 with custom order + 2 default) =====
        $saoProduct1 = Product::create([
            'name' => 'SAO Product 1',
            'description' => 'Product 1 with custom order',
            'code' => 'SAOPROD001',
            'category_id' => $category2->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $saoProduct2 = Product::create([
            'name' => 'SAO Product 2',
            'description' => 'Product 2 with custom order',
            'code' => 'SAOPROD002',
            'category_id' => $category2->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $saoProduct3 = Product::create([
            'name' => 'SAO Product 3',
            'description' => 'Product 3 with custom order',
            'code' => 'SAOPROD003',
            'category_id' => $category2->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // These 2 products are NOT in Excel, should get default display_order = 9999
        $saoProduct4 = Product::create([
            'name' => 'SAO Product 4',
            'description' => 'Product 4 without custom order',
            'code' => 'SAOPROD004',
            'category_id' => $category2->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $saoProduct5 = Product::create([
            'name' => 'SAO Product 5',
            'description' => 'Product 5 without custom order',
            'code' => 'SAOPROD005',
            'category_id' => $category2->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // ===== 5. CREATE MENU =====
        $menu = Menu::create([
            'title' => 'PIVOT ORDER TEST MENU',
            'description' => 'Menu for testing pivot display_order import',
            'publication_date' => '2026-03-01',
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'max_order_date' => '2026-02-28 18:00:00',
            'active' => true,
        ]);

        // ===== 6. IMPORT CATEGORY MENUS =====
        $testFile = base_path('tests/Fixtures/test_category_menu_pivot_display_order.xlsx');
        $importProcess = $this->runCategoryMenuImport($testFile);

        $importService = app(ImportService::class);
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'CategoryMenu import should complete successfully. Status: ' . $importProcess->status .
            ' Errors: ' . json_encode($importProcess->error_log)
        );

        // ===== 7. VERIFY CATEGORY MENU 1 (show_all_products = false) =====
        $categoryMenu1 = CategoryMenu::where('menu_id', $menu->id)
            ->where('category_id', $category1->id)
            ->first();

        $this->assertNotNull($categoryMenu1, 'CategoryMenu 1 should be created');
        $this->assertFalse($categoryMenu1->show_all_products, 'show_all_products should be false');
        $this->assertEquals(3, $categoryMenu1->products()->count(), 'Should have 3 products attached');

        // Verify display_order for category 1 products
        $pivotProduct1 = $categoryMenu1->products()->where('product_id', $product1->id)->first();
        $this->assertEquals(10, $pivotProduct1->pivot->display_order, 'PIVPROD001 should have display_order = 10');

        $pivotProduct2 = $categoryMenu1->products()->where('product_id', $product2->id)->first();
        $this->assertEquals(20, $pivotProduct2->pivot->display_order, 'PIVPROD002 should have display_order = 20');

        $pivotProduct3 = $categoryMenu1->products()->where('product_id', $product3->id)->first();
        $this->assertEquals(5, $pivotProduct3->pivot->display_order, 'PIVPROD003 should have display_order = 5');

        // ===== 8. VERIFY CATEGORY MENU 2 (show_all_products = true with custom orders) =====
        // TDD RED PHASE: This section will FAIL because current implementation
        // ignores the "Productos" column when show_all_products = true

        $categoryMenu2 = CategoryMenu::where('menu_id', $menu->id)
            ->where('category_id', $category2->id)
            ->first();

        $this->assertNotNull($categoryMenu2, 'CategoryMenu 2 should be created');
        $this->assertTrue($categoryMenu2->show_all_products, 'show_all_products should be true');

        // ALL 5 products should be attached (because show_all_products = true)
        $this->assertEquals(
            5,
            $categoryMenu2->products()->count(),
            'Should have ALL 5 products attached when show_all_products = true'
        );

        // Products specified in Excel should have custom display_order
        $saoPivot1 = $categoryMenu2->products()->where('product_id', $saoProduct1->id)->first();
        $this->assertNotNull($saoPivot1, 'SAOPROD001 should be attached');
        $this->assertEquals(
            1,
            $saoPivot1->pivot->display_order,
            "SAOPROD001 should have display_order = 1 (from Excel). " .
            "TDD RED: Fails because show_all_products=true ignores Productos column."
        );

        $saoPivot2 = $categoryMenu2->products()->where('product_id', $saoProduct2->id)->first();
        $this->assertNotNull($saoPivot2, 'SAOPROD002 should be attached');
        $this->assertEquals(
            2,
            $saoPivot2->pivot->display_order,
            "SAOPROD002 should have display_order = 2 (from Excel). " .
            "TDD RED: Fails because show_all_products=true ignores Productos column."
        );

        $saoPivot3 = $categoryMenu2->products()->where('product_id', $saoProduct3->id)->first();
        $this->assertNotNull($saoPivot3, 'SAOPROD003 should be attached');
        $this->assertEquals(
            3,
            $saoPivot3->pivot->display_order,
            "SAOPROD003 should have display_order = 3 (from Excel). " .
            "TDD RED: Fails because show_all_products=true ignores Productos column."
        );

        // Products NOT in Excel should have default display_order = 9999
        $saoPivot4 = $categoryMenu2->products()->where('product_id', $saoProduct4->id)->first();
        $this->assertNotNull($saoPivot4, 'SAOPROD004 should be attached');
        $this->assertEquals(
            9999,
            $saoPivot4->pivot->display_order,
            'SAOPROD004 should have default display_order = 9999'
        );

        $saoPivot5 = $categoryMenu2->products()->where('product_id', $saoProduct5->id)->first();
        $this->assertNotNull($saoPivot5, 'SAOPROD005 should be attached');
        $this->assertEquals(
            9999,
            $saoPivot5->pivot->display_order,
            'SAOPROD005 should have default display_order = 9999'
        );
    }
}
