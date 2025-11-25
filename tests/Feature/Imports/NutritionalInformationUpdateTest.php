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
 * Nutritional Information UPDATE Test - TDD Green Phase
 *
 * This test validates the UPDATE functionality for nutritional information:
 * - 5 products WITH existing nutritional information (should UPDATE all fields except barcode)
 * - 1 product WITHOUT existing nutritional information (should CREATE new record)
 *
 * LOOKUP KEY: product_id
 * UPDATED FIELDS: All fields
 *
 * Test Data: test_nutritional_information_update.xlsx
 * Products: ACM00000001 through ACM00000006
 *
 * Queue Configuration: Tests use 'sync' queue via ConfiguresNutritionalInformationTests trait
 * Storage Configuration: S3 is mocked via Storage::fake('s3')
 */
class NutritionalInformationUpdateTest extends TestCase
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
            'description' => 'Category for nutritional information update tests',
            'active' => true,
        ]);
    }

    /**
     * Test updates existing nutritional information and creates new records
     *
     * SCENARIO:
     * 1. Create 6 products (ACM00000001 - ACM00000006)
     * 2. Create EXISTING nutritional info for products 1-5 with ORIGINAL values
     * 3. Product 6 has NO nutritional info (will be created)
     * 4. Import Excel with UPDATED values for all 6 products
     * 5. Verify products 1-5 were UPDATED (all fields changed except barcode)
     * 6. Verify product 6 was CREATED
     *
     * RED PHASE: Will fail because repository doesn't implement update logic yet
     */
    public function test_updates_existing_nutritional_information_and_creates_new(): void
    {
        // STEP 1: Create 6 products
        $products = $this->createTestProducts();

        // STEP 2: Create EXISTING nutritional information for products 1-5
        $this->createExistingNutritionalInformation($products->take(5));

        // Verify initial state: 5 products have nutritional info, product 6 does NOT
        $this->assertEquals(5, NutritionalInformation::count(), 'Should have 5 existing nutritional information records');
        $this->assertEquals(80, NutritionalValue::count(), 'Should have 80 nutritional value records (5 products × 16 values)');

        // Verify product 6 has NO nutritional info
        $product6 = $products->get(5);
        $this->assertNull($product6->nutritionalInformation, 'Product 6 should NOT have nutritional information initially');

        // STEP 3: Import Excel file with UPDATED values
        $testFile = base_path('tests/Fixtures/test_nutritional_information_update.xlsx');
        $this->assertFileExists($testFile, 'Update test Excel file should exist in fixtures directory');

        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\NutritionalInformationRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\NutritionalInformationImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_NUTRITIONAL_INFORMATION,
            $repository
        );

        // STEP 4: Verify import was successful
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Import should complete successfully'
        );

        // STEP 5: Verify final state: 6 products now have nutritional info
        $this->assertEquals(6, NutritionalInformation::count(), 'Should now have 6 nutritional information records');
        $this->assertEquals(96, NutritionalValue::count(), 'Should have 96 nutritional value records (6 products × 16 values)');

        // STEP 6: Verify UPDATES for products 1-5
        $this->assertProduct1WasUpdated($products->get(0));
        $this->assertProduct2WasUpdated($products->get(1));
        $this->assertProduct3WasUpdated($products->get(2));
        $this->assertProduct4WasUpdated($products->get(3));
        $this->assertProduct5WasUpdated($products->get(4));

        // STEP 7: Verify CREATE for product 6
        $this->assertProduct6WasCreated($products->get(5));
    }

    /**
     * Assert that Product 1 nutritional information was UPDATED (not created new)
     */
    private function assertProduct1WasUpdated(Product $product): void
    {
        $product->refresh();
        $info = $product->nutritionalInformation;

        $this->assertNotNull($info, 'Product 1 should have nutritional information');

        // Verify BARCODE was NOT changed (lookup key)
        $this->assertEquals('7801234567001', $info->barcode, 'Barcode should NOT change');

        // Verify ALL other fields were UPDATED
        $this->assertEquals('Agua, pollo, verduras ACTUALIZADAS', $info->ingredients);
        $this->assertEquals('Apio, Mostaza ACTUALIZADOS', $info->allergens);
        $this->assertEquals('KG', $info->measure_unit);  // Changed from GR
        $this->assertEquals(400, $info->net_weight);  // Changed from 300
        $this->assertEquals(450, $info->gross_weight);  // Changed from 330
        $this->assertEquals(5, $info->shelf_life_days);  // Changed from 3
        $this->assertEquals(0, $info->generate_label);  // Changed from 1

        // Verify nutritional VALUES were UPDATED
        $this->assertEquals(60, $info->getValue(NutritionalValueType::CALORIES));  // Changed from 45
        $this->assertEquals(5.0, $info->getValue(NutritionalValueType::PROTEIN));  // Changed from 3.5
        $this->assertEquals(2.0, $info->getValue(NutritionalValueType::FAT_TOTAL));  // Changed from 1.2
        $this->assertEquals(0.5, $info->getValue(NutritionalValueType::FAT_SATURATED));  // Changed from 0.3
        $this->assertEquals(0.8, $info->getValue(NutritionalValueType::FAT_MONOUNSATURATED));  // Changed from 0.5
        $this->assertEquals(0.4, $info->getValue(NutritionalValueType::FAT_POLYUNSATURATED));  // Changed from 0.2
        $this->assertEquals(0.1, $info->getValue(NutritionalValueType::FAT_TRANS));  // Changed from 0
        $this->assertEquals(12, $info->getValue(NutritionalValueType::CHOLESTEROL));  // Changed from 8
        $this->assertEquals(6.0, $info->getValue(NutritionalValueType::CARBOHYDRATE));  // Changed from 4.2
        $this->assertEquals(1.0, $info->getValue(NutritionalValueType::FIBER));  // Changed from 0.5
        $this->assertEquals(2.0, $info->getValue(NutritionalValueType::SUGAR));  // Changed from 1.2
        $this->assertEquals(350, $info->getValue(NutritionalValueType::SODIUM));  // Changed from 280

        // Verify FLAGS were UPDATED
        $this->assertEquals(1, $info->getValue(NutritionalValueType::HIGH_SODIUM));  // Changed from 0
        $this->assertEquals(1, $info->getValue(NutritionalValueType::HIGH_CALORIES));  // Changed from 0
        $this->assertEquals(1, $info->getValue(NutritionalValueType::HIGH_FAT));  // Changed from 0
        $this->assertEquals(1, $info->getValue(NutritionalValueType::HIGH_SUGAR));  // Changed from 0
    }

    /**
     * Assert that Product 2 nutritional information was UPDATED
     */
    private function assertProduct2WasUpdated(Product $product): void
    {
        $product->refresh();
        $info = $product->nutritionalInformation;

        $this->assertNotNull($info);
        $this->assertEquals('7801234567002', $info->barcode);  // Unchanged
        $this->assertEquals('Carne, arroz, vegetales ACTUALIZADOS', $info->ingredients);  // Updated
        $this->assertEquals('UND', $info->measure_unit);  // Updated
        $this->assertEquals(200, $info->getValue(NutritionalValueType::CALORIES));  // Updated
    }

    /**
     * Assert that Product 3 nutritional information was UPDATED
     */
    private function assertProduct3WasUpdated(Product $product): void
    {
        $product->refresh();
        $info = $product->nutritionalInformation;

        $this->assertNotNull($info);
        $this->assertEquals('7801234567003', $info->barcode);  // Unchanged
        $this->assertEquals('Harina integral, agua, levadura ACTUALIZADOS', $info->ingredients);  // Updated
        $this->assertEquals(120, $info->getValue(NutritionalValueType::CALORIES));  // Updated
    }

    /**
     * Assert that Product 4 nutritional information was UPDATED
     */
    private function assertProduct4WasUpdated(Product $product): void
    {
        $product->refresh();
        $info = $product->nutritionalInformation;

        $this->assertNotNull($info);
        $this->assertEquals('7801234567004', $info->barcode);  // Unchanged
        $this->assertEquals('Leche, azúcar, frutas ACTUALIZADOS', $info->ingredients);  // Updated
        $this->assertEquals(180, $info->getValue(NutritionalValueType::CALORIES));  // Updated
    }

    /**
     * Assert that Product 5 nutritional information was UPDATED
     */
    private function assertProduct5WasUpdated(Product $product): void
    {
        $product->refresh();
        $info = $product->nutritionalInformation;

        $this->assertNotNull($info);
        $this->assertEquals('7801234567005', $info->barcode);  // Unchanged
        $this->assertEquals('Frutas naturales, agua ACTUALIZADOS', $info->ingredients);  // Updated
        $this->assertEquals(80, $info->getValue(NutritionalValueType::CALORIES));  // Updated
    }

    /**
     * Assert that Product 6 nutritional information was CREATED (new record)
     */
    private function assertProduct6WasCreated(Product $product): void
    {
        $product->refresh();
        $info = $product->nutritionalInformation;

        $this->assertNotNull($info, 'Product 6 should NOW have nutritional information');

        // Verify it was created with correct values
        $this->assertEquals('7801234567006', $info->barcode);
        $this->assertEquals('Ingredientes del producto NUEVO', $info->ingredients);
        $this->assertEquals('Alérgenos NUEVO', $info->allergens);
        $this->assertEquals('GR', $info->measure_unit);
        $this->assertEquals(200, $info->net_weight);
        $this->assertEquals(230, $info->gross_weight);
        $this->assertEquals(3, $info->shelf_life_days);
        $this->assertEquals(1, $info->generate_label);

        // Verify nutritional values
        $this->assertEquals(100, $info->getValue(NutritionalValueType::CALORIES));
        $this->assertEquals(8.0, $info->getValue(NutritionalValueType::PROTEIN));
        $this->assertEquals(4.0, $info->getValue(NutritionalValueType::FAT_TOTAL));
    }

    /**
     * Create 6 test products (ACM00000001 through ACM00000006)
     */
    private function createTestProducts(): \Illuminate\Support\Collection
    {
        $products = collect();

        for ($i = 1; $i <= 6; $i++) {
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
     * Create EXISTING nutritional information for products 1-5 with ORIGINAL values
     */
    private function createExistingNutritionalInformation(\Illuminate\Support\Collection $products): void
    {
        // Product 1: Original values (to be updated)
        $this->createNutritionalInfo($products->get(0), [
            'barcode' => '7801234567001',
            'ingredients' => 'Agua, pollo ORIGINAL',
            'allergens' => 'Apio ORIGINAL',
            'measure_unit' => 'GR',
            'net_weight' => 300,
            'gross_weight' => 330,
            'shelf_life_days' => 3,
            'generate_label' => true,
        ], [
            NutritionalValueType::CALORIES->value => 45,
            NutritionalValueType::PROTEIN->value => 3.5,
            NutritionalValueType::FAT_TOTAL->value => 1.2,
            NutritionalValueType::FAT_SATURATED->value => 0.3,
            NutritionalValueType::FAT_MONOUNSATURATED->value => 0.5,
            NutritionalValueType::FAT_POLYUNSATURATED->value => 0.2,
            NutritionalValueType::FAT_TRANS->value => 0,
            NutritionalValueType::CHOLESTEROL->value => 8,
            NutritionalValueType::CARBOHYDRATE->value => 4.2,
            NutritionalValueType::FIBER->value => 0.5,
            NutritionalValueType::SUGAR->value => 1.2,
            NutritionalValueType::SODIUM->value => 280,
            NutritionalValueType::HIGH_SODIUM->value => 0,
            NutritionalValueType::HIGH_CALORIES->value => 0,
            NutritionalValueType::HIGH_FAT->value => 0,
            NutritionalValueType::HIGH_SUGAR->value => 0,
        ]);

        // Product 2-5: Create with simple original values
        foreach ($products->slice(1, 4) as $index => $product) {
            $productNumber = $index + 2;
            $barcode = '780123456700' . $productNumber;

            $this->createNutritionalInfo($product, [
                'barcode' => $barcode,
                'ingredients' => "Ingredientes ORIGINALES producto {$productNumber}",
                'allergens' => "Alérgenos ORIGINALES {$productNumber}",
                'measure_unit' => 'GR',
                'net_weight' => 100 * $productNumber,
                'gross_weight' => 120 * $productNumber,
                'shelf_life_days' => $productNumber,
                'generate_label' => false,
            ], [
                NutritionalValueType::CALORIES->value => 50 * $productNumber,
                NutritionalValueType::PROTEIN->value => 2.0 * $productNumber,
                NutritionalValueType::FAT_TOTAL->value => 1.0 * $productNumber,
                NutritionalValueType::FAT_SATURATED->value => 0.5 * $productNumber,
                NutritionalValueType::FAT_MONOUNSATURATED->value => 0.3 * $productNumber,
                NutritionalValueType::FAT_POLYUNSATURATED->value => 0.2 * $productNumber,
                NutritionalValueType::FAT_TRANS->value => 0,
                NutritionalValueType::CHOLESTEROL->value => 10 * $productNumber,
                NutritionalValueType::CARBOHYDRATE->value => 5.0 * $productNumber,
                NutritionalValueType::FIBER->value => 0.5 * $productNumber,
                NutritionalValueType::SUGAR->value => 1.0 * $productNumber,
                NutritionalValueType::SODIUM->value => 100 * $productNumber,
                NutritionalValueType::HIGH_SODIUM->value => 0,
                NutritionalValueType::HIGH_CALORIES->value => 0,
                NutritionalValueType::HIGH_FAT->value => 0,
                NutritionalValueType::HIGH_SUGAR->value => 0,
            ]);
        }
    }

    /**
     * Helper to create nutritional information and values
     */
    private function createNutritionalInfo(Product $product, array $infoData, array $valuesData): void
    {
        $nutritionalInfo = NutritionalInformation::create([
            'product_id' => $product->id,
            'barcode' => $infoData['barcode'],
            'ingredients' => $infoData['ingredients'],
            'allergens' => $infoData['allergens'],
            'measure_unit' => $infoData['measure_unit'],
            'net_weight' => $infoData['net_weight'],
            'gross_weight' => $infoData['gross_weight'],
            'shelf_life_days' => $infoData['shelf_life_days'],
            'generate_label' => $infoData['generate_label'],
        ]);

        foreach ($valuesData as $type => $value) {
            NutritionalValue::create([
                'nutritional_information_id' => $nutritionalInfo->id,
                'type' => $type,
                'value' => $value,
            ]);
        }
    }
}