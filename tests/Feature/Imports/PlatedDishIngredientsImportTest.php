<?php

namespace Tests\Feature\Imports;

use App\Models\Category;
use App\Models\PlatedDish;
use App\Models\PlatedDishIngredient;
use App\Models\Product;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ConfiguresNutritionalInformationTests;

/**
 * Plated Dish Ingredients Import Test
 *
 * This test validates that:
 * 1. A product with ingredients creates a PlatedDish record
 * 2. All 5 ingredients are created as PlatedDishIngredient records with ingredient_name
 * 3. Data is stored correctly (ingredient_name, measure_unit, quantity, max_quantity_horeca, order_index)
 * 4. Ingredients are NOT products - they are text names stored directly
 *
 * Test Data: simple_plated_dish.xlsx
 * - 1 product (PROD001)
 * - 5 ingredient names (e.g., "Ingredient 1", "Ingredient 2", etc.)
 */
class PlatedDishIngredientsImportTest extends TestCase
{
    use RefreshDatabase;
    use ConfiguresNutritionalInformationTests;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Test Category',
            'code' => 'TEST',
            'description' => 'Test category',
            'active' => true,
        ]);
    }

    /**
     * Helper: Create a test product
     */
    private function createTestProduct(string $code, string $name = null): Product
    {
        return Product::create([
            'name' => $name ?? "Test Product {$code}",
            'description' => "Product for testing - {$code}",
            'code' => $code,
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
    }

    /**
     * Helper: Create a PlatedDish with ingredients
     */
    private function createPlatedDishWithIngredients(Product $product, array $ingredientsData): PlatedDish
    {
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        foreach ($ingredientsData as $ingredientData) {
            PlatedDishIngredient::create(array_merge([
                'plated_dish_id' => $platedDish->id,
            ], $ingredientData));
        }

        return $platedDish;
    }

    /**
     * Helper: Run import process
     */
    private function runImport(string $testFile): \App\Models\ImportProcess
    {
        $this->assertFileExists($testFile, "Test Excel file should exist at {$testFile}");

        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\PlatedDishRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\PlatedDishIngredientsImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_PLATED_DISH_INGREDIENTS,
            $repository
        );

        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Import should complete successfully. Status: ' . $importProcess->status
        );

        return $importProcess;
    }

    /**
     * Helper: Assert ingredient matches expected data
     */
    private function assertIngredientMatches(
        PlatedDishIngredient $ingredient,
        string $name,
        string $unit,
        float $quantity,
        ?float $maxQuantity,
        int $orderIndex
    ): void {
        $this->assertEquals($name, $ingredient->ingredient_name, "Ingredient name should match");
        $this->assertEquals($unit, $ingredient->measure_unit, "Measure unit should match");
        $this->assertEquals($quantity, $ingredient->quantity, "Quantity should match");
        $this->assertEquals($maxQuantity, $ingredient->max_quantity_horeca, "Max quantity should match");
        $this->assertEquals($orderIndex, $ingredient->order_index, "Order index should match");
    }

    public function test_imports_product_with_ingredients(): void
    {
        // Create the product
        $product = Product::create([
            'name' => 'Test Product with Ingredients',
            'description' => 'Product for testing plated dish import',
            'code' => 'PROD001',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Import the Excel file
        $testFile = base_path('tests/Fixtures/simple_plated_dish.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\PlatedDishRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\PlatedDishIngredientsImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_PLATED_DISH_INGREDIENTS,
            $repository
        );

        // Verify import completed successfully
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Import should complete successfully. Status: ' . $importProcess->status
        );

        // Verify PlatedDish was created
        $product->refresh();
        $this->assertNotNull($product->platedDish, 'Product should have a PlatedDish record');

        $platedDish = $product->platedDish;

        // Verify 5 ingredients were created
        $this->assertEquals(5, $platedDish->ingredients->count(), 'PlatedDish should have 5 ingredients');

        // Verify ingredients are ordered correctly
        $ingredients = $platedDish->ingredients->sortBy('order_index')->values();

        // Ingredient 1: "ING001", 100 GR, 150 max
        $this->assertEquals('ING001', $ingredients[0]->ingredient_name);
        $this->assertEquals('GR', $ingredients[0]->measure_unit);
        $this->assertEquals(100, $ingredients[0]->quantity);
        $this->assertEquals(150, $ingredients[0]->max_quantity_horeca);
        $this->assertEquals(0, $ingredients[0]->order_index);

        // Ingredient 2: "ING002", 50 ML, 75 max
        $this->assertEquals('ING002', $ingredients[1]->ingredient_name);
        $this->assertEquals('ML', $ingredients[1]->measure_unit);
        $this->assertEquals(50, $ingredients[1]->quantity);
        $this->assertEquals(75, $ingredients[1]->max_quantity_horeca);
        $this->assertEquals(1, $ingredients[1]->order_index);

        // Ingredient 3: "Ingredient 3", 2 UND, 3 max
        $this->assertEquals('ING003', $ingredients[2]->ingredient_name);
        $this->assertEquals('UND', $ingredients[2]->measure_unit);
        $this->assertEquals(2, $ingredients[2]->quantity);
        $this->assertEquals(3, $ingredients[2]->max_quantity_horeca);
        $this->assertEquals(2, $ingredients[2]->order_index);

        // Ingredient 4: "Ingredient 4", 25 GR, 30 max
        $this->assertEquals('ING004', $ingredients[3]->ingredient_name);
        $this->assertEquals('GR', $ingredients[3]->measure_unit);
        $this->assertEquals(25, $ingredients[3]->quantity);
        $this->assertEquals(30, $ingredients[3]->max_quantity_horeca);
        $this->assertEquals(3, $ingredients[3]->order_index);

        // Ingredient 5: "Ingredient 5", 10 ML, 15 max
        $this->assertEquals('ING005', $ingredients[4]->ingredient_name);
        $this->assertEquals('ML', $ingredients[4]->measure_unit);
        $this->assertEquals(10, $ingredients[4]->quantity);
        $this->assertEquals(15, $ingredients[4]->max_quantity_horeca);
        $this->assertEquals(4, $ingredients[4]->order_index);
    }

    public function test_updates_existing_product_with_modified_ingredients(): void
    {
        // Create the product
        $product = Product::create([
            'name' => 'Test Product with Ingredients',
            'description' => 'Product for testing plated dish import',
            'code' => 'PROD001',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create initial PlatedDish with original ingredients
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        // Create original ingredients with initial values
        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING001',
            'measure_unit' => 'GR',
            'quantity' => 100,
            'max_quantity_horeca' => 150,
            'order_index' => 0,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING002',
            'measure_unit' => 'ML',
            'quantity' => 50,
            'max_quantity_horeca' => 75,
            'order_index' => 1,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING003',
            'measure_unit' => 'UND',
            'quantity' => 2,
            'max_quantity_horeca' => 3,
            'order_index' => 2,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING004',
            'measure_unit' => 'GR',
            'quantity' => 25,
            'max_quantity_horeca' => 30,
            'order_index' => 3,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING005',
            'measure_unit' => 'ML',
            'quantity' => 10,
            'max_quantity_horeca' => 15,
            'order_index' => 4,
        ]);

        // Verify initial data exists
        $this->assertEquals(5, $platedDish->ingredients->count());

        // Import the Excel file with MODIFIED attributes
        $testFile = base_path('tests/Fixtures/simple_plated_dish_update.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\PlatedDishRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\PlatedDishIngredientsImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_PLATED_DISH_INGREDIENTS,
            $repository
        );

        // Verify import completed successfully
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Import should complete successfully. Status: ' . $importProcess->status
        );

        // Refresh PlatedDish
        $platedDish->refresh();

        // Verify still 5 ingredients (no duplicates)
        $this->assertEquals(5, $platedDish->ingredients->count(), 'Should still have 5 ingredients');

        // Get updated ingredients
        $ingredients = $platedDish->ingredients->sortBy('order_index')->values();

        // Verify Ingredient 1 was UPDATED: GR 100 -> KG 0.200, max 150 -> 0.300
        $this->assertEquals('ING001', $ingredients[0]->ingredient_name);
        $this->assertEquals('KG', $ingredients[0]->measure_unit, 'Unit should be updated to KG');
        $this->assertEquals(0.200, $ingredients[0]->quantity, 'Quantity should be updated to 0.200');
        $this->assertEquals(0.300, $ingredients[0]->max_quantity_horeca, 'Max should be updated to 0.300');
        $this->assertEquals(0, $ingredients[0]->order_index);

        // Verify Ingredient 2 was UPDATED: ML 50 -> L 0.075, max 75 -> 0.100
        $this->assertEquals('ING002', $ingredients[1]->ingredient_name);
        $this->assertEquals('L', $ingredients[1]->measure_unit, 'Unit should be updated to L');
        $this->assertEquals(0.075, $ingredients[1]->quantity, 'Quantity should be updated to 0.075');
        $this->assertEquals(0.100, $ingredients[1]->max_quantity_horeca, 'Max should be updated to 0.100');
        $this->assertEquals(1, $ingredients[1]->order_index);

        // Verify Ingredient 3 was UPDATED: UND 2 -> 5, max 3 -> 10
        $this->assertEquals('ING003', $ingredients[2]->ingredient_name);
        $this->assertEquals('UND', $ingredients[2]->measure_unit);
        $this->assertEquals(5, $ingredients[2]->quantity, 'Quantity should be updated to 5');
        $this->assertEquals(10, $ingredients[2]->max_quantity_horeca, 'Max should be updated to 10');
        $this->assertEquals(2, $ingredients[2]->order_index);

        // Verify Ingredient 4 was UPDATED: GR 25 -> 50, max 30 -> 75
        $this->assertEquals('ING004', $ingredients[3]->ingredient_name);
        $this->assertEquals('GR', $ingredients[3]->measure_unit);
        $this->assertEquals(50, $ingredients[3]->quantity, 'Quantity should be updated to 50');
        $this->assertEquals(75, $ingredients[3]->max_quantity_horeca, 'Max should be updated to 75');
        $this->assertEquals(3, $ingredients[3]->order_index);

        // Verify Ingredient 5 was UPDATED: ML 10 -> 25, max 15 -> 40
        $this->assertEquals('ING005', $ingredients[4]->ingredient_name);
        $this->assertEquals('ML', $ingredients[4]->measure_unit);
        $this->assertEquals(25, $ingredients[4]->quantity, 'Quantity should be updated to 25');
        $this->assertEquals(40, $ingredients[4]->max_quantity_horeca, 'Max should be updated to 40');
        $this->assertEquals(4, $ingredients[4]->order_index);
    }

    public function test_removes_ingredient_when_not_in_excel(): void
    {
        // Create the product
        $product = Product::create([
            'name' => 'Test Product with Ingredients',
            'description' => 'Product for testing plated dish import',
            'code' => 'PROD001',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create initial PlatedDish with 5 ingredients
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING001',
            'measure_unit' => 'GR',
            'quantity' => 100,
            'max_quantity_horeca' => 150,
            'order_index' => 0,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING002',
            'measure_unit' => 'ML',
            'quantity' => 50,
            'max_quantity_horeca' => 75,
            'order_index' => 1,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING003',
            'measure_unit' => 'UND',
            'quantity' => 2,
            'max_quantity_horeca' => 3,
            'order_index' => 2,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING004',
            'measure_unit' => 'GR',
            'quantity' => 25,
            'max_quantity_horeca' => 30,
            'order_index' => 3,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING005',
            'measure_unit' => 'ML',
            'quantity' => 10,
            'max_quantity_horeca' => 15,
            'order_index' => 4,
        ]);

        // Verify initial data: 5 ingredients
        $this->assertEquals(5, $platedDish->ingredients->count());

        // Import Excel file with only 4 ingredients (Ingredient 5 removed)
        $testFile = base_path('tests/Fixtures/simple_plated_dish_4_ingredients.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\PlatedDishRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\PlatedDishIngredientsImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_PLATED_DISH_INGREDIENTS,
            $repository
        );

        // Verify import completed successfully
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Import should complete successfully. Status: ' . $importProcess->status
        );

        // Refresh PlatedDish
        $platedDish->refresh();

        // Verify only 4 ingredients remain (Ingredient 5 was deleted)
        $this->assertEquals(4, $platedDish->ingredients->count(), 'Should have only 4 ingredients after import');

        // Get remaining ingredients
        $ingredients = $platedDish->ingredients->sortBy('order_index')->values();

        // Verify remaining ingredients by name
        $this->assertEquals('ING001', $ingredients[0]->ingredient_name);
        $this->assertEquals('ING002', $ingredients[1]->ingredient_name);
        $this->assertEquals('ING003', $ingredients[2]->ingredient_name);
        $this->assertEquals('ING004', $ingredients[3]->ingredient_name);

        // Verify Ingredient 5 was removed (should not exist in collection)
        $ingredientNames = $ingredients->pluck('ingredient_name')->toArray();
        $this->assertNotContains('ING005', $ingredientNames, 'Ingredient 5 should be removed from ingredients');
    }

    public function test_adds_new_ingredient_from_excel(): void
    {
        // Create the product
        $product = Product::create([
            'name' => 'Test Product with Ingredients',
            'description' => 'Product for testing plated dish import',
            'code' => 'PROD001',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create initial PlatedDish with 5 ingredients
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING001',
            'measure_unit' => 'GR',
            'quantity' => 100,
            'max_quantity_horeca' => 150,
            'order_index' => 0,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING002',
            'measure_unit' => 'ML',
            'quantity' => 50,
            'max_quantity_horeca' => 75,
            'order_index' => 1,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING003',
            'measure_unit' => 'UND',
            'quantity' => 2,
            'max_quantity_horeca' => 3,
            'order_index' => 2,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING004',
            'measure_unit' => 'GR',
            'quantity' => 25,
            'max_quantity_horeca' => 30,
            'order_index' => 3,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'ING005',
            'measure_unit' => 'ML',
            'quantity' => 10,
            'max_quantity_horeca' => 15,
            'order_index' => 4,
        ]);

        // Verify initial data: 5 ingredients
        $this->assertEquals(5, $platedDish->ingredients->count());

        // Import Excel file with 6 ingredients (Ingredient 6 added)
        $testFile = base_path('tests/Fixtures/simple_plated_dish_6_ingredients.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\PlatedDishRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\PlatedDishIngredientsImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_PLATED_DISH_INGREDIENTS,
            $repository
        );

        // Verify import completed successfully
        $this->assertTrue(
            $importService->wasSuccessful($importProcess),
            'Import should complete successfully. Status: ' . $importProcess->status
        );

        // Refresh PlatedDish
        $platedDish->refresh();

        // Verify now 6 ingredients exist (Ingredient 6 was added)
        $this->assertEquals(6, $platedDish->ingredients->count(), 'Should have 6 ingredients after import');

        // Get all ingredients
        $ingredients = $platedDish->ingredients->sortBy('order_index')->values();

        // Verify all 6 ingredients by name
        $this->assertEquals('ING001', $ingredients[0]->ingredient_name);
        $this->assertEquals('ING002', $ingredients[1]->ingredient_name);
        $this->assertEquals('ING003', $ingredients[2]->ingredient_name);
        $this->assertEquals('ING004', $ingredients[3]->ingredient_name);
        $this->assertEquals('ING005', $ingredients[4]->ingredient_name);
        $this->assertEquals('ING006', $ingredients[5]->ingredient_name);

        // Verify Ingredient 6 details
        $this->assertEquals('KG', $ingredients[5]->measure_unit);
        $this->assertEquals(0.5, $ingredients[5]->quantity);
        $this->assertEquals(1.0, $ingredients[5]->max_quantity_horeca);
        $this->assertEquals(5, $ingredients[5]->order_index);
    }

    public function test_validates_import_errors_and_logs_them_correctly(): void
    {
        // Create valid products (PROD001-PROD005)
        for ($i = 1; $i <= 5; $i++) {
            Product::create([
                'name' => "Valid Product {$i}",
                'description' => "Product {$i} for validation testing",
                'code' => 'PROD' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'category_id' => $this->category->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);
        }

        // Create products that will have errors (PROD007-PROD010)
        // PROD006 doesn't exist (empty code)
        $prod007 = Product::create([
            'name' => 'Product with Empty Ingredient',
            'description' => 'Product 7',
            'code' => 'PROD007',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $prod008 = Product::create([
            'name' => 'Product with Invalid Unit',
            'description' => 'Product 8',
            'code' => 'PROD008',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $prod009 = Product::create([
            'name' => 'Product with Invalid Quantity',
            'description' => 'Product 9',
            'code' => 'PROD009',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $prod010 = Product::create([
            'name' => 'Product with Invalid Max Qty',
            'description' => 'Product 10',
            'code' => 'PROD010',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Create ingredient products
        for ($i = 1; $i <= 4; $i++) {
            Product::create([
                'name' => "Ingredient {$i}",
                'description' => "Ingredient product {$i}",
                'code' => 'ING' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'category_id' => $this->category->id,
                'active' => true,
                'measure_unit' => 'GR',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);
        }

        // Import Excel file with validation errors
        $testFile = base_path('tests/Fixtures/plated_dish_with_validation_errors.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist');

        $importService = app(ImportService::class);
        $repository = app(\App\Contracts\PlatedDishRepositoryInterface::class);

        $importProcess = $importService->importWithRepository(
            \App\Imports\PlatedDishIngredientsImport::class,
            $testFile,
            \App\Models\ImportProcess::TYPE_PLATED_DISH_INGREDIENTS,
            $repository
        );

        // TDD RED PHASE: Import should fail with validation errors
        $this->assertFalse(
            $importService->wasSuccessful($importProcess),
            'Import should fail due to validation errors'
        );

        // Verify import status is "procesado con errores"
        $this->assertEquals(
            \App\Models\ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
            $importProcess->status,
            'Import process status should be "procesado con errores"'
        );

        // Verify error log exists and contains errors
        $this->assertNotNull($importProcess->error_log, 'Error log should not be null');
        $this->assertIsArray($importProcess->error_log, 'Error log should be an array');
        $this->assertGreaterThan(0, count($importProcess->error_log), 'Error log should contain errors');

        // Expected errors:
        // 1. Empty product code: 4 rows
        // 2. Empty ingredient code: 3 rows (one ingredient is valid)
        // 3. Invalid measure unit: 3 rows (one is valid)
        // 4. Invalid quantity: 3 rows (one is valid)
        // 5. Invalid max quantity: 3 rows (one is valid)

        $errorLog = $importProcess->error_log;

        // Count errors by type
        $emptyProductCodeErrors = 0;
        $emptyIngredientErrors = 0;
        $invalidUnitErrors = 0;
        $invalidQuantityErrors = 0;
        $invalidMaxQuantityErrors = 0;

        foreach ($errorLog as $error) {
            $attribute = $error['attribute'] ?? '';
            $errors = $error['errors'] ?? [];

            if ($attribute === 'codigo_de_producto') {
                $emptyProductCodeErrors++;
            }

            if ($attribute === 'emplatado' && in_array('El ingrediente con código \'\' no existe en la base de datos', $errors)) {
                $emptyIngredientErrors++;
            }

            if ($attribute === 'unidad_de_medida') {
                $invalidUnitErrors++;
            }

            if ($attribute === 'cantidad' && isset($errors[0]) && str_contains($errors[0], 'debe ser un número')) {
                $invalidQuantityErrors++;
            }

            if ($attribute === 'cantidad_maxima_horeca' && isset($errors[0]) && str_contains($errors[0], 'debe ser un número')) {
                $invalidMaxQuantityErrors++;
            }
        }

        // TDD RED PHASE: These assertions will help identify which validations are missing
        $this->assertGreaterThan(0, $emptyProductCodeErrors, 'Should have errors for empty product code');
        $this->assertGreaterThan(0, $invalidUnitErrors, 'Should have errors for invalid measure unit');
        $this->assertGreaterThan(0, $invalidQuantityErrors, 'Should have errors for invalid quantity');
        $this->assertGreaterThan(0, $invalidMaxQuantityErrors, 'Should have errors for invalid max quantity');
    }

    /**
     * Test Complete Production Data Import
     *
     * This test validates that ALL products and ALL ingredients from the production
     * Excel file (emplatado_data_VERTICAL.xlsx) are imported correctly.
     *
     * PRODUCTION DATA ANALYSIS:
     * - Total products: 374
     * - Products WITH ingredients: 48
     * - Products WITHOUT ingredients: 326
     * - Total ingredient rows: 83
     *
     * EXPECTED BEHAVIOR:
     * - Import should create 374 PlatedDish records (ALL products, including those without ingredients)
     * - Import should create 83 PlatedDishIngredient records
     * - Products without ingredients should have PlatedDish records with 0 ingredients
     *
     * SAMPLE DATA (first 5 products):
     * 1. ACM00000007: 1 ingredient (MZC - CONSOME DE POLLO GRANEL)
     * 2. ACM00000008: 1 ingredient (MZC - CONSOME DE POLLO GRANEL)
     * 3. ACM00000009: 1 ingredient (MZC - CONSOME DE POLLO GRANEL)
     * 4. ACM00000010: 1 ingredient (MZC - CONSOME DE VACUNO GRANEL)
     * 5. ACM00000011: 1 ingredient (MZC - CONSOME DE VACUNO GRANEL)
     */
    public function test_imports_all_products_and_ingredients_from_production_excel(): void
    {
        // Create ALL 374 products from Excel file using bulk insert for efficiency
        $productCodes = [
            'ACM00000007', 'ACM00000008', 'ACM00000009', 'ACM00000010', 'ACM00000011',
            'ACM00000012', 'ACM00000013', 'ACM00000014', 'ACM00000015', 'ACM00000016',
            'ACM00000017', 'ACM00000018', 'ACM00000019', 'ACM00000020', 'ACM00000021',
            'ACM00000022', 'ACM00000023', 'ACM00000024', 'ACM00000025', 'ACM00000026',
            'ACM00000027', 'ACM00000028', 'ACM00000029', 'ACM00000030', 'ACM00000031',
            'ACM00000032', 'ACM00000033', 'ACM00000034', 'ACM00000035', 'ACM00000036',
            'ACM00000037', 'ACM00000038', 'ACM00000039', 'ACM00000040', 'ACM00000041',
            'ACM00000062', 'ACM00000063', 'ACM00000064', 'PCFH00000001', 'PCFH00000002',
            'PCFH00000003', 'PCFH00000004', 'PCFH00000005', 'PCFH00000006', 'PCFH00000007',
            'PCH00000001', 'PCH00000002', 'PCH00000003', 'PCH00000004', 'PCH00000005',
            'PCH00000006', 'PCH00000007', 'PCH00000008', 'PCH00000009', 'PCH00000010',
            'PCH00000011', 'PCH00000012', 'PCH00000013', 'PCH00000014', 'PCH00000015',
            'PCH00000016', 'PCH00000017', 'PCH00000018', 'PCH00000019', 'PCH00000020',
            'PCH00000021', 'PCH00000022', 'PCH00000023', 'PCH00000024', 'PCH00000025',
            'PCH00000026', 'PCH00000027', 'PCH00000028', 'PCH00000029', 'PCH00000030',
            'PCH00000031', 'PCH00000032', 'PCH00000033', 'PCH00000034', 'PCH00000035',
            'PCH00000036', 'PCH00000037', 'PCH00000038', 'PCH00000039', 'PCH00000040',
            'PCH00000041', 'PCH00000042', 'PCH00000043', 'PCH00000044', 'PCH00000045',
            'PCH00000046', 'PCH00000047', 'PCH00000048', 'PCH00000049', 'PCH00000050',
            'PCH00000051', 'PCH00000052', 'PCH00000053', 'PCH00000054', 'PCH00000055',
            'PCH00000056', 'PCH00000057', 'PCH00000058', 'PCH00000059', 'PCH00000060',
            'PCH00000061', 'PCH00000062', 'PCH00000063', 'PCH00000064', 'PCH00000065',
            'PCH00000066', 'PCH00000067', 'PCH00000068', 'PCH00000069', 'PCH00000070',
            'PCH00000071', 'PCH00000072', 'PCH00000073', 'PCH00000074', 'PCH00000075',
            'PCH00000076', 'PCH00000077', 'PCH00000078', 'PCH00000079', 'PCH00000080',
            'PCH00000081', 'PCH00000082', 'PCH00000083', 'PCH00000084', 'PCH00000085',
            'PCH00000086', 'PCH00000087', 'PCH00000088', 'PCH00000089', 'PCH00000090',
            'PCH00000091', 'PCH00000092', 'PCH00000093', 'PCH00000094', 'PCH00000095',
            'PCH00000096', 'PCH00000097', 'PCH00000098', 'PCH00000099', 'PCH00000100',
            'PCH00000101', 'PCH00000102', 'PCH00000103', 'PCH00000104', 'PCH00000105',
            'PCH00000106', 'PCH00000107', 'PCH00000108', 'PCH00000109', 'PCH00000110',
            'PCH00000111', 'PCH00000112', 'PCH00000113', 'PCH00000114', 'PCH00000115',
            'PCH00000116', 'PCH00000117', 'PCH00000118', 'PCH00000119', 'PCH00000120',
            'PCH00000121', 'PCH00000122', 'PCH00000123', 'PCH00000124', 'PCH00000125',
            'PCH00000126', 'PCH00000127', 'PCH00000128', 'PCH00000129', 'PCH00000130',
            'PCH00000131', 'PCH00000132', 'PCH00000133', 'PCH00000134', 'PCH00000135',
            'PCH00000136', 'PCH00000137', 'PCH00000138', 'PCH00000139', 'PCH00000140',
            'PCH00000141', 'PCH00000142', 'PCH00000143', 'PCH00000144', 'PCH00000145',
            'PCH00000146', 'PCH00000147', 'PCH00000148', 'PCH00000149', 'PCH00000150',
            'PCH00000151', 'PCH00000152', 'PCH00000153', 'PCH00000154', 'PCH00000155',
            'PCH00000156', 'PCH00000157', 'PCH00000158', 'PCH00000159', 'PCH00000160',
            'PCH00000161', 'PCH00000162', 'PCH00000163', 'PCH00000164', 'PCH00000165',
            'PCH00000166', 'PCH00000167', 'PCH00000168', 'PCH00000169', 'PCH00000170',
            'PCH00000171', 'PCH00000172', 'PCH00000173', 'PCH00000174', 'PCH00000175',
            'PCH00000176', 'PCH00000177', 'PCH00000178', 'PCH00000179', 'PCH00000180',
            'PCH00000181', 'PCH00000182', 'PCH00000183', 'PCH00000184', 'PCH00000185',
            'PCH00000186', 'PCH00000187', 'PCH00000188', 'PCH00000189', 'PCH00000190',
            'PCH00000191', 'PCH00000192', 'PCH00000193', 'PCH00000194', 'PCH00000195',
            'PCH00000196', 'PCH00000197', 'PCH00000198', 'PCH00000199', 'PCH00000200',
            'PCH00000201', 'PCH00000202', 'PCH00000203', 'PCH00000204', 'PCH00000205',
            'PCH00000206', 'PCH00000207', 'PCH00000208', 'PCH00000209', 'PCH00000210',
            'PCH00000211', 'PCH00000212', 'PCH00000213', 'PCH00000214', 'PCH00000215',
            'PCH00000216', 'PCH00000217', 'PCH00000218', 'PCH00000219', 'PCH00000220',
            'PCH00000221', 'PCH00000222', 'PCH00000223', 'PCH00000224', 'PCH00000225',
            'PCH00000226', 'PCH00000227', 'PCH00000228', 'PCH00000229', 'PCH00000230',
            'PCH00000231', 'PCH00000232', 'PCH00000233', 'PCH00000234', 'PCH00000235',
            'PCH00000236', 'PCH00000237', 'PCH00000238', 'PCH00000239', 'PCH00000240',
            'PCH00000241', 'PCH00000242', 'PCH00000243', 'PCH00000244', 'PCH00000245',
            'PCH00000246', 'PCH00000247', 'PCH00000248', 'PCH00000249', 'PCH00000250',
            'PCH00000251', 'PCH00000252', 'PCH00000253', 'PCH00000254', 'PCH00000255',
            'PCH00000256', 'PCH00000257', 'PCH00000258', 'PCH00000259', 'PCH00000260',
            'PCH00000261', 'PCH00000262', 'PCH00000263', 'PCH00000264', 'PCH00000265',
            'PCH00000266', 'PCH00000267', 'PCH00000268', 'PCH00000269', 'PCH00000270',
            'PCH00000271', 'PCH00000272', 'PCH00000273', 'PVH00000001', 'PVH00000002',
            'PVH00000003', 'PVH00000004', 'PVH00000005', 'PVH00000006', 'PVH00000007',
            'PVH00000008', 'PVH00000009', 'PVH00000010', 'PVH00000011', 'PVH00000012',
            'PVH00000013', 'PVH00000014', 'PVH00000015', 'PVH00000016', 'PVH00000017',
            'PVH00000018', 'PVH00000019', 'PVH00000020', 'PVH00000021', 'PVH00000022',
            'PVH00000023', 'PVH00000024', 'PVH00000025', 'PVH00000026', 'PVH00000027',
            'PVH00000028', 'PVH00000029', 'PVH00000030', 'PVH00000031', 'PVH00000032',
            'PVH00000033', 'PVH00000034', 'PVH00000035', 'PVH00000036', 'PVH00000037',
            'PVH00000038', 'PVH00000039', 'PVH00000040', 'PVH00000041', 'PVH00000042',
            'PVH00000043', 'PVH00000044', 'PVH00000045', 'PVH00000046', 'PVH00000047',
            'PVH00000048', 'PVH00000049', 'PVH00000050', 'PVH00000051', 'PVH00000052',
            'PVH00000053', 'PVH00000054', 'PVH00000055', 'PVH00000056',
        ];

        // Create all 374 products using helper method
        foreach ($productCodes as $code) {
            $this->createTestProduct($code);
        }

        // Run import with production Excel file
        $testFile = base_path('tests/Fixtures/emplatado_data_VERTICAL.xlsx');
        $importProcess = $this->runImport($testFile);

        // ===== VERIFY PLATED DISHES CREATED =====
        // Expected: 374 PlatedDish records (ALL products, even those without ingredients)
        $platedDishCount = PlatedDish::count();
        $this->assertEquals(
            374,
            $platedDishCount,
            "Should create exactly 374 PlatedDish records (all products, including those without ingredients)"
        );

        // ===== VERIFY TOTAL INGREDIENTS CREATED =====
        // Expected: 83 total ingredient rows
        $ingredientCount = PlatedDishIngredient::count();
        $this->assertEquals(
            83,
            $ingredientCount,
            "Should create exactly 83 PlatedDishIngredient records"
        );

        // ===== VERIFY SPECIFIC PRODUCTS =====

        // Test Product 1: ACM00000007 - Should have 1 ingredient
        $product1 = Product::where('code', 'ACM00000007')->first();
        $this->assertNotNull($product1->platedDish, "Product ACM00000007 should have PlatedDish");
        $this->assertEquals(
            1,
            $product1->platedDish->ingredients->count(),
            "ACM00000007 should have 1 ingredient"
        );

        $ingredient1 = $product1->platedDish->ingredients->first();
        $this->assertIngredientMatches(
            $ingredient1,
            'MZC - CONSOME DE POLLO GRANEL',
            'GR',
            1000.0,
            1000.0,
            0
        );

        // Test Product 2: ACM00000008 - Should have 1 ingredient
        $product2 = Product::where('code', 'ACM00000008')->first();
        $this->assertNotNull($product2->platedDish, "Product ACM00000008 should have PlatedDish");
        $this->assertEquals(
            1,
            $product2->platedDish->ingredients->count(),
            "ACM00000008 should have 1 ingredient"
        );

        // Test Product with multiple ingredients: PVH00000048 - Should have 3 ingredients
        $product3 = Product::where('code', 'PVH00000048')->first();
        $this->assertNotNull($product3->platedDish, "Product PVH00000048 should have PlatedDish");

        // Note: Based on production analysis, PVH00000048 should have 3 ingredients
        // but only 1 was found in DB. This test will validate the EXPECTED behavior (3).
        $ingredientsPVH48 = $product3->platedDish->ingredients()->orderBy('order_index')->get();
        $this->assertEquals(
            3,
            $ingredientsPVH48->count(),
            "PVH00000048 should have 3 ingredients"
        );

        // Verify ingredient names for PVH00000048
        $expectedIngredients = [
            'MZC - QUESO PARMESANO RALLADO',
            'MZC - SALSA DE RAGU BLANCO VEGETARIANO',
            'MZC - PASTA COCIDA',
        ];

        $actualIngredientNames = $ingredientsPVH48->pluck('ingredient_name')->toArray();
        foreach ($expectedIngredients as $expectedName) {
            $this->assertContains(
                $expectedName,
                $actualIngredientNames,
                "PVH00000048 should have ingredient: {$expectedName}"
            );
        }

        // ===== VERIFY PRODUCTS WITHOUT INGREDIENTS ARE SKIPPED =====
        // Products without ingredients should NOT have PlatedDish records
        // Example: Create a product that exists in Excel but has no ingredients
        $productWithoutIngredients = $this->createTestProduct('TEST-NO-ING', 'Test Product Without Ingredients');
        $this->assertNull(
            $productWithoutIngredients->platedDish,
            "Product without ingredients should NOT have PlatedDish record"
        );

        // ===== VERIFY INGREDIENT DATA INTEGRITY =====
        // All ingredients should have:
        // - Valid ingredient_name (not null, not empty)
        // - Valid measure_unit (GR, KG, ML, L, UND)
        // - Valid quantity (> 0)
        // - Valid order_index (>= 0)

        $allIngredients = PlatedDishIngredient::all();
        foreach ($allIngredients as $ingredient) {
            $this->assertNotEmpty(
                $ingredient->ingredient_name,
                "Ingredient ID {$ingredient->id} should have non-empty name"
            );

            $this->assertContains(
                $ingredient->measure_unit,
                ['GR', 'KG', 'ML', 'L', 'UND'],
                "Ingredient ID {$ingredient->id} should have valid measure unit"
            );

            $this->assertGreaterThan(
                0,
                $ingredient->quantity,
                "Ingredient ID {$ingredient->id} should have positive quantity"
            );

            $this->assertGreaterThanOrEqual(
                0,
                $ingredient->order_index,
                "Ingredient ID {$ingredient->id} should have non-negative order_index"
            );
        }
    }

    /**
     * Test imports two products: one with ingredients and one without
     *
     * EXPECTED BEHAVIOR (what SHOULD happen):
     * 1. Product WITH ingredients creates a PlatedDish record with 3 ingredients
     * 2. Product WITHOUT ingredients ALSO creates a PlatedDish record (with 0 ingredients)
     * 3. Both products should have PlatedDish records in database after import
     *
     * CURRENT BUG:
     * - Products without ingredients are skipped and don't create PlatedDish records
     * - This test will FAIL until the bug is fixed
     */
    public function test_imports_product_with_and_without_ingredients(): void
    {
        // Create two test products
        $productWithIngredients = $this->createTestProduct('PROD-WITH', 'Product With Ingredients');
        $productWithoutIngredients = $this->createTestProduct('PROD-WITHOUT', 'Product Without Ingredients');

        // Verify both products exist before import
        $this->assertNotNull($productWithIngredients);
        $this->assertNotNull($productWithoutIngredients);

        // Import the Excel file with both products
        $testFile = base_path('tests/Fixtures/two_products_one_with_one_without_ingredients.xlsx');
        $importProcess = $this->runImport($testFile);

        // Refresh products from database
        $productWithIngredients->refresh();
        $productWithoutIngredients->refresh();

        // ===== VERIFY PRODUCT WITH INGREDIENTS =====
        $this->assertNotNull(
            $productWithIngredients->platedDish,
            'Product PROD-WITH should have a PlatedDish record'
        );

        // Verify PlatedDish has 3 ingredients
        $ingredients = $productWithIngredients->platedDish->ingredients()->orderBy('order_index')->get();
        $this->assertEquals(3, $ingredients->count(), 'Product PROD-WITH should have exactly 3 ingredients');

        // Verify first ingredient
        $this->assertEquals('Ingredient 1', $ingredients[0]->ingredient_name);
        $this->assertEquals('GR', $ingredients[0]->measure_unit);
        $this->assertEquals(100, $ingredients[0]->quantity);

        // Verify second ingredient
        $this->assertEquals('Ingredient 2', $ingredients[1]->ingredient_name);
        $this->assertEquals('ML', $ingredients[1]->measure_unit);
        $this->assertEquals(50, $ingredients[1]->quantity);

        // Verify third ingredient
        $this->assertEquals('Ingredient 3', $ingredients[2]->ingredient_name);
        $this->assertEquals('UND', $ingredients[2]->measure_unit);
        $this->assertEquals(2, $ingredients[2]->quantity);

        // ===== VERIFY PRODUCT WITHOUT INGREDIENTS =====
        // EXPECTED: Product without ingredients SHOULD have a PlatedDish record (with 0 ingredients)
        // This assertion will FAIL with current code (bug to be fixed)
        $this->assertNotNull(
            $productWithoutIngredients->platedDish,
            'Product PROD-WITHOUT SHOULD have a PlatedDish record (even with 0 ingredients)'
        );

        // Verify PlatedDish has 0 ingredients
        $this->assertEquals(
            0,
            $productWithoutIngredients->platedDish->ingredients->count(),
            'Product PROD-WITHOUT should have 0 ingredients'
        );

        // Verify import was successful overall
        $this->assertTrue(
            app(ImportService::class)->wasSuccessful($importProcess),
            'Import should complete successfully'
        );
    }

    /**
     * Test updates product without ingredients to have ingredients
     *
     * SCENARIO:
     * 1. Database has a product WITHOUT ingredients (PlatedDish record exists with 0 ingredients)
     * 2. Excel file contains the SAME product WITH 5 ingredients
     * 3. After import, the product should have 5 ingredients
     *
     * EXPECTED BEHAVIOR:
     * - Product that had 0 ingredients should now have 5 ingredients
     * - PlatedDish record should be updated (not duplicated)
     * - All 5 ingredients should be correctly created
     */
    public function test_updates_product_without_ingredients_to_have_ingredients(): void
    {
        // Create a product
        $product = $this->createTestProduct('PROD001', 'Test Product Initially Without Ingredients');

        // Create PlatedDish record WITHOUT ingredients (simulating product without ingredients)
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        // Verify product has PlatedDish but NO ingredients
        $this->assertNotNull($product->platedDish, 'Product should have PlatedDish record');
        $this->assertEquals(0, $platedDish->ingredients->count(), 'Product should start with 0 ingredients');

        // Import Excel file with 5 ingredients for this product
        $testFile = base_path('tests/Fixtures/simple_plated_dish.xlsx');
        $importProcess = $this->runImport($testFile);

        // Refresh product and plated dish from database
        $product->refresh();
        $platedDish->refresh();

        // Verify PlatedDish was updated (not duplicated)
        $this->assertEquals(
            1,
            PlatedDish::where('product_id', $product->id)->count(),
            'Should have exactly 1 PlatedDish record (no duplicates)'
        );

        // Verify product NOW has 5 ingredients
        $this->assertEquals(
            5,
            $platedDish->ingredients->count(),
            'Product should now have 5 ingredients after import'
        );

        // Verify ingredients are correct
        $ingredients = $platedDish->ingredients->sortBy('order_index')->values();

        // Ingredient 1
        $this->assertIngredientMatches(
            $ingredients[0],
            'ING001',
            'GR',
            100.0,
            150.0,
            0
        );

        // Ingredient 2
        $this->assertIngredientMatches(
            $ingredients[1],
            'ING002',
            'ML',
            50.0,
            75.0,
            1
        );

        // Ingredient 3
        $this->assertIngredientMatches(
            $ingredients[2],
            'ING003',
            'UND',
            2.0,
            3.0,
            2
        );

        // Ingredient 4
        $this->assertIngredientMatches(
            $ingredients[3],
            'ING004',
            'GR',
            25.0,
            30.0,
            3
        );

        // Ingredient 5
        $this->assertIngredientMatches(
            $ingredients[4],
            'ING005',
            'ML',
            10.0,
            15.0,
            4
        );

        // Verify import was successful
        $this->assertTrue(
            app(ImportService::class)->wasSuccessful($importProcess),
            'Import should complete successfully'
        );
    }

    /**
     * TDD RED PHASE: Test imports shelf_life field for ingredients
     *
     * SCENARIO:
     * 1. Excel file contains a product with 5 ingredients
     * 2. Each ingredient has a "VIDA UTIL" (shelf life) column with values in days
     * 3. After import, all ingredients should have shelf_life stored correctly
     *
     * EXPECTED BEHAVIOR:
     * - Import should create PlatedDish with 5 ingredients
     * - Each ingredient should have shelf_life field populated
     * - Shelf life values should match Excel data
     *
     * TEST DATA (simple_plated_dish_with_shelf_life.xlsx):
     * - Product: PROD001 (Test Product with Shelf Life)
     * - Ingredient 1: ING001, 100 GR, shelf_life = 7 days
     * - Ingredient 2: ING002, 50 ML, shelf_life = 3 days
     * - Ingredient 3: ING003, 2 UND, shelf_life = 30 days
     * - Ingredient 4: ING004, 25 GR, shelf_life = 5 days
     * - Ingredient 5: ING005, 10 ML, shelf_life = 15 days
     *
     * TDD PHASE: RED
     * This test will FAIL because:
     * 1. The migration for shelf_life column may not exist yet
     * 2. The import logic doesn't handle the VIDA UTIL column yet
     * 3. The PlatedDishIngredient model may not have shelf_life in fillable array
     */
    public function test_imports_shelf_life_field_for_ingredients(): void
    {
        // Create the product
        $product = $this->createTestProduct('PROD001', 'Test Product with Shelf Life');

        // Verify product exists
        $this->assertNotNull($product, 'Product should be created');

        // Import Excel file with shelf_life column
        $testFile = base_path('tests/Fixtures/simple_plated_dish_with_shelf_life.xlsx');
        $this->assertFileExists($testFile, 'Test Excel file should exist at ' . $testFile);

        $importProcess = $this->runImport($testFile);

        // Refresh product from database
        $product->refresh();

        // ===== VERIFY PLATED DISH WAS CREATED =====
        $this->assertNotNull(
            $product->platedDish,
            'Product should have a PlatedDish record after import'
        );

        $platedDish = $product->platedDish;

        // ===== VERIFY 5 INGREDIENTS WERE CREATED =====
        $this->assertEquals(
            5,
            $platedDish->ingredients->count(),
            'PlatedDish should have exactly 5 ingredients'
        );

        // Get ingredients ordered by order_index
        $ingredients = $platedDish->ingredients->sortBy('order_index')->values();

        // ===== VERIFY INGREDIENT 1: ING001, shelf_life = 7 days =====
        $ingredient1 = $ingredients[0];
        $this->assertEquals('ING001', $ingredient1->ingredient_name, 'Ingredient 1 name should match');
        $this->assertEquals('GR', $ingredient1->measure_unit, 'Ingredient 1 unit should match');
        $this->assertEquals(100, $ingredient1->quantity, 'Ingredient 1 quantity should match');
        $this->assertEquals(150, $ingredient1->max_quantity_horeca, 'Ingredient 1 max quantity should match');
        $this->assertEquals(0, $ingredient1->order_index, 'Ingredient 1 order_index should match');

        // TDD RED: This will fail - shelf_life field doesn't exist yet
        $this->assertNotNull(
            $ingredient1->shelf_life,
            'Ingredient 1 should have shelf_life field populated'
        );
        $this->assertEquals(
            7,
            $ingredient1->shelf_life,
            'Ingredient 1 shelf_life should be 7 days'
        );

        // ===== VERIFY INGREDIENT 2: ING002, shelf_life = 3 days =====
        $ingredient2 = $ingredients[1];
        $this->assertEquals('ING002', $ingredient2->ingredient_name, 'Ingredient 2 name should match');
        $this->assertEquals('ML', $ingredient2->measure_unit, 'Ingredient 2 unit should match');
        $this->assertEquals(50, $ingredient2->quantity, 'Ingredient 2 quantity should match');
        $this->assertEquals(75, $ingredient2->max_quantity_horeca, 'Ingredient 2 max quantity should match');
        $this->assertEquals(1, $ingredient2->order_index, 'Ingredient 2 order_index should match');

        // TDD RED: This will fail
        $this->assertNotNull(
            $ingredient2->shelf_life,
            'Ingredient 2 should have shelf_life field populated'
        );
        $this->assertEquals(
            3,
            $ingredient2->shelf_life,
            'Ingredient 2 shelf_life should be 3 days'
        );

        // ===== VERIFY INGREDIENT 3: ING003, shelf_life = 30 days =====
        $ingredient3 = $ingredients[2];
        $this->assertEquals('ING003', $ingredient3->ingredient_name, 'Ingredient 3 name should match');
        $this->assertEquals('UND', $ingredient3->measure_unit, 'Ingredient 3 unit should match');
        $this->assertEquals(2, $ingredient3->quantity, 'Ingredient 3 quantity should match');
        $this->assertEquals(3, $ingredient3->max_quantity_horeca, 'Ingredient 3 max quantity should match');
        $this->assertEquals(2, $ingredient3->order_index, 'Ingredient 3 order_index should match');

        // TDD RED: This will fail
        $this->assertNotNull(
            $ingredient3->shelf_life,
            'Ingredient 3 should have shelf_life field populated'
        );
        $this->assertEquals(
            30,
            $ingredient3->shelf_life,
            'Ingredient 3 shelf_life should be 30 days'
        );

        // ===== VERIFY INGREDIENT 4: ING004, shelf_life = 5 days =====
        $ingredient4 = $ingredients[3];
        $this->assertEquals('ING004', $ingredient4->ingredient_name, 'Ingredient 4 name should match');
        $this->assertEquals('GR', $ingredient4->measure_unit, 'Ingredient 4 unit should match');
        $this->assertEquals(25, $ingredient4->quantity, 'Ingredient 4 quantity should match');
        $this->assertEquals(30, $ingredient4->max_quantity_horeca, 'Ingredient 4 max quantity should match');
        $this->assertEquals(3, $ingredient4->order_index, 'Ingredient 4 order_index should match');

        // TDD RED: This will fail
        $this->assertNotNull(
            $ingredient4->shelf_life,
            'Ingredient 4 should have shelf_life field populated'
        );
        $this->assertEquals(
            5,
            $ingredient4->shelf_life,
            'Ingredient 4 shelf_life should be 5 days'
        );

        // ===== VERIFY INGREDIENT 5: ING005, shelf_life = 15 days =====
        $ingredient5 = $ingredients[4];
        $this->assertEquals('ING005', $ingredient5->ingredient_name, 'Ingredient 5 name should match');
        $this->assertEquals('ML', $ingredient5->measure_unit, 'Ingredient 5 unit should match');
        $this->assertEquals(10, $ingredient5->quantity, 'Ingredient 5 quantity should match');
        $this->assertEquals(15, $ingredient5->max_quantity_horeca, 'Ingredient 5 max quantity should match');
        $this->assertEquals(4, $ingredient5->order_index, 'Ingredient 5 order_index should match');

        // TDD RED: This will fail
        $this->assertNotNull(
            $ingredient5->shelf_life,
            'Ingredient 5 should have shelf_life field populated'
        );
        $this->assertEquals(
            15,
            $ingredient5->shelf_life,
            'Ingredient 5 shelf_life should be 15 days'
        );

        // ===== VERIFY IMPORT WAS SUCCESSFUL =====
        $this->assertTrue(
            app(ImportService::class)->wasSuccessful($importProcess),
            'Import should complete successfully'
        );
    }

    /**
     * Test updates existing ingredients shelf_life values
     *
     * SCENARIO:
     * 1. Database has a product WITH 3 ingredients, each with initial shelf_life values
     * 2. Excel file contains the SAME product WITH SAME ingredients BUT DIFFERENT shelf_life values
     * 3. After import, all ingredients should have UPDATED shelf_life values
     *
     * EXPECTED BEHAVIOR:
     * - Import should UPDATE existing PlatedDish ingredients
     * - Shelf life values should be changed to new values from Excel
     * - Other fields (quantity, measure_unit, etc.) should remain the same
     *
     * TEST DATA:
     * - Initial state (created in DB):
     *   - INGREDIENT_A: shelf_life = 5 days
     *   - INGREDIENT_B: shelf_life = 7 days
     *   - INGREDIENT_C: shelf_life = 15 days
     *
     * - Excel file (update_shelf_life_plated_dish.xlsx):
     *   - INGREDIENT_A: shelf_life = 10 days (5 → 10)
     *   - INGREDIENT_B: shelf_life = 14 days (7 → 14)
     *   - INGREDIENT_C: shelf_life = 30 days (15 → 30)
     */
    public function test_updates_shelf_life_for_existing_ingredients(): void
    {
        // ===== STEP 1: CREATE PRODUCT =====
        $product = $this->createTestProduct('UPD001', 'Product for Shelf Life Update Test');
        $this->assertNotNull($product, 'Product should be created');

        // ===== STEP 2: CREATE PLATED DISH WITH INITIAL INGREDIENTS =====
        $platedDish = PlatedDish::create([
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        // Create 3 ingredients with INITIAL shelf_life values
        $ingredient1 = PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'INGREDIENT_A',
            'measure_unit' => 'GR',
            'quantity' => 200,
            'max_quantity_horeca' => 250,
            'order_index' => 0,
            'is_optional' => false,
            'shelf_life' => 5,  // Initial value: 5 days
        ]);

        $ingredient2 = PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'INGREDIENT_B',
            'measure_unit' => 'ML',
            'quantity' => 100,
            'max_quantity_horeca' => 150,
            'order_index' => 1,
            'is_optional' => false,
            'shelf_life' => 7,  // Initial value: 7 days
        ]);

        $ingredient3 = PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_name' => 'INGREDIENT_C',
            'measure_unit' => 'UND',
            'quantity' => 3,
            'max_quantity_horeca' => 5,
            'order_index' => 2,
            'is_optional' => false,
            'shelf_life' => 15,  // Initial value: 15 days
        ]);

        // ===== STEP 3: VERIFY INITIAL STATE =====
        $this->assertEquals(3, $platedDish->ingredients->count(), 'Should have 3 ingredients initially');
        $this->assertEquals(5, $ingredient1->shelf_life, 'Ingredient A initial shelf_life should be 5 days');
        $this->assertEquals(7, $ingredient2->shelf_life, 'Ingredient B initial shelf_life should be 7 days');
        $this->assertEquals(15, $ingredient3->shelf_life, 'Ingredient C initial shelf_life should be 15 days');

        // ===== STEP 4: IMPORT EXCEL WITH UPDATED SHELF_LIFE VALUES =====
        $testFile = base_path('tests/Fixtures/update_shelf_life_plated_dish.xlsx');
        $this->assertFileExists($testFile, 'Update test Excel file should exist at ' . $testFile);

        $importProcess = $this->runImport($testFile);

        // ===== STEP 5: REFRESH MODELS FROM DATABASE =====
        $product->refresh();
        $platedDish->refresh();
        $ingredient1->refresh();
        $ingredient2->refresh();
        $ingredient3->refresh();

        // ===== STEP 6: VERIFY PLATED DISH STILL EXISTS (NO DUPLICATION) =====
        $this->assertEquals(
            1,
            PlatedDish::where('product_id', $product->id)->count(),
            'Should still have exactly 1 PlatedDish record (no duplication)'
        );

        // ===== STEP 7: VERIFY STILL HAVE 3 INGREDIENTS (NO DUPLICATION) =====
        $this->assertEquals(
            3,
            $platedDish->ingredients->count(),
            'Should still have exactly 3 ingredients (no duplication)'
        );

        // ===== STEP 8: VERIFY SHELF_LIFE VALUES WERE UPDATED =====
        $updatedIngredients = $platedDish->ingredients->keyBy('ingredient_name');

        // Ingredient A: shelf_life should be updated from 5 to 10
        $updatedIngredientA = $updatedIngredients['INGREDIENT_A'];
        $this->assertNotNull($updatedIngredientA, 'Ingredient A should exist');
        $this->assertEquals(
            10,
            $updatedIngredientA->shelf_life,
            'Ingredient A shelf_life should be UPDATED from 5 to 10 days'
        );

        // Ingredient B: shelf_life should be updated from 7 to 14
        $updatedIngredientB = $updatedIngredients['INGREDIENT_B'];
        $this->assertNotNull($updatedIngredientB, 'Ingredient B should exist');
        $this->assertEquals(
            14,
            $updatedIngredientB->shelf_life,
            'Ingredient B shelf_life should be UPDATED from 7 to 14 days'
        );

        // Ingredient C: shelf_life should be updated from 15 to 30
        $updatedIngredientC = $updatedIngredients['INGREDIENT_C'];
        $this->assertNotNull($updatedIngredientC, 'Ingredient C should exist');
        $this->assertEquals(
            30,
            $updatedIngredientC->shelf_life,
            'Ingredient C shelf_life should be UPDATED from 15 to 30 days'
        );

        // ===== STEP 9: VERIFY OTHER FIELDS REMAINED UNCHANGED =====
        // Ingredient A
        $this->assertEquals('GR', $updatedIngredientA->measure_unit, 'Ingredient A measure_unit should remain GR');
        $this->assertEquals(200, $updatedIngredientA->quantity, 'Ingredient A quantity should remain 200');
        $this->assertEquals(250, $updatedIngredientA->max_quantity_horeca, 'Ingredient A max_quantity should remain 250');
        $this->assertEquals(0, $updatedIngredientA->order_index, 'Ingredient A order_index should remain 0');

        // Ingredient B
        $this->assertEquals('ML', $updatedIngredientB->measure_unit, 'Ingredient B measure_unit should remain ML');
        $this->assertEquals(100, $updatedIngredientB->quantity, 'Ingredient B quantity should remain 100');
        $this->assertEquals(150, $updatedIngredientB->max_quantity_horeca, 'Ingredient B max_quantity should remain 150');
        $this->assertEquals(1, $updatedIngredientB->order_index, 'Ingredient B order_index should remain 1');

        // Ingredient C
        $this->assertEquals('UND', $updatedIngredientC->measure_unit, 'Ingredient C measure_unit should remain UND');
        $this->assertEquals(3, $updatedIngredientC->quantity, 'Ingredient C quantity should remain 3');
        $this->assertEquals(5, $updatedIngredientC->max_quantity_horeca, 'Ingredient C max_quantity should remain 5');
        $this->assertEquals(2, $updatedIngredientC->order_index, 'Ingredient C order_index should remain 2');

        // ===== STEP 10: VERIFY IMPORT WAS SUCCESSFUL =====
        $this->assertTrue(
            app(ImportService::class)->wasSuccessful($importProcess),
            'Import should complete successfully'
        );
    }
}