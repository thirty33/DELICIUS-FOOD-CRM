<?php

namespace Tests\Feature\Imports;

use App\Enums\NutritionalValueType;
use App\Models\Category;
use App\Models\NutritionalInformation;
use App\Models\NutritionalValue;
use App\Models\Product;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ConfiguresNutritionalInformationTests;

/**
 * Nutritional Information Import Test - TDD Green Phase
 *
 * This test validates the complete import flow for nutritional information:
 * - Imports data from Excel file with 10 products
 * - Creates NutritionalInformation records (parent table)
 * - Creates NutritionalValue records (child table with 16 values per product)
 * - Validates all nutritional types and flags are stored correctly
 *
 * Test Data: IMPORTADOR INF NUTRICIONAL COMPLETO.xlsx
 * Products: ACM00000001 through ACM00000010
 *
 * Queue Configuration: Tests use 'sync' queue via ConfiguresNutritionalInformationTests trait
 * Storage Configuration: S3 is mocked via Storage::fake('s3')
 */
class NutritionalInformationImportTest extends TestCase
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
            'description' => 'Category for nutritional information tests',
            'active' => true,
        ]);
    }

    /**
     * Test nutritional information import with 10 products
     *
     * Expected behavior:
     * 1. Import finds products by code (ACM00000001 - ACM00000010)
     * 2. Creates NutritionalInformation record for each product
     * 3. Creates 16 NutritionalValue records per product (12 nutritional + 4 flags)
     * 4. All values are stored correctly with proper types
     */
    public function test_imports_nutritional_information_for_10_products(): void
    {
        // Create the 10 test products (ACM00000001 through ACM00000010)
        $products = $this->createTestProducts();

        // Get test Excel file from fixtures
        $testFile = base_path('tests/Fixtures/test_nutritional_information.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist in fixtures directory');

        // Import using ImportService
        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\NutritionalInformationRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\NutritionalInformationImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_NUTRITIONAL_INFORMATION,
            $repository
        );

        // Validate that each product now has nutritional information
        foreach ($products as $product) {
            $product->refresh();

            // Assert NutritionalInformation record exists
            $this->assertNotNull(
                $product->nutritionalInformation,
                "Product {$product->code} should have nutritional information"
            );

            $nutritionalInfo = $product->nutritionalInformation;

            // Assert basic information fields are populated
            $this->assertNotNull($nutritionalInfo->barcode, "Product {$product->code} should have barcode");
            $this->assertNotNull($nutritionalInfo->ingredients, "Product {$product->code} should have ingredients");
            $this->assertNotNull($nutritionalInfo->measure_unit, "Product {$product->code} should have measure unit");
            $this->assertGreaterThan(0, $nutritionalInfo->net_weight, "Product {$product->code} should have net weight > 0");

            // Assert NutritionalValue records exist (should have 16 values)
            $nutritionalValues = $nutritionalInfo->nutritionalValues;
            $this->assertEquals(
                16,
                $nutritionalValues->count(),
                "Product {$product->code} should have exactly 16 nutritional values (12 nutritional + 4 flags)"
            );

            // Assert all nutritional value types exist
            $this->assertNutritionalValuesExist($nutritionalInfo, $product->code);

            // Assert flag values are 0 or 1
            $this->assertFlagValuesAreValid($nutritionalInfo, $product->code);
        }

        // Verify total counts
        $this->assertEquals(10, NutritionalInformation::count(), 'Should have 10 nutritional information records');
        $this->assertEquals(160, NutritionalValue::count(), 'Should have 160 nutritional value records (16 per product)');
    }

    /**
     * Test validates text fields and flags from Excel import
     */
    public function test_validates_text_fields_and_flags_from_excel(): void
    {
        // Create test products
        $products = $this->createTestProducts();

        // Import using ImportService
        $testFile = base_path('tests/Fixtures/test_nutritional_information.xlsx');
        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\NutritionalInformationRepositoryInterface::class);

        $importService->importWithRepository(
            \App\Imports\NutritionalInformationImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_NUTRITIONAL_INFORMATION,
            $repository
        );

        // Verify all 10 products have nutritional information
        $this->assertEquals(10, NutritionalInformation::count(), 'Should have 10 nutritional information records');

        // Validate first product (ACM00000001) with all its data
        $firstProduct = $products->first();
        $firstProduct->refresh();
        $nutritionalInfo = $firstProduct->nutritionalInformation;

        // Validate text fields
        $this->assertEquals('7801234567001', $nutritionalInfo->barcode);
        $this->assertStringContainsString('Agua, pollo', $nutritionalInfo->ingredients);
        $this->assertStringContainsString('Apio', $nutritionalInfo->allergens);
        $this->assertEquals('GR', $nutritionalInfo->measure_unit);
        $this->assertEquals(300, $nutritionalInfo->net_weight);
        $this->assertEquals(330, $nutritionalInfo->gross_weight);
        $this->assertEquals(3, $nutritionalInfo->shelf_life_days);

        // Validate nutritional values
        $this->assertEquals(45, $nutritionalInfo->getValue(NutritionalValueType::CALORIES));
        $this->assertEquals(3.5, $nutritionalInfo->getValue(NutritionalValueType::PROTEIN));
        $this->assertEquals(1.2, $nutritionalInfo->getValue(NutritionalValueType::FAT_TOTAL));
        $this->assertEquals(0.3, $nutritionalInfo->getValue(NutritionalValueType::FAT_SATURATED));
        $this->assertEquals(0.5, $nutritionalInfo->getValue(NutritionalValueType::FAT_MONOUNSATURATED));
        $this->assertEquals(0.2, $nutritionalInfo->getValue(NutritionalValueType::FAT_POLYUNSATURATED));
        $this->assertEquals(0, $nutritionalInfo->getValue(NutritionalValueType::FAT_TRANS));
        $this->assertEquals(8, $nutritionalInfo->getValue(NutritionalValueType::CHOLESTEROL));
        $this->assertEquals(4.2, $nutritionalInfo->getValue(NutritionalValueType::CARBOHYDRATE));
        $this->assertEquals(0.5, $nutritionalInfo->getValue(NutritionalValueType::FIBER));
        $this->assertEquals(1.2, $nutritionalInfo->getValue(NutritionalValueType::SUGAR));
        $this->assertEquals(280, $nutritionalInfo->getValue(NutritionalValueType::SODIUM));

        // Validate high content flags (0 or 1)
        $this->assertEquals(0, $nutritionalInfo->getValue(NutritionalValueType::HIGH_SODIUM));
        $this->assertEquals(0, $nutritionalInfo->getValue(NutritionalValueType::HIGH_CALORIES));
        $this->assertEquals(0, $nutritionalInfo->getValue(NutritionalValueType::HIGH_FAT));
        $this->assertEquals(0, $nutritionalInfo->getValue(NutritionalValueType::HIGH_SUGAR));

        // Validate generate_label flag
        $this->assertTrue($nutritionalInfo->generate_label);
    }

    /**
     * Test specific product nutritional values
     * Validates that specific values from Excel are imported correctly
     */
    public function test_validates_specific_nutritional_values_from_excel(): void
    {
        // Create test products
        $products = $this->createTestProducts();

        // Import using ImportService
        $testFile = base_path('tests/Fixtures/test_nutritional_information.xlsx');
        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\NutritionalInformationRepositoryInterface::class);

        $importService->importWithRepository(
            \App\Imports\NutritionalInformationImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_NUTRITIONAL_INFORMATION,
            $repository
        );

        // Get first product and verify specific values
        $firstProduct = $products->first();
        $firstProduct->refresh();

        $nutritionalInfo = $firstProduct->nutritionalInformation;
        $this->assertNotNull($nutritionalInfo);

        // Verify that values are numeric and greater than or equal to 0
        $calories = $nutritionalInfo->getValue(NutritionalValueType::CALORIES);
        $this->assertIsFloat($calories);
        $this->assertGreaterThanOrEqual(0, $calories);

        $protein = $nutritionalInfo->getValue(NutritionalValueType::PROTEIN);
        $this->assertIsFloat($protein);
        $this->assertGreaterThanOrEqual(0, $protein);

        // Verify flag values
        $highSodium = $nutritionalInfo->getValue(NutritionalValueType::HIGH_SODIUM);
        $this->assertTrue(in_array($highSodium, [0, 1, 0.0, 1.0], true));
    }

    /**
     * Create 10 test products (ACM00000001 through ACM00000010)
     * These match the product codes in the Excel file
     */
    private function createTestProducts(): \Illuminate\Support\Collection
    {
        $products = collect();

        for ($i = 1; $i <= 10; $i++) {
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

    /**
     * Assert that all nutritional value types exist for a given nutritional information
     */
    private function assertNutritionalValuesExist(NutritionalInformation $nutritionalInfo, string $productCode): void
    {
        // Check nutritional types (12 types)
        foreach (NutritionalValueType::nutritionalTypes() as $type) {
            $value = $nutritionalInfo->getValue($type);
            $this->assertNotNull(
                $value,
                "Product {$productCode}: Nutritional value type '{$type->value}' should exist"
            );
            $this->assertIsFloat($value, "Product {$productCode}: Value for '{$type->value}' should be float");
        }

        // Check flag types (4 types)
        foreach (NutritionalValueType::flagTypes() as $type) {
            $value = $nutritionalInfo->getValue($type);
            $this->assertNotNull(
                $value,
                "Product {$productCode}: Flag type '{$type->value}' should exist"
            );
        }
    }

    /**
     * Assert that flag values are either 0 or 1
     */
    private function assertFlagValuesAreValid(NutritionalInformation $nutritionalInfo, string $productCode): void
    {
        foreach (NutritionalValueType::flagTypes() as $type) {
            $value = $nutritionalInfo->getValue($type);
            $this->assertTrue(
                in_array($value, [0, 1, 0.0, 1.0], true),
                "Product {$productCode}: Flag '{$type->value}' should be 0 or 1, got {$value}"
            );
        }
    }

    /**
     * Test error validation during import
     *
     * This test validates that import errors are properly detected and logged.
     *
     * Expected errors (RED PHASE - will fail until validation is implemented):
     * 1. Product code does not exist in database
     * 2. Missing product code (empty column)
     * 3. Barcode too long (> 20 characters) and alphanumeric
     * 4. Empty barcode
     * 5. Non-numeric nutritional values (calories)
     * 6. Non-numeric nutritional values (protein)
     * 7. ALTO SODIO with value other than 0 or 1
     * 8. ALTO CALORIAS with value other than 0 or 1
     * 9. GENERAR ETIQUETA with value other than 0 or 1
     * 10. Invalid measure unit (not GR, KG, UND)
     */
    public function test_validates_import_errors_and_logs_them(): void
    {
        // Create products that exist (ACM00000001 through ACM00000008)
        // Note: Product ACM00000009 and ACM00000010 are NOT created to test "non-existent product"
        $products = collect();
        for ($i = 1; $i <= 8; $i++) {
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

        // Get test Excel file with errors
        $testFile = base_path('tests/Fixtures/test_nutritional_information_errors.xlsx');
        $this->assertFileExists($testFile, 'Error test Excel file should exist in fixtures directory');

        // Import using ImportService
        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\NutritionalInformationRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\NutritionalInformationImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_NUTRITIONAL_INFORMATION,
            $repository
        );

        // TDD RED PHASE: These assertions will FAIL until validation is implemented

        // Assert import completed with errors
        $this->assertEquals(
            \App\Models\ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
            $importProcess->status,
            'Import should complete with STATUS_PROCESSED_WITH_ERRORS'
        );

        // Assert error_log exists and has entries
        $this->assertNotNull($importProcess->error_log, 'Error log should not be null');
        $this->assertIsArray($importProcess->error_log, 'Error log should be an array');
        $this->assertNotEmpty($importProcess->error_log, 'Error log should contain errors');

        // Assert we have at least 10 errors (one per row)
        $this->assertGreaterThanOrEqual(
            10,
            count($importProcess->error_log),
            'Should have at least 10 errors logged (one per error type)'
        );

        // Verify error structure for each error
        foreach ($importProcess->error_log as $error) {
            $this->assertArrayHasKey('row', $error, 'Error should have row field');
            $this->assertArrayHasKey('attribute', $error, 'Error should have attribute field');
            $this->assertArrayHasKey('errors', $error, 'Error should have errors field');
            $this->assertArrayHasKey('values', $error, 'Error should have values field');
        }

        // Verify specific error messages exist
        $errorMessages = [];
        foreach ($importProcess->error_log as $error) {
            if (is_array($error['errors'])) {
                $errorMessages = array_merge($errorMessages, $error['errors']);
            } else {
                $errorMessages[] = $error['errors'];
            }
        }

        $allErrors = implode(' ', $errorMessages);

        // Expected error patterns
        $expectedErrorPatterns = [
            '/product.*not.*found|producto.*no.*encontrado/i',  // Non-existent product
            '/product.*code.*required|codigo.*producto.*requerido/i',  // Missing product code
            '/barcode.*max|codigo.*barras.*maximo/i',  // Barcode too long
            '/barcode.*required|codigo.*barras.*requerido/i',  // Empty barcode
            '/calories.*numeric|calorias.*numerico/i',  // Non-numeric calories
            '/protein.*numeric|proteina.*numerico/i',  // Non-numeric protein
            '/alto.*sodio.*0.*1|high.*sodium.*0.*1/i',  // ALTO SODIO invalid
            '/alto.*calorias.*0.*1|high.*calories.*0.*1/i',  // ALTO CALORIAS invalid
            '/generar.*etiqueta.*0.*1|generate.*label.*0.*1/i',  // GENERAR ETIQUETA invalid
            '/measure.*unit|unidad.*medida/i',  // Invalid measure unit
        ];

        // TDD: At least one error pattern should match
        // (This will fail until validation is implemented)
        $matchFound = false;
        foreach ($expectedErrorPatterns as $pattern) {
            if (preg_match($pattern, $allErrors)) {
                $matchFound = true;
                break;
            }
        }

        $this->assertTrue(
            $matchFound,
            'At least one expected error pattern should be found in error log'
        );
    }
}