<?php

namespace Tests\Feature\Exports;

use App\Exports\PlatedDishIngredientsDataExport;
use App\Models\Category;
use App\Models\ExportProcess;
use App\Models\PlatedDish;
use App\Models\PlatedDishIngredient;
use App\Models\Product;
use App\Support\ImportExport\PlatedDishIngredientsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * PlatedDishIngredientsDataExport Test - Validates Export Compatibility with Import
 *
 * This test validates that the export format matches EXACTLY what the
 * PlatedDishIngredientsImport class expects, ensuring round-trip compatibility.
 *
 * VERTICAL FORMAT:
 * - Each row represents ONE ingredient for a product
 * - Same product can have multiple rows (multiple ingredients)
 * - Products with 6 ingredients = 6 rows in Excel
 *
 * Test validates:
 * 1. File is generated successfully
 * 2. Headers match EXACTLY what PlatedDishIngredientsImport expects (6 headers)
 * 3. Excel data matches database data exactly
 * 4. Each product with 6 ingredients appears as 6 rows
 * 5. All ingredient details are exported correctly
 *
 * TDD RED PHASE:
 * This test will FAIL because PlatedDishIngredientsDataExport class does not exist yet.
 */
class PlatedDishIngredientsDataExportTest extends TestCase
{
    use RefreshDatabase;

    protected Category $category;
    protected array $products = [];
    protected array $platedDishes = [];
    protected array $ingredientProducts = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create category
        $this->category = Category::create([
            'name' => 'TEST PLATED DISHES',
            'code' => 'TPD',
            'active' => true,
        ]);

        // Note: Ingredients are now stored as text names, not product references
        // We'll use ingredient codes like ING001, ING002, etc.

        // Create 5 plated dish products, each with 6 ingredients
        for ($p = 1; $p <= 5; $p++) {
            // Create product
            $product = Product::create([
                'code' => 'PLATED-EXPORT-' . str_pad($p, 3, '0', STR_PAD_LEFT),
                'name' => "Plated Dish {$p} for Export",
                'description' => "Test plated dish {$p}",
                'price' => 10000 * $p,
                'category_id' => $this->category->id,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
                'active' => true,
            ]);

            $this->products[$p] = $product;

            // Create PlatedDish
            $platedDish = PlatedDish::create([
                'product_id' => $product->id,
                'is_active' => true,
            ]);

            $this->platedDishes[$p] = $platedDish;

            // Create 6 ingredients for this plated dish
            for ($i = 1; $i <= 6; $i++) {
                PlatedDishIngredient::create([
                    'plated_dish_id' => $platedDish->id,
                    'ingredient_name' => 'ING-EXPORT-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                    'measure_unit' => $this->getMeasureUnitForIngredient($i),
                    'quantity' => 10 * $i + ($p * 0.5),
                    'max_quantity_horeca' => 15 * $i + ($p * 0.75),
                    'order_index' => $i - 1,
                    'is_optional' => false,
                    'shelf_life' => $this->getShelfLifeForIngredient($i),
                ]);
            }
        }
    }

    /**
     * Get measure unit for ingredient (varies by ingredient number)
     */
    protected function getMeasureUnitForIngredient(int $ingredientNumber): string
    {
        return match ($ingredientNumber) {
            1 => 'GR',
            2 => 'KG',
            3 => 'ML',
            4 => 'L',
            5 => 'UND',
            6 => 'GR',
            default => 'UND',
        };
    }

    /**
     * Get shelf life for ingredient (varies by ingredient number)
     */
    protected function getShelfLifeForIngredient(int $ingredientNumber): int
    {
        return match ($ingredientNumber) {
            1 => 7,
            2 => 15,
            3 => 30,
            4 => 60,
            5 => 90,
            6 => 180,
            default => 30,
        };
    }

    /**
     * Test that export file is generated successfully
     *
     * TDD RED PHASE: This will FAIL because PlatedDishIngredientsDataExport does not exist
     */
    public function test_export_file_is_generated_successfully_with_correct_headers_and_data(): void
    {
        // Collect all plated dish IDs
        $platedDishIds = collect($this->platedDishes)->pluck('id');

        // Generate export file
        $filePath = $this->generatePlatedDishExport($platedDishIds);

        // ===== 1. VERIFY FILE EXISTS =====
        $this->assertFileExists($filePath, 'Export file should be created');
        $this->assertGreaterThan(0, filesize($filePath), 'Export file should not be empty');

        // ===== 2. VERIFY HEADERS MATCH IMPORT EXPECTATIONS =====
        // Expected headers from PlatedDishIngredientsSchema (centralized)
        $expectedHeaders = PlatedDishIngredientsSchema::getHeaderValues();

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Read headers from row 1
        $actualHeaders = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert headers match EXACTLY
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Export headers MUST match PlatedDishIngredientsImport expectations EXACTLY'
        );

        // Verify we have expected number of headers
        $expectedCount = PlatedDishIngredientsSchema::getHeaderCount();
        $this->assertCount($expectedCount, $actualHeaders, "Should have exactly {$expectedCount} headers");

        // ===== 3. VERIFY ROW COUNT =====
        // We have 5 products, each with 6 ingredients = 30 data rows + 1 header row = 31 total rows
        $lastRow = $sheet->getHighestRow();
        $this->assertEquals(31, $lastRow, 'Should have 31 rows (1 header + 30 data rows for 5 products × 6 ingredients)');

        // ===== 4. VERIFY PRODUCT 1 DATA (6 ROWS) =====
        $product1 = $this->products[1];
        $platedDish1 = $this->platedDishes[1];
        $ingredients1 = $platedDish1->ingredients()->orderBy('order_index')->get();

        // Verify Product 1 has 6 ingredients in database
        $this->assertCount(6, $ingredients1, 'Product 1 should have 6 ingredients in database');

        // Verify each ingredient row in Excel (rows 2-7 are for Product 1)
        for ($i = 0; $i < 6; $i++) {
            $excelRow = $i + 2; // Row 2 = first ingredient, Row 7 = sixth ingredient
            $ingredient = $ingredients1[$i];

            // Column A: CODIGO DE PRODUCTO (same for all 6 rows)
            $this->assertEquals(
                $product1->code,
                $sheet->getCell("A{$excelRow}")->getValue(),
                "Row {$excelRow}: Product code should match"
            );

            // Column B: NOMBRE DE PRODUCTO (same for all 6 rows)
            $this->assertEquals(
                $product1->name,
                $sheet->getCell("B{$excelRow}")->getValue(),
                "Row {$excelRow}: Product name should match"
            );

            // Column C: EMPLATADO (ingredient name - different for each row)
            $this->assertEquals(
                $ingredient->ingredient_name,
                $sheet->getCell("C{$excelRow}")->getValue(),
                "Row {$excelRow}: Ingredient name should match"
            );

            // Column D: UNIDAD DE MEDIDA
            $this->assertEquals(
                $ingredient->measure_unit,
                $sheet->getCell("D{$excelRow}")->getValue(),
                "Row {$excelRow}: Measure unit should match"
            );

            // Column E: CANTIDAD
            $this->assertEquals(
                $ingredient->quantity,
                $sheet->getCell("E{$excelRow}")->getValue(),
                "Row {$excelRow}: Quantity should match"
            );

            // Column F: CANTIDAD MAXIMA (HORECA)
            $this->assertEquals(
                $ingredient->max_quantity_horeca,
                $sheet->getCell("F{$excelRow}")->getValue(),
                "Row {$excelRow}: Max quantity HORECA should match"
            );
        }

        // ===== 5. VERIFY PRODUCT 2 DATA (6 ROWS) =====
        $product2 = $this->products[2];
        $platedDish2 = $this->platedDishes[2];
        $ingredients2 = $platedDish2->ingredients()->orderBy('order_index')->get();

        // Verify Product 2 rows (rows 8-13)
        for ($i = 0; $i < 6; $i++) {
            $excelRow = $i + 8; // Row 8 = first ingredient of Product 2
            $ingredient = $ingredients2[$i];

            // Verify at least product code and ingredient code for Product 2
            $this->assertEquals(
                $product2->code,
                $sheet->getCell("A{$excelRow}")->getValue(),
                "Row {$excelRow}: Product 2 code should match"
            );

            $this->assertEquals(
                $ingredient->ingredient_name,
                $sheet->getCell("C{$excelRow}")->getValue(),
                "Row {$excelRow}: Product 2 ingredient name should match"
            );
        }

        // ===== 6. VERIFY TOTAL DATA ROWS =====
        // Count non-empty rows (should be 30 data rows)
        $dataRowCount = 0;
        for ($row = 2; $row <= $lastRow; $row++) {
            $productCode = $sheet->getCell("A{$row}")->getValue();
            if (!empty($productCode)) {
                $dataRowCount++;
            }
        }

        $this->assertEquals(30, $dataRowCount, 'Should have exactly 30 data rows (5 products × 6 ingredients)');

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Helper: Generate plated dish ingredients export file
     */
    protected function generatePlatedDishExport(Collection $platedDishIds): string
    {
        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Generate filename
        $fileName = 'test-exports/plated-dish-export-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        // Use ExportService to generate raw content
        $exportService = app(\App\Services\ExportService::class);
        $result = $exportService->exportRaw(
            \App\Exports\PlatedDishIngredientsDataExport::class,
            $platedDishIds,
            ExportProcess::TYPE_PLATED_DISH_INGREDIENTS
        );

        // Write to file
        file_put_contents($fullPath, $result['content']);

        $this->assertFileExists($fullPath, "Excel file should be created at {$fullPath}");

        return $fullPath;
    }

    /**
     * Helper: Load Excel file
     */
    protected function loadExcelFile(string $filePath): Spreadsheet
    {
        $this->assertFileExists($filePath, "Excel file should exist at {$filePath}");

        return IOFactory::load($filePath);
    }

    /**
     * Helper: Clean up test file
     */
    protected function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Test that export includes shelf_life field with correct values
     *
     * This test validates that the 7th column "VIDA UTIL" is included
     * in the export and that shelf_life values from database are exported correctly.
     *
     * EXPECTED STRUCTURE:
     * - Headers: 7 columns including "VIDA UTIL" as the last column
     * - Data: Each ingredient row includes shelf_life value in Column G
     * - Values: Match database values exactly (7, 15, 30, 60, 90, 180 days)
     */
    public function test_export_includes_shelf_life_field_with_correct_values(): void
    {
        // Collect all plated dish IDs
        $platedDishIds = collect($this->platedDishes)->pluck('id');

        // Generate export file
        $filePath = $this->generatePlatedDishExport($platedDishIds);

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // ===== 1. VERIFY HEADERS INCLUDE "VIDA UTIL" =====
        $expectedHeaders = PlatedDishIngredientsSchema::getHeaderValues();

        // Read headers from row 1
        $actualHeaders = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert we have expected number of headers
        $expectedCount = PlatedDishIngredientsSchema::getHeaderCount();
        $this->assertCount($expectedCount, $actualHeaders, "Should have exactly {$expectedCount} headers including VIDA UTIL");

        // Assert headers match exactly
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Export headers MUST include VIDA UTIL as 7th column'
        );

        // ===== 2. VERIFY PRODUCT 1 SHELF_LIFE VALUES (6 INGREDIENTS) =====
        $product1 = $this->products[1];
        $platedDish1 = $this->platedDishes[1];
        $ingredients1 = $platedDish1->ingredients()->orderBy('order_index')->get();

        // Expected shelf_life values for Product 1 ingredients
        $expectedShelfLives = [7, 15, 30, 60, 90, 180];

        // Verify each ingredient's shelf_life in Excel (rows 2-7 are for Product 1)
        for ($i = 0; $i < 6; $i++) {
            $excelRow = $i + 2; // Row 2 = first ingredient, Row 7 = sixth ingredient
            $ingredient = $ingredients1[$i];

            // Verify ingredient has shelf_life in database
            $this->assertNotNull(
                $ingredient->shelf_life,
                "Ingredient {$i} should have shelf_life in database"
            );

            // Verify shelf_life matches expected value
            $this->assertEquals(
                $expectedShelfLives[$i],
                $ingredient->shelf_life,
                "Ingredient {$i} should have shelf_life of {$expectedShelfLives[$i]} days in database"
            );

            // Column G: VIDA UTIL
            $excelShelfLife = $sheet->getCell("G{$excelRow}")->getValue();

            $this->assertNotNull(
                $excelShelfLife,
                "Row {$excelRow}: Shelf life should not be null in Excel"
            );

            $this->assertEquals(
                $ingredient->shelf_life,
                $excelShelfLife,
                "Row {$excelRow}: Shelf life in Excel should match database value"
            );

            $this->assertEquals(
                $expectedShelfLives[$i],
                $excelShelfLife,
                "Row {$excelRow}: Shelf life should be {$expectedShelfLives[$i]} days"
            );
        }

        // ===== 3. VERIFY PRODUCT 2 SHELF_LIFE VALUES (6 INGREDIENTS) =====
        $product2 = $this->products[2];
        $platedDish2 = $this->platedDishes[2];
        $ingredients2 = $platedDish2->ingredients()->orderBy('order_index')->get();

        // Verify Product 2 rows (rows 8-13)
        for ($i = 0; $i < 6; $i++) {
            $excelRow = $i + 8; // Row 8 = first ingredient of Product 2
            $ingredient = $ingredients2[$i];

            // Column G: VIDA UTIL
            $excelShelfLife = $sheet->getCell("G{$excelRow}")->getValue();

            $this->assertNotNull(
                $excelShelfLife,
                "Row {$excelRow}: Product 2 shelf life should not be null"
            );

            $this->assertEquals(
                $ingredient->shelf_life,
                $excelShelfLife,
                "Row {$excelRow}: Product 2 shelf life should match database"
            );

            $this->assertEquals(
                $expectedShelfLives[$i],
                $excelShelfLife,
                "Row {$excelRow}: Product 2 ingredient {$i} should have shelf_life of {$expectedShelfLives[$i]} days"
            );
        }

        // ===== 4. VERIFY ALL 30 DATA ROWS HAVE SHELF_LIFE =====
        $lastRow = $sheet->getHighestRow();
        $nullShelfLifeCount = 0;

        for ($row = 2; $row <= $lastRow; $row++) {
            $shelfLife = $sheet->getCell("G{$row}")->getValue();
            if ($shelfLife === null || $shelfLife === '') {
                $nullShelfLifeCount++;
            }
        }

        $this->assertEquals(
            0,
            $nullShelfLifeCount,
            'All 30 data rows should have shelf_life values, none should be null'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }

    /**
     * Test that export includes is_horeca field with correct values
     *
     * This test validates that the 8th column "ES HORECA" is included
     * in the export and that is_horeca values from database are exported correctly.
     *
     * EXPECTED STRUCTURE:
     * - Headers: 8 columns including "ES HORECA" as the last column
     * - Data: Each product row includes is_horeca value in Column H
     * - Values: "VERDADERO" for TRUE, "FALSO" for FALSE
     *
     * IMPORTANT:
     * - is_horeca is a PLATED DISH level field (not ingredient level)
     * - All ingredient rows for the same product MUST have the same is_horeca value
     * - Product with 6 ingredients = 6 rows with same is_horeca value
     */
    public function test_export_includes_is_horeca_field_with_correct_values(): void
    {
        // ===== 1. CREATE TEST DATA WITH DIFFERENT is_horeca VALUES =====

        // Product 1: is_horeca = TRUE (3 ingredients)
        $product1 = Product::create([
            'code' => 'HORECA-TRUE-001',
            'name' => 'HORECA Product (TRUE)',
            'description' => 'Test product with is_horeca = TRUE',
            'price' => 10000,
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);

        $platedDish1 = PlatedDish::create([
            'product_id' => $product1->id,
            'is_active' => true,
            'is_horeca' => true, // TRUE
        ]);

        // Create 3 ingredients for Product 1
        for ($i = 1; $i <= 3; $i++) {
            PlatedDishIngredient::create([
                'plated_dish_id' => $platedDish1->id,
                'ingredient_name' => "HORECA-ING-{$i}",
                'measure_unit' => 'GR',
                'quantity' => 100 * $i,
                'max_quantity_horeca' => 150 * $i,
                'order_index' => $i - 1,
                'is_optional' => false,
                'shelf_life' => 7 * $i,
            ]);
        }

        // Product 2: is_horeca = FALSE (2 ingredients)
        $product2 = Product::create([
            'code' => 'HORECA-FALSE-001',
            'name' => 'Non-HORECA Product (FALSE)',
            'description' => 'Test product with is_horeca = FALSE',
            'price' => 20000,
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);

        $platedDish2 = PlatedDish::create([
            'product_id' => $product2->id,
            'is_active' => true,
            'is_horeca' => false, // FALSE
        ]);

        // Create 2 ingredients for Product 2
        for ($i = 1; $i <= 2; $i++) {
            PlatedDishIngredient::create([
                'plated_dish_id' => $platedDish2->id,
                'ingredient_name' => "REGULAR-ING-{$i}",
                'measure_unit' => 'ML',
                'quantity' => 50 * $i,
                'max_quantity_horeca' => 75 * $i,
                'order_index' => $i - 1,
                'is_optional' => false,
                'shelf_life' => 15 * $i,
            ]);
        }

        // ===== 2. GENERATE EXPORT FILE =====
        $platedDishIds = collect([$platedDish1->id, $platedDish2->id]);
        $filePath = $this->generatePlatedDishExport($platedDishIds);

        // Load Excel file
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // ===== 3. VERIFY HEADERS INCLUDE "ES HORECA" =====
        $expectedHeaders = PlatedDishIngredientsSchema::getHeaderValues();

        // Read headers from row 1
        $actualHeaders = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        // Assert we have 8 headers including ES HORECA
        $expectedCount = PlatedDishIngredientsSchema::getHeaderCount();
        $this->assertCount($expectedCount, $actualHeaders, "Should have exactly {$expectedCount} headers including ES HORECA");

        // Assert headers match exactly
        $this->assertEquals(
            $expectedHeaders,
            $actualHeaders,
            'Export headers MUST include ES HORECA as 8th column'
        );

        // Verify "ES HORECA" is in Column H (8th column)
        $esHorecaHeader = $sheet->getCell('H1')->getValue();
        $this->assertEquals(
            'ES HORECA',
            $esHorecaHeader,
            'Column H should contain "ES HORECA" header'
        );

        // ===== 4. VERIFY PRODUCT 1 IS_HORECA VALUES (3 ROWS - ALL TRUE) =====
        // Rows 2-4 are for Product 1 (3 ingredients)
        for ($row = 2; $row <= 4; $row++) {
            // Column H: ES HORECA
            $excelIsHoreca = $sheet->getCell("H{$row}")->getValue();

            $this->assertNotNull(
                $excelIsHoreca,
                "Row {$row}: is_horeca should not be null in Excel"
            );

            $this->assertEquals(
                'VERDADERO',
                $excelIsHoreca,
                "Row {$row}: Product 1 is_horeca should be VERDADERO (TRUE) in Excel"
            );
        }

        // ===== 5. VERIFY PRODUCT 2 IS_HORECA VALUES (2 ROWS - ALL FALSE) =====
        // Rows 5-6 are for Product 2 (2 ingredients)
        for ($row = 5; $row <= 6; $row++) {
            // Column H: ES HORECA
            $excelIsHoreca = $sheet->getCell("H{$row}")->getValue();

            $this->assertNotNull(
                $excelIsHoreca,
                "Row {$row}: is_horeca should not be null in Excel"
            );

            $this->assertEquals(
                'FALSO',
                $excelIsHoreca,
                "Row {$row}: Product 2 is_horeca should be FALSO (FALSE) in Excel"
            );
        }

        // ===== 6. VERIFY ALL ROWS HAVE IS_HORECA VALUE =====
        $lastRow = $sheet->getHighestRow();
        $nullIsHorecaCount = 0;

        for ($row = 2; $row <= $lastRow; $row++) {
            $isHoreca = $sheet->getCell("H{$row}")->getValue();
            if ($isHoreca === null || $isHoreca === '') {
                $nullIsHorecaCount++;
            }
        }

        $this->assertEquals(
            0,
            $nullIsHorecaCount,
            'All data rows should have is_horeca values, none should be null'
        );

        // ===== 7. VERIFY IS_HORECA VALUES ARE CONSISTENT PER PRODUCT =====
        // Product 1: All 3 rows should have same value (VERDADERO)
        $product1Row1IsHoreca = $sheet->getCell('H2')->getValue();
        $product1Row2IsHoreca = $sheet->getCell('H3')->getValue();
        $product1Row3IsHoreca = $sheet->getCell('H4')->getValue();

        $this->assertEquals(
            $product1Row1IsHoreca,
            $product1Row2IsHoreca,
            'Product 1: All ingredient rows must have same is_horeca value (row 2 vs row 3)'
        );

        $this->assertEquals(
            $product1Row1IsHoreca,
            $product1Row3IsHoreca,
            'Product 1: All ingredient rows must have same is_horeca value (row 2 vs row 4)'
        );

        // Product 2: All 2 rows should have same value (FALSO)
        $product2Row1IsHoreca = $sheet->getCell('H5')->getValue();
        $product2Row2IsHoreca = $sheet->getCell('H6')->getValue();

        $this->assertEquals(
            $product2Row1IsHoreca,
            $product2Row2IsHoreca,
            'Product 2: All ingredient rows must have same is_horeca value'
        );

        // Clean up
        $this->cleanupTestFile($filePath);
    }
}