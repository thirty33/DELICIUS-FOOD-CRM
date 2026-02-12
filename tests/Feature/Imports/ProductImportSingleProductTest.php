<?php

namespace Tests\Feature\Imports;

use App\Imports\ProductsImport;
use App\Models\Category;
use App\Models\ImportProcess;
use App\Models\Ingredient;
use App\Models\MasterCategory;
use App\Models\Product;
use App\Models\ProductionArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * Product Import Test - Single Product with All Fields
 *
 * This test validates the complete import flow for a single product,
 * including all fields: basic data, prices, stock, ingredients, and production areas.
 *
 * Test Data:
 * - Product: TEST-PROD-001 (TEST - Producto de Prueba)
 * - Category: MINI ENSALADAS
 * - Price: $1,250.50
 * - Price List: $1,350.75
 * - Stock: 100
 * - Weight: 0.25
 * - Ingredients: Lechuga, Tomate, Zanahoria (3 ingredients)
 * - Production Areas: CUARTO FRIO ENSALADAS, EMPLATADO (2 areas)
 * - Flags: Allow sales without stock = true, Active = true
 *
 * Expected Results:
 * - Product created with all basic fields
 * - Prices transformed correctly (dollars to cents)
 * - Stock and weight stored correctly
 * - 3 ingredients created and associated
 * - 2 production areas created (or found) and associated
 * - Boolean flags set correctly
 */
class ProductImportSingleProductTest extends TestCase
{
    use RefreshDatabase;
    use ConfiguresImportTests;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment for imports
        $this->configureImportTest();

        // Create category needed for test
        Category::create([
            'name' => 'MINI ENSALADAS',
            'code' => 'ENS',
            'active' => true,
        ]);
    }

    /**
     * Test complete product import with all fields
     */
    public function test_imports_single_product_with_all_fields_successfully(): void
    {
        // Mock S3 storage
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Arrange: Verify initial state
        $this->assertEquals(0, Product::count(), 'Should start with 0 products');
        $this->assertEquals(0, Ingredient::count(), 'Should start with 0 ingredients');
        $this->assertEquals(0, ProductionArea::count(), 'Should start with 0 production areas');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // Get test Excel file
        $testFile = base_path('tests/Fixtures/test_single_product.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        // Act: Import the Excel file
        Excel::import(
            new ProductsImport($importProcess->id),
            $testFile
        );

        // Assert: Verify import was successful
        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors'
        );

        // Verify product was created
        $this->assertEquals(1, Product::count(), 'Should have created 1 product');

        $product = Product::where('code', 'TEST-PROD-001')->first();
        $this->assertNotNull($product, 'Product TEST-PROD-001 should exist');

        // Verify basic product fields
        $this->assertEquals('TEST-PROD-001', $product->code, 'Product code should match');
        $this->assertEquals('TEST - Producto de Prueba', $product->name, 'Product name should match');
        $this->assertEquals(
            'Producto de prueba para validar importación completa',
            $product->description,
            'Product description should match'
        );
        $this->assertEquals('UND', $product->measure_unit, 'Measure unit should be UND');
        $this->assertEquals('test_producto_001.jpg', $product->original_filename, 'Original filename should match');

        // Verify category association
        $this->assertNotNull($product->category, 'Product should have category');
        $this->assertEquals('MINI ENSALADAS', $product->category->name, 'Category should be MINI ENSALADAS');

        // Verify price transformation: $1,250.50 -> 125050 cents
        $this->assertEquals(125050, $product->price, 'Price should be transformed to cents (125050)');

        // Verify price list transformation: $1,350.75 -> 135075 cents
        $this->assertEquals(135075, $product->price_list, 'Price list should be transformed to cents (135075)');

        // Verify stock (stored as integer)
        $this->assertEquals(100, $product->stock, 'Stock should be 100');

        // Verify weight (stored as float)
        $this->assertEquals(0.25, $product->weight, 'Weight should be 0.25');

        // Verify boolean flags (stored as tinyint in DB, can be 1 or true)
        $this->assertTrue((bool)$product->allow_sales_without_stock, 'Allow sales without stock should be true');
        $this->assertTrue((bool)$product->active, 'Product should be active');

        // Verify ingredients were created and associated
        $this->assertEquals(3, Ingredient::count(), 'Should have created 3 ingredients');
        $this->assertEquals(3, $product->ingredients->count(), 'Product should have 3 ingredients');

        $ingredientNames = $product->ingredients->pluck('descriptive_text')->toArray();
        $this->assertContains('Lechuga', $ingredientNames, 'Should have Lechuga ingredient');
        $this->assertContains('Tomate', $ingredientNames, 'Should have Tomate ingredient');
        $this->assertContains('Zanahoria', $ingredientNames, 'Should have Zanahoria ingredient');

        // Verify production areas were created and associated
        $this->assertEquals(2, ProductionArea::count(), 'Should have created 2 production areas');
        $this->assertEquals(2, $product->productionAreas->count(), 'Product should have 2 production areas');

        $areaNames = $product->productionAreas->pluck('name')->toArray();
        $this->assertContains('CUARTO FRIO ENSALADAS', $areaNames, 'Should have CUARTO FRIO ENSALADAS area');
        $this->assertContains('EMPLATADO', $areaNames, 'Should have EMPLATADO area');

        // Verify production areas were created in database
        $area1 = ProductionArea::where('name', 'CUARTO FRIO ENSALADAS')->first();
        $this->assertNotNull($area1, 'CUARTO FRIO ENSALADAS production area should exist');

        $area2 = ProductionArea::where('name', 'EMPLATADO')->first();
        $this->assertNotNull($area2, 'EMPLATADO production area should exist');
    }

    /**
     * Test that production areas are reused if they already exist
     */
    public function test_reuses_existing_production_areas(): void
    {
        // Mock S3 storage
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Pre-create production areas
        $existingArea1 = ProductionArea::create(['name' => 'CUARTO FRIO ENSALADAS']);
        $existingArea2 = ProductionArea::create(['name' => 'EMPLATADO']);

        $this->assertEquals(2, ProductionArea::count(), 'Should start with 2 production areas');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $testFile = base_path('tests/Fixtures/test_single_product.xlsx');

        // Import product
        Excel::import(
            new ProductsImport($importProcess->id),
            $testFile
        );

        // Verify production areas were NOT duplicated
        $this->assertEquals(2, ProductionArea::count(), 'Should still have 2 production areas (reused existing)');

        // Verify product is associated with existing areas
        $product = Product::where('code', 'TEST-PROD-001')->first();
        $this->assertNotNull($product, 'Product should exist');
        $this->assertEquals(2, $product->productionAreas->count(), 'Product should have 2 production areas');

        // Verify the areas are the same (by ID)
        $productAreaIds = $product->productionAreas->pluck('id')->toArray();
        $this->assertContains($existingArea1->id, $productAreaIds, 'Should use existing CUARTO FRIO ENSALADAS area');
        $this->assertContains($existingArea2->id, $productAreaIds, 'Should use existing EMPLATADO area');
    }

    /**
     * Test that import process status updates correctly
     */
    public function test_import_process_status_updates_correctly(): void
    {
        // Mock S3 storage
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Create import process
        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $this->assertEquals(
            ImportProcess::STATUS_QUEUED,
            $importProcess->status,
            'Initial status should be QUEUED'
        );

        $testFile = base_path('tests/Fixtures/test_single_product.xlsx');

        // Import
        Excel::import(
            new ProductsImport($importProcess->id),
            $testFile
        );

        $importProcess->refresh();

        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Final status should be PROCESSED'
        );

        $this->assertNull(
            $importProcess->error_log,
            'Error log should be null for successful import'
        );
    }

    /**
     * Test that updateOrCreate works correctly on re-import
     */
    public function test_updates_existing_product_on_reimport(): void
    {
        // Mock S3 storage
        \Illuminate\Support\Facades\Storage::fake('s3');

        // First import
        $importProcess1 = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $testFile = base_path('tests/Fixtures/test_single_product.xlsx');

        Excel::import(
            new ProductsImport($importProcess1->id),
            $testFile
        );

        $this->assertEquals(1, Product::count(), 'Should have 1 product after first import');

        $product = Product::where('code', 'TEST-PROD-001')->first();
        $originalId = $product->id;

        // Second import (should update, not duplicate)
        $importProcess2 = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new ProductsImport($importProcess2->id),
            $testFile
        );

        // Verify product was NOT duplicated
        $this->assertEquals(1, Product::count(), 'Should still have 1 product after re-import (updateOrCreate)');

        $product = Product::where('code', 'TEST-PROD-001')->first();
        $this->assertEquals($originalId, $product->id, 'Product ID should be the same (updated, not duplicated)');
    }

    /**
     * Test that billing code is imported when present in the Excel file.
     *
     * Fixture: test_product_import_billing_code.xlsx (3 products, 15 columns)
     * - Product 1: TEST-BILL-001, billing_code = FACT-PROD-001
     * - Product 2: TEST-BILL-002, billing_code = FACT-PROD-002
     * - Product 3: TEST-BILL-003, billing_code = empty
     */
    public function test_imports_billing_code_when_present(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $testFile = base_path('tests/Fixtures/test_product_import_billing_code.xlsx');
        $this->assertFileExists($testFile);

        Excel::import(
            new ProductsImport($importProcess->id),
            $testFile
        );

        $importProcess->refresh();
        $this->assertEquals(ImportProcess::STATUS_PROCESSED, $importProcess->status);
        $this->assertEquals(3, Product::count());

        // Product with billing code
        $product1 = Product::where('code', 'TEST-BILL-001')->first();
        $this->assertNotNull($product1);
        $this->assertEquals('FACT-PROD-001', $product1->billing_code);

        // Product with billing code
        $product2 = Product::where('code', 'TEST-BILL-002')->first();
        $this->assertNotNull($product2);
        $this->assertEquals('FACT-PROD-002', $product2->billing_code);

        // Product without billing code
        $product3 = Product::where('code', 'TEST-BILL-003')->first();
        $this->assertNotNull($product3);
        $this->assertNull($product3->billing_code);
    }

    /**
     * Test that master categories are imported from the "Categoria Maestra" column.
     *
     * Fixture: test_product_master_category.xlsx (3 products, 16 columns)
     * - Product 1: TEST-MC-001, category MINI ENSALADAS, master categories "Almuerzos, Platos Fríos"
     * - Product 2: TEST-MC-002, category BEBESTIBLES, master category "Bebidas"
     * - Product 3: TEST-MC-003, category ACOMPAÑAMIENTOS, master category empty
     *
     * Expected:
     * - MasterCategory "Almuerzos" created and associated to MINI ENSALADAS
     * - MasterCategory "Platos Fríos" created and associated to MINI ENSALADAS
     * - MasterCategory "Bebidas" created and associated to BEBESTIBLES
     * - ACOMPAÑAMIENTOS has no master categories
     */
    public function test_imports_master_categories_from_excel(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        // Create categories needed
        Category::create(['name' => 'BEBESTIBLES']);
        Category::create(['name' => 'ACOMPAÑAMIENTOS']);

        $this->assertEquals(0, MasterCategory::count(), 'Should start with 0 master categories');

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $testFile = base_path('tests/Fixtures/test_product_master_category.xlsx');
        $this->assertFileExists($testFile);

        Excel::import(
            new ProductsImport($importProcess->id),
            $testFile
        );

        $importProcess->refresh();
        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete without errors'
        );

        $this->assertEquals(3, Product::count(), 'Should have imported 3 products');

        // Verify master categories were created
        $this->assertEquals(3, MasterCategory::count(), 'Should have created 3 master categories');
        $this->assertNotNull(MasterCategory::where('name', 'Almuerzos')->first());
        $this->assertNotNull(MasterCategory::where('name', 'Platos Fríos')->first());
        $this->assertNotNull(MasterCategory::where('name', 'Bebidas')->first());

        // Verify associations to categories
        $catEnsaladas = Category::where('name', 'MINI ENSALADAS')->first();
        $masterNames = $catEnsaladas->masterCategories->pluck('name')->sort()->values()->toArray();
        $this->assertEquals(['Almuerzos', 'Platos Fríos'], $masterNames);

        $catBebidas = Category::where('name', 'BEBESTIBLES')->first();
        $this->assertEquals(['Bebidas'], $catBebidas->masterCategories->pluck('name')->toArray());

        // Category without master categories
        $catAcomp = Category::where('name', 'ACOMPAÑAMIENTOS')->first();
        $this->assertCount(0, $catAcomp->masterCategories);
    }

    /**
     * Test that existing master categories are reused and associated
     * (not duplicated) when reimporting.
     */
    public function test_imports_master_categories_reuses_existing(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        Category::create(['name' => 'BEBESTIBLES']);
        Category::create(['name' => 'ACOMPAÑAMIENTOS']);

        // Pre-create a master category
        $existing = MasterCategory::create(['name' => 'Almuerzos']);

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new ProductsImport($importProcess->id),
            base_path('tests/Fixtures/test_product_master_category.xlsx')
        );

        // "Almuerzos" should NOT be duplicated
        $this->assertEquals(3, MasterCategory::count(), 'Should have 3 master categories (not 4)');

        // The existing one should be the same record
        $catEnsaladas = Category::where('name', 'MINI ENSALADAS')->first();
        $almuerzos = $catEnsaladas->masterCategories->where('name', 'Almuerzos')->first();
        $this->assertEquals($existing->id, $almuerzos->id, 'Should reuse existing MasterCategory');
    }

    /**
     * Test that master category field is not mandatory (products without it import fine).
     */
    public function test_imports_products_without_master_category(): void
    {
        \Illuminate\Support\Facades\Storage::fake('s3');

        Category::create(['name' => 'BEBESTIBLES']);
        Category::create(['name' => 'ACOMPAÑAMIENTOS']);

        $importProcess = ImportProcess::create([
            'type' => ImportProcess::TYPE_PRODUCTS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        Excel::import(
            new ProductsImport($importProcess->id),
            base_path('tests/Fixtures/test_product_master_category.xlsx')
        );

        // Product 3 has empty master category - should import fine
        $product3 = Product::where('code', 'TEST-MC-003')->first();
        $this->assertNotNull($product3, 'Product without master category should be imported');
        $this->assertEquals('ACOMPAÑAMIENTOS', $product3->category->name);

        $importProcess->refresh();
        $this->assertNull($importProcess->error_log, 'No errors expected');
    }
}
