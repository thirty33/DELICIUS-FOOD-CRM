<?php

namespace Tests\Feature\Imports;

use App\Models\Category;
use App\Models\NutritionalInformation;
use App\Models\Product;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ConfiguresNutritionalInformationTests;

/**
 * Nutritional Information Warning Text Import Test - TDD RED PHASE
 *
 * This test validates the import of warning text fields for allergens/ingredients:
 * - MOSTRAR TEXTO SOYA: Boolean field to show/hide soy warning text
 * - MOSTRAR TEXTO POLLO: Boolean field to show/hide chicken warning text
 *
 * These fields will be stored in the nutritional_information table as boolean columns:
 * - show_soy_text (boolean, default false)
 * - show_chicken_text (boolean, default false)
 *
 * Test Data: test_nutritional_information_warning_texts.xlsx
 * Products: ACM00000001 through ACM00000005
 *
 * Expected Excel columns:
 * - CÓDIGO DE PRODUCTO
 * - NOMBRE DE PRODUCTO
 * - CODIGO DE BARRAS
 * - INGREDIENTE
 * - ALERGENOS
 * - UNIDAD DE MEDIDA
 * - PESO NETO
 * - PESO BRUTO
 * - ... (all nutritional values)
 * - MOSTRAR TEXTO SOYA (0 or 1)
 * - MOSTRAR TEXTO POLLO (0 or 1)
 * - VIDA UTIL
 * - GENERAR ETIQUETA
 *
 * TDD RED PHASE: This test will FAIL because:
 * 1. Columns show_soy_text and show_chicken_text don't exist in nutritional_information table
 * 2. Import class doesn't map MOSTRAR TEXTO SOYA and MOSTRAR TEXTO POLLO columns
 * 3. Migration to add these columns hasn't been created yet
 *
 * Queue Configuration: Tests use 'sync' queue via ConfiguresNutritionalInformationTests trait
 * Storage Configuration: S3 is mocked via Storage::fake('s3')
 */
class NutritionalInformationWarningTextImportTest extends TestCase
{
    use RefreshDatabase;
    use ConfiguresNutritionalInformationTests;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test category for products
        $this->category = Category::create([
            'name' => 'Test Category',
            'code' => 'TEST',
            'description' => 'Category for warning text import tests',
            'active' => true,
        ]);
    }

    /**
     * Test imports warning text fields (MOSTRAR TEXTO SOYA, MOSTRAR TEXTO POLLO)
     *
     * TDD RED PHASE: This test will FAIL because the columns don't exist yet
     *
     * Expected behavior:
     * 1. Import reads MOSTRAR TEXTO SOYA and MOSTRAR TEXTO POLLO from Excel
     * 2. Values are stored in show_soy_text and show_chicken_text boolean columns
     * 3. Values are either true (1) or false (0)
     * 4. Default value is false when not specified
     */
    public function test_imports_warning_text_fields_for_soy_and_chicken(): void
    {
        // Create 5 test products (ACM00000001 through ACM00000005)
        $products = $this->createTestProducts();

        // Get test Excel file from fixtures
        $testFile = base_path('tests/Fixtures/test_nutritional_information_warning_texts.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file with warning texts should exist in fixtures directory');

        // Import using ImportService
        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\NutritionalInformationRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\NutritionalInformationImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_NUTRITIONAL_INFORMATION,
            $repository
        );

        // Verify import completed successfully
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Import should complete successfully'
        );

        // Verify all 5 products have nutritional information
        $this->assertEquals(5, NutritionalInformation::count(), 'Should have 5 nutritional information records');

        // Product 1: MOSTRAR TEXTO SOYA = 1, MOSTRAR TEXTO POLLO = 0
        $product1 = $products->get(0);
        $product1->refresh();
        $nutritionalInfo1 = $product1->nutritionalInformation;

        $this->assertNotNull($nutritionalInfo1, 'Product 1 should have nutritional information');
        $this->assertTrue(
            $nutritionalInfo1->show_soy_text,
            'Product 1: show_soy_text should be TRUE (value 1 in Excel)'
        );
        $this->assertFalse(
            $nutritionalInfo1->show_chicken_text,
            'Product 1: show_chicken_text should be FALSE (value 0 in Excel)'
        );

        // Product 2: MOSTRAR TEXTO SOYA = 0, MOSTRAR TEXTO POLLO = 1
        $product2 = $products->get(1);
        $product2->refresh();
        $nutritionalInfo2 = $product2->nutritionalInformation;

        $this->assertNotNull($nutritionalInfo2, 'Product 2 should have nutritional information');
        $this->assertFalse(
            $nutritionalInfo2->show_soy_text,
            'Product 2: show_soy_text should be FALSE (value 0 in Excel)'
        );
        $this->assertTrue(
            $nutritionalInfo2->show_chicken_text,
            'Product 2: show_chicken_text should be TRUE (value 1 in Excel)'
        );

        // Product 3: MOSTRAR TEXTO SOYA = 1, MOSTRAR TEXTO POLLO = 1
        $product3 = $products->get(2);
        $product3->refresh();
        $nutritionalInfo3 = $product3->nutritionalInformation;

        $this->assertNotNull($nutritionalInfo3, 'Product 3 should have nutritional information');
        $this->assertTrue(
            $nutritionalInfo3->show_soy_text,
            'Product 3: show_soy_text should be TRUE (value 1 in Excel)'
        );
        $this->assertTrue(
            $nutritionalInfo3->show_chicken_text,
            'Product 3: show_chicken_text should be TRUE (value 1 in Excel)'
        );

        // Product 4: MOSTRAR TEXTO SOYA = 0, MOSTRAR TEXTO POLLO = 0
        $product4 = $products->get(3);
        $product4->refresh();
        $nutritionalInfo4 = $product4->nutritionalInformation;

        $this->assertNotNull($nutritionalInfo4, 'Product 4 should have nutritional information');
        $this->assertFalse(
            $nutritionalInfo4->show_soy_text,
            'Product 4: show_soy_text should be FALSE (value 0 in Excel)'
        );
        $this->assertFalse(
            $nutritionalInfo4->show_chicken_text,
            'Product 4: show_chicken_text should be FALSE (value 0 in Excel)'
        );

        // Product 5: MOSTRAR TEXTO SOYA = empty (default), MOSTRAR TEXTO POLLO = empty (default)
        $product5 = $products->get(4);
        $product5->refresh();
        $nutritionalInfo5 = $product5->nutritionalInformation;

        $this->assertNotNull($nutritionalInfo5, 'Product 5 should have nutritional information');
        $this->assertFalse(
            $nutritionalInfo5->show_soy_text,
            'Product 5: show_soy_text should be FALSE (empty/default in Excel)'
        );
        $this->assertFalse(
            $nutritionalInfo5->show_chicken_text,
            'Product 5: show_chicken_text should be FALSE (empty/default in Excel)'
        );
    }

    /**
     * Test validates that warning text fields are boolean type
     *
     * TDD RED PHASE: This test will FAIL because columns don't exist yet
     *
     * Expected behavior:
     * 1. show_soy_text and show_chicken_text should be boolean fields
     * 2. Values should be strictly true or false (not 1/0 strings)
     * 3. Database schema should define these as boolean/tinyint columns
     */
    public function test_warning_text_fields_are_boolean_type(): void
    {
        // Create test products
        $products = $this->createTestProducts();

        // Get test Excel file
        $testFile = base_path('tests/Fixtures/test_nutritional_information_warning_texts.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        // Import using ImportService
        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\NutritionalInformationRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\NutritionalInformationImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_NUTRITIONAL_INFORMATION,
            $repository
        );

        // Get first product
        $product1 = $products->first();
        $product1->refresh();
        $nutritionalInfo = $product1->nutritionalInformation;

        $this->assertNotNull($nutritionalInfo);

        // Assert values are boolean type (not string or integer)
        $this->assertIsBool($nutritionalInfo->show_soy_text, 'show_soy_text should be boolean type');
        $this->assertIsBool($nutritionalInfo->show_chicken_text, 'show_chicken_text should be boolean type');

        // Assert values are strictly true or false
        $this->assertTrue(
            $nutritionalInfo->show_soy_text === true || $nutritionalInfo->show_soy_text === false,
            'show_soy_text should be strictly boolean (true/false)'
        );
        $this->assertTrue(
            $nutritionalInfo->show_chicken_text === true || $nutritionalInfo->show_chicken_text === false,
            'show_chicken_text should be strictly boolean (true/false)'
        );
    }

    /**
     * Test validates Excel column headers exist
     *
     * TDD RED PHASE: This test documents expected Excel structure
     *
     * Expected Excel columns (26 base + 2 new = 28 total):
     * 1-26: Existing columns (product info, nutritional values, flags)
     * 27: MOSTRAR TEXTO SOYA
     * 28: MOSTRAR TEXTO POLLO
     *
     * Note: This test validates the Excel structure, not database schema
     */
    public function test_excel_has_warning_text_columns(): void
    {
        // This test will read the Excel file and validate column headers
        $testFile = base_path('tests/Fixtures/test_nutritional_information_warning_texts.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        // Load Excel file using PhpSpreadsheet
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($testFile);
        $sheet = $spreadsheet->getActiveSheet();

        // Read all headers from row 1
        $headers = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($headerValue) {
                $headers[] = trim($headerValue);
            }
        }

        // Assert we have at least 28 columns (26 base + 2 new)
        $this->assertGreaterThanOrEqual(
            28,
            count($headers),
            'Excel should have at least 28 columns (26 base + 2 warning text columns)'
        );

        // Assert MOSTRAR TEXTO SOYA column exists
        $this->assertContains(
            'MOSTRAR TEXTO SOYA',
            $headers,
            'Excel should have "MOSTRAR TEXTO SOYA" column'
        );

        // Assert MOSTRAR TEXTO POLLO column exists
        $this->assertContains(
            'MOSTRAR TEXTO POLLO',
            $headers,
            'Excel should have "MOSTRAR TEXTO POLLO" column'
        );

        // Verify column positions (should be at the END, after VIDA UTIL and GENERAR ETIQUETA)
        $vidaUtilIndex = array_search('VIDA UTIL', $headers);
        $generateLabelIndex = array_search('GENERAR ETIQUETA', $headers);
        $showSoyTextIndex = array_search('MOSTRAR TEXTO SOYA', $headers);
        $showChickenTextIndex = array_search('MOSTRAR TEXTO POLLO', $headers);

        $this->assertGreaterThan(
            $vidaUtilIndex,
            $showSoyTextIndex,
            'MOSTRAR TEXTO SOYA should come after VIDA UTIL'
        );

        $this->assertGreaterThan(
            $generateLabelIndex,
            $showSoyTextIndex,
            'MOSTRAR TEXTO SOYA should come after GENERAR ETIQUETA'
        );

        $this->assertGreaterThan(
            $showSoyTextIndex,
            $showChickenTextIndex,
            'MOSTRAR TEXTO POLLO should come after MOSTRAR TEXTO SOYA'
        );
    }

    /**
     * Test updates existing warning text fields through import
     *
     * Scenario:
     * 1. Create two products with existing nutritional information and warning text values
     * 2. Import Excel file that updates the warning text fields with different values
     * 3. Verify the database has been updated with the new values
     *
     * This validates that the import process correctly UPDATES existing records
     * rather than only creating new ones.
     */
    public function test_import_updates_existing_warning_text_fields(): void
    {
        // Step 1: Create two test products with existing nutritional information
        $product1 = Product::create([
            'name' => 'Test Product 1 - Update',
            'description' => 'Product 1 for update test',
            'code' => 'UPD00000001',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $product2 = Product::create([
            'name' => 'Test Product 2 - Update',
            'description' => 'Product 2 for update test',
            'code' => 'UPD00000002',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create initial nutritional information with specific warning text values
        $nutritionalInfo1 = NutritionalInformation::create([
            'product_id' => $product1->id,
            'barcode' => '1234567890123',
            'ingredients' => 'Initial ingredients 1',
            'allergens' => 'Initial allergens 1',
            'measure_unit' => 'GR',
            'net_weight' => 100.00,
            'gross_weight' => 120.00,
            'shelf_life_days' => 5,
            'generate_label' => true,
            'show_soy_text' => true,  // Initial value: TRUE
            'show_chicken_text' => false,  // Initial value: FALSE
        ]);

        $nutritionalInfo2 = NutritionalInformation::create([
            'product_id' => $product2->id,
            'barcode' => '1234567890124',
            'ingredients' => 'Initial ingredients 2',
            'allergens' => 'Initial allergens 2',
            'measure_unit' => 'GR',
            'net_weight' => 150.00,
            'gross_weight' => 180.00,
            'shelf_life_days' => 7,
            'generate_label' => false,
            'show_soy_text' => false,  // Initial value: FALSE
            'show_chicken_text' => true,  // Initial value: TRUE
        ]);

        // Verify initial state in database
        $this->assertTrue(
            $nutritionalInfo1->fresh()->show_soy_text,
            'Product 1: Initial show_soy_text should be TRUE'
        );
        $this->assertFalse(
            $nutritionalInfo1->fresh()->show_chicken_text,
            'Product 1: Initial show_chicken_text should be FALSE'
        );
        $this->assertFalse(
            $nutritionalInfo2->fresh()->show_soy_text,
            'Product 2: Initial show_soy_text should be FALSE'
        );
        $this->assertTrue(
            $nutritionalInfo2->fresh()->show_chicken_text,
            'Product 2: Initial show_chicken_text should be TRUE'
        );

        // Step 2: Import Excel file that updates the warning text fields
        // Expected Excel data:
        // Product UPD00000001: MOSTRAR TEXTO SOYA = 0 (change from 1 to 0), MOSTRAR TEXTO POLLO = 1 (change from 0 to 1)
        // Product UPD00000002: MOSTRAR TEXTO SOYA = 1 (change from 0 to 1), MOSTRAR TEXTO POLLO = 0 (change from 1 to 0)
        $testFile = base_path('tests/Fixtures/test_nutritional_information_warning_texts_update.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file for update should exist in fixtures directory');

        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\NutritionalInformationRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\NutritionalInformationImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_NUTRITIONAL_INFORMATION,
            $repository
        );

        // Verify import completed successfully
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Import should complete successfully'
        );

        // Step 3: Verify database has been updated with new values
        // Product 1: show_soy_text should change from TRUE to FALSE, show_chicken_text should change from FALSE to TRUE
        $nutritionalInfo1->refresh();
        $this->assertFalse(
            $nutritionalInfo1->show_soy_text,
            'Product 1: show_soy_text should be updated to FALSE (was TRUE, now 0 in Excel)'
        );
        $this->assertTrue(
            $nutritionalInfo1->show_chicken_text,
            'Product 1: show_chicken_text should be updated to TRUE (was FALSE, now 1 in Excel)'
        );

        // Product 2: show_soy_text should change from FALSE to TRUE, show_chicken_text should change from TRUE to FALSE
        $nutritionalInfo2->refresh();
        $this->assertTrue(
            $nutritionalInfo2->show_soy_text,
            'Product 2: show_soy_text should be updated to TRUE (was FALSE, now 1 in Excel)'
        );
        $this->assertFalse(
            $nutritionalInfo2->show_chicken_text,
            'Product 2: show_chicken_text should be updated to FALSE (was TRUE, now 0 in Excel)'
        );

        // Verify that other fields were not affected by the update
        $this->assertEquals(
            'Initial ingredients 1',
            $nutritionalInfo1->ingredients,
            'Product 1: ingredients should remain unchanged'
        );
        $this->assertEquals(
            100.00,
            $nutritionalInfo1->net_weight,
            'Product 1: net_weight should remain unchanged'
        );

        $this->assertEquals(
            'Initial ingredients 2',
            $nutritionalInfo2->ingredients,
            'Product 2: ingredients should remain unchanged'
        );
        $this->assertEquals(
            150.00,
            $nutritionalInfo2->net_weight,
            'Product 2: net_weight should remain unchanged'
        );
    }

    /**
     * Create 5 test products (ACM00000001 through ACM00000005)
     * These match the product codes in the Excel file
     */
    private function createTestProducts(): \Illuminate\Support\Collection
    {
        $products = collect();

        for ($i = 1; $i <= 5; $i++) {
            $code = 'ACM' . str_pad($i, 8, '0', STR_PAD_LEFT);

            $product = Product::create([
                'name' => "Test Product {$i}",
                'description' => "Description for test product {$i}",
                'code' => $code,
                'category_id' => $this->category->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);

            $products->push($product);
        }

        return $products;
    }
}