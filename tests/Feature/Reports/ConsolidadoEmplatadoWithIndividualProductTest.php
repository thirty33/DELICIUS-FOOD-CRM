<?php

namespace Tests\Feature\Reports;

use App\Exports\ConsolidadoEmplatadoDataExport;
use App\Repositories\ConsolidadoEmplatadoRepository;
use App\Repositories\OrderRepository;
use App\Support\ImportExport\ConsolidadoEmplatadoSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Helpers\ReportGrouperTestHelper;
use Tests\Helpers\TestDataFactory;
use Tests\TestCase;

/**
 * Test Consolidado Emplatado Report with HORECA and INDIVIDUAL Products
 *
 * SCENARIO:
 * - HORECA Product: PCH - HORECA SANDWICH (is_horeca = true)
 * - INDIVIDUAL Product: PPC - INDIVIDUAL SANDWICH (is_horeca = false)
 * - Both products related via related_product_id
 * - HORECA has 2 ingredients: PAN (50g/PAX), JAMON (30g/PAX)
 * - 2 Branches: BRANCH A (10 HORECA), BRANCH B (5 HORECA)
 * - INDIVIDUAL: BRANCH A (8), BRANCH B (12)
 * - Expected TOTAL HORECA: 15
 * - Expected TOTAL INDIVIDUAL: 20
 */
class ConsolidadoEmplatadoWithIndividualProductTest extends TestCase
{
    use RefreshDatabase;
    use ReportGrouperTestHelper;

    public function test_horeca_with_individual_product_shows_combined_name_and_totals(): void
    {
        // Reset factory state
        TestDataFactory::reset();

        // ===============================================================
        // 1. CREATE COMPANY, BRANCHES, USERS
        // ===============================================================
        $company = TestDataFactory::createCompany();
        $branchA = TestDataFactory::createBranch($company, 'BRANCH A HORECA');
        $branchB = TestDataFactory::createBranch($company, 'BRANCH B HORECA');
        $userA = TestDataFactory::createUser($company, $branchA, 'USERA');
        $userB = TestDataFactory::createUser($company, $branchB, 'USERB');

        // ===============================================================
        // 2. CREATE CATEGORIES AND INGREDIENTS
        // ===============================================================
        $categoryHoreca = TestDataFactory::createCategory('SANDWICHES HORECA');
        $categoryIndividual = TestDataFactory::createCategory('SANDWICHES INDIVIDUAL');
        $categoryIngredients = TestDataFactory::createCategory('INGREDIENTES');

        $ingredientPan = TestDataFactory::createProduct($categoryIngredients, 'PAN MARRAQUETA', 'ING001', [
            'measure_unit' => 'GR',
        ]);

        $ingredientJamon = TestDataFactory::createProduct($categoryIngredients, 'JAMON', 'ING002', [
            'measure_unit' => 'GR',
        ]);

        // ===============================================================
        // 3. CREATE INDIVIDUAL PRODUCT (created first, then linked)
        // ===============================================================
        $individualProduct = TestDataFactory::createProduct($categoryIndividual, 'PPC - INDIVIDUAL SANDWICH', 'PPC00001', [
            'price' => 3000,
        ]);

        TestDataFactory::createPlatedDish($individualProduct, false, null);

        // ===============================================================
        // 4. CREATE HORECA PRODUCT WITH RELATION
        // ===============================================================
        $horecaProduct = TestDataFactory::createProduct($categoryHoreca, 'PCH - HORECA SANDWICH', 'PCH00001', [
            'price' => 5000,
        ]);

        $horecaPlatedDish = TestDataFactory::createPlatedDish($horecaProduct, true, $individualProduct);

        // Add ingredients to HORECA
        TestDataFactory::addIngredient($horecaPlatedDish, $ingredientPan, 50, 'GR', 1000, 1);
        TestDataFactory::addIngredient($horecaPlatedDish, $ingredientJamon, 30, 'GR', 1000, 2);

        // Get production area
        $productionArea = TestDataFactory::createProductionArea();

        // ===============================================================
        // 5. CREATE ORDERS (PROCESSED status) - HORECA + INDIVIDUAL
        // ===============================================================
        $deliveryDate = now()->addDays(3)->format('Y-m-d');

        // HORECA orders
        $orderA = TestDataFactory::createHorecaOrder($userA, $branchA, $horecaProduct, 10, $deliveryDate);
        $orderB = TestDataFactory::createHorecaOrder($userB, $branchB, $horecaProduct, 5, $deliveryDate);

        // INDIVIDUAL orders (also PROCESSED status to be included in advance order)
        $orderIndivA = TestDataFactory::createHorecaOrder($userA, $branchA, $individualProduct, 8, $deliveryDate);
        $orderIndivB = TestDataFactory::createHorecaOrder($userB, $branchB, $individualProduct, 12, $deliveryDate);

        // ===============================================================
        // 6. CREATE REPORT GROUPERS (by branch, not company, to keep separate columns)
        // ===============================================================
        $this->createGroupersByBranchName([
            ['name' => 'BRANCH A HORECA', 'branch_id' => $branchA->id],
            ['name' => 'BRANCH B HORECA', 'branch_id' => $branchB->id],
        ]);

        // ===============================================================
        // 7. CREATE ADVANCE ORDER FROM ALL ORDERS (using repository)
        // ===============================================================
        $orderRepository = new OrderRepository();
        $advanceOrder = $orderRepository->createAdvanceOrderFromOrders(
            [$orderA->id, $orderB->id, $orderIndivA->id, $orderIndivB->id],
            now()->format('Y-m-d H:i:s'),
            [$productionArea->id]
        );

        // ===============================================================
        // 8. EXECUTE REPOSITORY - NESTED FORMAT
        // ===============================================================
        $repository = app(ConsolidadoEmplatadoRepository::class);
        $nestedData = $repository->getConsolidatedPlatedDishData([$advanceOrder->id], false);

        // ===============================================================
        // 8. ASSERTIONS - NESTED FORMAT
        // ===============================================================
        dump('Product groups count: ' . count($nestedData));
        foreach ($nestedData as $idx => $group) {
            dump("Group {$idx}: Product ID {$group['product_id']}, Name: {$group['product_name']}, HORECA: {$group['total_horeca']}, INDIVIDUAL: {$group['total_individual']}");
        }

        $this->assertCount(1, $nestedData, 'Should have 1 product group');

        $productGroup = $nestedData[0];

        // Product name should combine HORECA + INDIVIDUAL
        $expectedProductName = "PCH - HORECA SANDWICH\nPPC - INDIVIDUAL SANDWICH";
        $this->assertEquals($expectedProductName, $productGroup['product_name']);

        // Total HORECA: 10 + 5 = 15
        $this->assertEquals(15, $productGroup['total_horeca']);

        // Total INDIVIDUAL: 8 + 12 = 20
        $this->assertEquals(20, $productGroup['total_individual']);

        // Should have 2 ingredients
        $this->assertCount(2, $productGroup['ingredients']);

        // ===============================================================
        // 9. VALIDATE FIRST INGREDIENT (PAN)
        // ===============================================================
        $ingredient1 = $productGroup['ingredients'][0];

        $this->assertEquals('PAN MARRAQUETA', $ingredient1['ingredient_name']);
        $this->assertEquals(50, $ingredient1['quantity_per_pax']);
        $this->assertEquals('GR', $ingredient1['measure_unit']);
        $this->assertEquals(20, $ingredient1['individual']);
        $this->assertEquals(15, $ingredient1['total_horeca']);

        // Check clients
        $this->assertCount(2, $ingredient1['clientes']);

        $clientA = collect($ingredient1['clientes'])->firstWhere('column_name', 'BRANCH A HORECA');
        $this->assertNotNull($clientA);
        $this->assertEquals(10, $clientA['porciones']);
        $this->assertEquals(500, $clientA['gramos']); // 10 × 50 = 500

        $clientB = collect($ingredient1['clientes'])->firstWhere('column_name', 'BRANCH B HORECA');
        $this->assertNotNull($clientB);
        $this->assertEquals(5, $clientB['porciones']);
        $this->assertEquals(250, $clientB['gramos']); // 5 × 50 = 250

        // ===============================================================
        // 10. VALIDATE SECOND INGREDIENT (JAMON)
        // ===============================================================
        $ingredient2 = $productGroup['ingredients'][1];

        $this->assertEquals('JAMON', $ingredient2['ingredient_name']);
        $this->assertEquals(30, $ingredient2['quantity_per_pax']);
        $this->assertEquals('GR', $ingredient2['measure_unit']);
        $this->assertEquals(20, $ingredient2['individual']);
        $this->assertEquals(15, $ingredient2['total_horeca']);

        // ===============================================================
        // 11. TEST FLAT FORMAT
        // ===============================================================
        ConsolidadoEmplatadoSchema::resetClientColumns();
        $flatData = $repository->getConsolidatedPlatedDishData([$advanceOrder->id], true);

        $this->assertCount(3, $flatData, 'Flat data should have 2 ingredient rows + 1 totals row');

        // Get schema headers
        $headers = ConsolidadoEmplatadoSchema::getHeaders();
        $headerKeys = array_keys($headers);

        // Verify INDIVIDUAL column exists in schema
        $this->assertContains('individual', $headerKeys);

        // Validate first row
        $row1 = $flatData[0];
        $this->assertEquals($expectedProductName, $row1['plato']);
        $this->assertEquals('PAN MARRAQUETA', $row1['ingrediente']);
        $this->assertEquals('50 GRAMOS', $row1['cantidad_x_pax']);
        $this->assertEquals('20', $row1['individual']); // String value
        $this->assertEquals('15', $row1['total_horeca']);

        // Validate second row
        $row2 = $flatData[1];
        $this->assertEquals('', $row2['plato']); // Empty for merged cell effect
        $this->assertEquals('JAMON', $row2['ingrediente']);
        $this->assertEquals('30 GRAMOS', $row2['cantidad_x_pax']);
        $this->assertEquals('', $row2['individual']); // Empty for merged cell effect
        $this->assertEquals('', $row2['total_horeca']); // Empty for merged cell effect

        // ===============================================================
        // 12. TEST EXCEL EXPORT USING ExportService
        // ===============================================================

        // Reset schema again before export
        ConsolidadoEmplatadoSchema::resetClientColumns();

        // Use ExportService to handle export
        $exportService = app(\App\Services\ExportService::class);

        // Ensure test-exports directory exists
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        // Generate filename
        $fileName = 'test-exports/consolidado-emplatado-horeca-individual-' . now()->format('Y-m-d-His') . '.xlsx';

        // Extract branch names for export
        $branchNames = $repository->getBranchNamesFromAdvanceOrders([$advanceOrder->id]);

        // Execute export via ExportService (this will create ExportProcess)
        $result = $exportService->exportRaw(
            \App\Exports\ConsolidadoEmplatadoDataExport::class,
            collect([$advanceOrder->id]),
            \App\Models\ExportProcess::TYPE_CONSOLIDADO_EMPLATADO,
            [$branchNames] // Pass branch names as additional argument
        );

        // VALIDATE EXPORT PROCESS
        $exportProcess = $result['exportProcess'];
        $this->assertNotNull($exportProcess, 'ExportProcess should be created');
        $this->assertEquals(\App\Models\ExportProcess::TYPE_CONSOLIDADO_EMPLATADO, $exportProcess->type, 'Export type should be consolidado emplatado');
        $this->assertEquals(\App\Models\ExportProcess::STATUS_PROCESSED, $exportProcess->status, 'Export should be processed');

        // Write content to file for validation
        $filePath = storage_path('app/' . $fileName);
        file_put_contents($filePath, $result['content']);
        $this->assertFileExists($filePath, "Excel file should be created at {$filePath}");

        // Load Excel file using PhpSpreadsheet
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify title (row 1)
        $this->assertEquals('CONSOLIDADO DE INGREDIENTES - EMPLATADO', $sheet->getCellByColumnAndRow(1, 1)->getValue());

        // Verify date row (row 2)
        $dateValue = $sheet->getCellByColumnAndRow(1, 2)->getValue();
        $this->assertStringContainsString('FECHA:', $dateValue);

        // Verify headers (row 3)
        $expectedHeaders = array_values(ConsolidadoEmplatadoSchema::getHeaders());
        $actualHeaders = [];
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $headerValue = $sheet->getCellByColumnAndRow($col, 3)->getValue();
            if ($headerValue) {
                $actualHeaders[] = $headerValue;
            }
        }

        $this->assertEquals($expectedHeaders, $actualHeaders, 'Excel headers should match schema headers');

        // Verify INDIVIDUAL column exists in headers
        $this->assertContains('INDIVIDUAL', $actualHeaders, 'Excel should have INDIVIDUAL column');

        // Verify row count (1 title + 1 date + 1 header + 2 ingredient rows + 1 totals row)
        $lastRow = $sheet->getHighestRow();
        $this->assertEquals(6, $lastRow, 'Should have 1 title + 1 date + 1 header + 2 ingredient rows + 1 totals row');

        // Verify data row 4 (Ingredient 1: PAN)
        $this->assertEquals($expectedProductName, $sheet->getCellByColumnAndRow(1, 4)->getValue());
        $this->assertEquals('PAN MARRAQUETA', $sheet->getCellByColumnAndRow(2, 4)->getValue());
        $this->assertEquals('50 GRAMOS', $sheet->getCellByColumnAndRow(3, 4)->getValue());
        $this->assertEquals('20', $sheet->getCellByColumnAndRow(4, 4)->getValue()); // INDIVIDUAL column

        // Verify data row 5 (Ingredient 2: JAMON) - plato and individual should be empty for merged cell effect
        $this->assertEquals('', $sheet->getCellByColumnAndRow(1, 5)->getValue());
        $this->assertEquals('JAMON', $sheet->getCellByColumnAndRow(2, 5)->getValue());
        $this->assertEquals('30 GRAMOS', $sheet->getCellByColumnAndRow(3, 5)->getValue());
        $this->assertEquals('', $sheet->getCellByColumnAndRow(4, 5)->getValue()); // INDIVIDUAL column - empty for merged cell effect

        // ===============================================================
        // 13. SUMMARY OUTPUT
        // ===============================================================
        echo "\n\n";
        echo "====================================================================\n";
        echo "✅ TEST PASSED: HORECA + INDIVIDUAL Integration\n";
        echo "====================================================================\n";
        echo "Product: {$productGroup['product_name']}\n";
        echo "Total HORECA: {$productGroup['total_horeca']}\n";
        echo "Total INDIVIDUAL: {$productGroup['total_individual']}\n";
        echo "Ingredients: " . count($productGroup['ingredients']) . "\n";
        echo "====================================================================\n";
        echo "✅ EXCEL FILE GENERATED SUCCESSFULLY\n";
        echo "====================================================================\n";
        echo "Absolute Path: {$filePath}\n";
        echo "====================================================================\n";
        echo "\n\n";
    }

    /**
     * Test Mixed Scenario: Related Products + Unrelated Products
     *
     * SCENARIO:
     * 1) 2 RELATED PRODUCTS:
     *    - HORECA BURGER (is_horeca = true, related to INDIVIDUAL BURGER)
     *    - INDIVIDUAL BURGER (is_horeca = false)
     *    - Orders: HORECA (12 units), INDIVIDUAL (18 units)
     *
     * 2) INDIVIDUAL PRODUCT WITHOUT RELATION:
     *    - INDIVIDUAL SALAD (is_horeca = false, no relation)
     *    - Orders: 25 units
     *
     * 3) HORECA PRODUCT WITHOUT RELATION:
     *    - HORECA PASTA (is_horeca = true, no relation)
     *    - Orders: 30 units
     *
     * EXPECTED RESULT:
     * - Group 1: HORECA BURGER + INDIVIDUAL BURGER (combined)
     *   - total_horeca = 12, total_individual = 18
     * - Group 2: INDIVIDUAL SALAD (standalone)
     *   - total_horeca = 0, total_individual = 25
     * - Group 3: HORECA PASTA (standalone)
     *   - total_horeca = 30, total_individual = 0
     */
    public function test_mixed_scenario_related_and_unrelated_products(): void
    {
        // Reset factory state
        TestDataFactory::reset();

        // ===============================================================
        // 1. CREATE COMPANY, BRANCHES, USERS
        // ===============================================================
        $company = TestDataFactory::createCompany();
        $branchA = TestDataFactory::createBranch($company, 'BRANCH A MIXED');
        $branchB = TestDataFactory::createBranch($company, 'BRANCH B MIXED');
        $userA = TestDataFactory::createUser($company, $branchA, 'USERA');
        $userB = TestDataFactory::createUser($company, $branchB, 'USERB');

        // ===============================================================
        // 2. CREATE CATEGORIES AND INGREDIENTS
        // ===============================================================
        $categoryHoreca = TestDataFactory::createCategory('HORECA MEALS');
        $categoryIndividual = TestDataFactory::createCategory('INDIVIDUAL MEALS');
        $categoryIngredients = TestDataFactory::createCategory('INGREDIENTES');

        // Ingredients for HORECA BURGER
        $ingredientBun = TestDataFactory::createProduct($categoryIngredients, 'PAN HAMBURGUESA', 'ING001', [
            'measure_unit' => 'GR',
        ]);
        $ingredientMeat = TestDataFactory::createProduct($categoryIngredients, 'CARNE MOLIDA', 'ING002', [
            'measure_unit' => 'GR',
        ]);

        // Ingredients for INDIVIDUAL SALAD
        $ingredientLettuce = TestDataFactory::createProduct($categoryIngredients, 'LECHUGA', 'ING003', [
            'measure_unit' => 'GR',
        ]);

        // Ingredients for HORECA PASTA
        $ingredientPasta = TestDataFactory::createProduct($categoryIngredients, 'PASTA', 'ING004', [
            'measure_unit' => 'GR',
        ]);

        // ===============================================================
        // 3. SCENARIO 1: CREATE RELATED PRODUCTS (HORECA + INDIVIDUAL)
        // ===============================================================

        // Create INDIVIDUAL BURGER first (will be linked)
        $individualBurger = TestDataFactory::createProduct($categoryIndividual, 'INDIVIDUAL BURGER', 'IND001', [
            'price' => 4000,
        ]);
        TestDataFactory::createPlatedDish($individualBurger, false, null);

        // Create HORECA BURGER with relation
        $horecaBurger = TestDataFactory::createProduct($categoryHoreca, 'HORECA BURGER', 'HOR001', [
            'price' => 6000,
        ]);
        $horecaBurgerDish = TestDataFactory::createPlatedDish($horecaBurger, true, $individualBurger);
        TestDataFactory::addIngredient($horecaBurgerDish, $ingredientBun, 80, 'GR', 1000, 1);
        TestDataFactory::addIngredient($horecaBurgerDish, $ingredientMeat, 150, 'GR', 1000, 2);

        // ===============================================================
        // 4. SCENARIO 2: CREATE INDIVIDUAL PRODUCT WITHOUT RELATION
        // ===============================================================
        $individualSalad = TestDataFactory::createProduct($categoryIndividual, 'INDIVIDUAL SALAD', 'IND002', [
            'price' => 3500,
        ]);
        $individualSaladDish = TestDataFactory::createPlatedDish($individualSalad, false, null);
        TestDataFactory::addIngredient($individualSaladDish, $ingredientLettuce, 100, 'GR', 1000, 1);

        // ===============================================================
        // 5. SCENARIO 3: CREATE HORECA PRODUCT WITHOUT RELATION
        // ===============================================================
        $horecaPasta = TestDataFactory::createProduct($categoryHoreca, 'HORECA PASTA', 'HOR002', [
            'price' => 5500,
        ]);
        $horecaPastaDish = TestDataFactory::createPlatedDish($horecaPasta, true, null);
        TestDataFactory::addIngredient($horecaPastaDish, $ingredientPasta, 200, 'GR', 1000, 1);

        // Get production area
        $productionArea = TestDataFactory::createProductionArea();

        // ===============================================================
        // 6. CREATE ORDERS (PROCESSED status)
        // ===============================================================
        $deliveryDate = now()->addDays(3)->format('Y-m-d');

        // SCENARIO 1: Related products orders
        $orderHorecaBurgerA = TestDataFactory::createHorecaOrder($userA, $branchA, $horecaBurger, 7, $deliveryDate);
        $orderHorecaBurgerB = TestDataFactory::createHorecaOrder($userB, $branchB, $horecaBurger, 5, $deliveryDate);
        $orderIndivBurgerA = TestDataFactory::createHorecaOrder($userA, $branchA, $individualBurger, 10, $deliveryDate);
        $orderIndivBurgerB = TestDataFactory::createHorecaOrder($userB, $branchB, $individualBurger, 8, $deliveryDate);

        // SCENARIO 2: Individual product without relation
        $orderIndivSaladA = TestDataFactory::createHorecaOrder($userA, $branchA, $individualSalad, 15, $deliveryDate);
        $orderIndivSaladB = TestDataFactory::createHorecaOrder($userB, $branchB, $individualSalad, 10, $deliveryDate);

        // SCENARIO 3: Horeca product without relation
        $orderHorecaPastaA = TestDataFactory::createHorecaOrder($userA, $branchA, $horecaPasta, 18, $deliveryDate);
        $orderHorecaPastaB = TestDataFactory::createHorecaOrder($userB, $branchB, $horecaPasta, 12, $deliveryDate);

        // ===============================================================
        // 7. CREATE REPORT GROUPERS (by branch, not company, to keep separate columns)
        // ===============================================================
        $this->createGroupersByBranchName([
            ['name' => 'BRANCH A MIXED', 'branch_id' => $branchA->id],
            ['name' => 'BRANCH B MIXED', 'branch_id' => $branchB->id],
        ]);

        // ===============================================================
        // 8. CREATE ADVANCE ORDER FROM ALL ORDERS
        // ===============================================================
        $orderRepository = new OrderRepository();
        $advanceOrder = $orderRepository->createAdvanceOrderFromOrders(
            [
                $orderHorecaBurgerA->id,
                $orderHorecaBurgerB->id,
                $orderIndivBurgerA->id,
                $orderIndivBurgerB->id,
                $orderIndivSaladA->id,
                $orderIndivSaladB->id,
                $orderHorecaPastaA->id,
                $orderHorecaPastaB->id,
            ],
            now()->format('Y-m-d H:i:s'),
            [$productionArea->id]
        );

        // ===============================================================
        // 9. EXECUTE REPOSITORY - NESTED FORMAT
        // ===============================================================
        $repository = app(ConsolidadoEmplatadoRepository::class);
        $nestedData = $repository->getConsolidatedPlatedDishData([$advanceOrder->id], false);

        // ===============================================================
        // 9. ASSERTIONS - NESTED FORMAT
        // ===============================================================
        dump('=== MIXED SCENARIO TEST ===');
        dump('Product groups count: ' . count($nestedData));
        foreach ($nestedData as $idx => $group) {
            dump("Group {$idx}: Product ID {$group['product_id']}, Name: {$group['product_name']}, HORECA: {$group['total_horeca']}, INDIVIDUAL: {$group['total_individual']}");
        }

        // Should have 3 product groups
        $this->assertCount(3, $nestedData, 'Should have 3 product groups');

        // ===============================================================
        // 10. VALIDATE GROUP 1: RELATED PRODUCTS (HORECA + INDIVIDUAL BURGER)
        // ===============================================================
        $group1 = collect($nestedData)->firstWhere('product_id', $horecaBurger->id);
        $this->assertNotNull($group1, 'HORECA BURGER group should exist');

        $expectedBurgerName = "HORECA BURGER\nINDIVIDUAL BURGER";
        $this->assertEquals($expectedBurgerName, $group1['product_name']);
        $this->assertEquals(12, $group1['total_horeca'], 'HORECA BURGER: 7 + 5 = 12');
        $this->assertEquals(18, $group1['total_individual'], 'INDIVIDUAL BURGER: 10 + 8 = 18');
        $this->assertCount(2, $group1['ingredients'], 'HORECA BURGER should have 2 ingredients');

        // Validate ingredients
        $ing1 = $group1['ingredients'][0];
        $this->assertEquals('PAN HAMBURGUESA', $ing1['ingredient_name']);
        $this->assertEquals(80, $ing1['quantity_per_pax']);
        $this->assertEquals(18, $ing1['individual']); // Total individual
        $this->assertEquals(12, $ing1['total_horeca']); // Total horeca

        $ing2 = $group1['ingredients'][1];
        $this->assertEquals('CARNE MOLIDA', $ing2['ingredient_name']);
        $this->assertEquals(150, $ing2['quantity_per_pax']);
        $this->assertEquals(18, $ing2['individual']);
        $this->assertEquals(12, $ing2['total_horeca']);

        // ===============================================================
        // 11. VALIDATE GROUP 2: INDIVIDUAL PRODUCT WITHOUT RELATION (SALAD)
        // ===============================================================
        $group2 = collect($nestedData)->firstWhere('product_id', $individualSalad->id);
        $this->assertNotNull($group2, 'INDIVIDUAL SALAD group should exist');

        $this->assertEquals('INDIVIDUAL SALAD', $group2['product_name']);
        $this->assertEquals(0, $group2['total_horeca'], 'INDIVIDUAL SALAD has no horeca orders');
        $this->assertEquals(25, $group2['total_individual'], 'INDIVIDUAL SALAD: 15 + 10 = 25');
        $this->assertCount(1, $group2['ingredients'], 'INDIVIDUAL SALAD should have 1 ingredient');

        $ing3 = $group2['ingredients'][0];
        $this->assertEquals('LECHUGA', $ing3['ingredient_name']);
        $this->assertEquals(100, $ing3['quantity_per_pax']);
        $this->assertEquals(25, $ing3['individual']);
        $this->assertEquals(0, $ing3['total_horeca']);

        // CRITICAL: INDIVIDUAL products without HORECA relation should NOT have client data
        $this->assertEmpty($ing3['clientes'], 'INDIVIDUAL SALAD should have NO client/branch data');
        $this->assertEmpty($ing3['total_bolsas'], 'INDIVIDUAL SALAD should have NO bag calculations');

        // ===============================================================
        // 12. VALIDATE GROUP 3: HORECA PRODUCT WITHOUT RELATION (PASTA)
        // ===============================================================
        $group3 = collect($nestedData)->firstWhere('product_id', $horecaPasta->id);
        $this->assertNotNull($group3, 'HORECA PASTA group should exist');

        $this->assertEquals('HORECA PASTA', $group3['product_name']);
        $this->assertEquals(30, $group3['total_horeca'], 'HORECA PASTA: 18 + 12 = 30');
        $this->assertEquals(0, $group3['total_individual'], 'HORECA PASTA has no individual orders');
        $this->assertCount(1, $group3['ingredients'], 'HORECA PASTA should have 1 ingredient');

        $ing4 = $group3['ingredients'][0];
        $this->assertEquals('PASTA', $ing4['ingredient_name']);
        $this->assertEquals(200, $ing4['quantity_per_pax']);
        $this->assertEquals(0, $ing4['individual']);
        $this->assertEquals(30, $ing4['total_horeca']);

        // ===============================================================
        // 13. TEST EXCEL EXPORT USING ExportService (TDD Red Phase)
        // ===============================================================
        ConsolidadoEmplatadoSchema::resetClientColumns();

        // Use ExportService to handle export
        $exportService = app(\App\Services\ExportService::class);

        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $fileName = 'test-exports/consolidado-emplatado-mixed-scenario-' . now()->format('Y-m-d-His') . '.xlsx';

        // Extract branch names for export
        $branchNames = $repository->getBranchNamesFromAdvanceOrders([$advanceOrder->id]);

        // Execute export via ExportService (this will create ExportProcess)
        $result = $exportService->exportRaw(
            \App\Exports\ConsolidadoEmplatadoDataExport::class,
            collect([$advanceOrder->id]),
            \App\Models\ExportProcess::TYPE_CONSOLIDADO_EMPLATADO,
            [$branchNames] // Pass branch names as additional argument
        );

        // VALIDATE EXPORT PROCESS
        $exportProcess = $result['exportProcess'];
        $this->assertNotNull($exportProcess, 'ExportProcess should be created');
        $this->assertEquals(\App\Models\ExportProcess::TYPE_CONSOLIDADO_EMPLATADO, $exportProcess->type, 'Export type should be consolidado emplatado');
        $this->assertEquals(\App\Models\ExportProcess::STATUS_PROCESSED, $exportProcess->status, 'Export should be processed');

        // Write content to file for validation
        $filePath = storage_path('app/' . $fileName);
        file_put_contents($filePath, $result['content']);
        $this->assertFileExists($filePath, "Excel file should be created at {$filePath}");

        // Load and validate Excel
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Verify title (row 1)
        $this->assertEquals('CONSOLIDADO DE INGREDIENTES - EMPLATADO', $sheet->getCellByColumnAndRow(1, 1)->getValue());

        // Verify date row (row 2)
        $dateValue = $sheet->getCellByColumnAndRow(1, 2)->getValue();
        $this->assertStringContainsString('FECHA:', $dateValue);

        // Verify headers (row 3)
        $expectedHeaders = array_values(ConsolidadoEmplatadoSchema::getHeaders());
        $this->assertContains('INDIVIDUAL', $expectedHeaders);

        // Verify row count: 1 title + 1 date + 1 header + 4 ingredient rows + 1 totals row
        $lastRow = $sheet->getHighestRow();
        $this->assertEquals(8, $lastRow, 'Should have 1 title + 1 date + 1 header + 4 ingredient rows + 1 totals row');

        // Verify merged cells in PLATO column (adjusted for title and date rows)
        $mergedCells = $sheet->getMergeCells();
        $this->assertNotEmpty($mergedCells, 'Should have merged cells for product grouping');
        $this->assertContains('A4:A5', $mergedCells, 'HORECA BURGER rows should be merged (2 ingredients, rows 4-5)');

        // ===============================================================
        // 14. SUMMARY OUTPUT
        // ===============================================================
        echo "\n\n";
        echo "====================================================================\n";
        echo "✅ TEST PASSED: MIXED SCENARIO (Related + Unrelated Products)\n";
        echo "====================================================================\n";
        echo "Group 1 (Related): {$group1['product_name']}\n";
        echo "  - HORECA: {$group1['total_horeca']}, INDIVIDUAL: {$group1['total_individual']}\n";
        echo "Group 2 (Individual Only): {$group2['product_name']}\n";
        echo "  - HORECA: {$group2['total_horeca']}, INDIVIDUAL: {$group2['total_individual']}\n";
        echo "Group 3 (Horeca Only): {$group3['product_name']}\n";
        echo "  - HORECA: {$group3['total_horeca']}, INDIVIDUAL: {$group3['total_individual']}\n";
        echo "====================================================================\n";
        echo "✅ EXCEL FILE GENERATED SUCCESSFULLY\n";
        echo "====================================================================\n";
        echo "Absolute Path: {$filePath}\n";
        echo "====================================================================\n";
        echo "\n\n";
    }
}