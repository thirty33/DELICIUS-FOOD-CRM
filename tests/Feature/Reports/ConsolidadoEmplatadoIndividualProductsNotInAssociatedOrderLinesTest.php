<?php

namespace Tests\Feature\Reports;

use App\Repositories\ConsolidadoEmplatadoRepository;
use App\Repositories\OrderRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\TestDataFactory;
use Tests\Helpers\ReportGrouperTestHelper;
use Tests\TestCase;

/**
 * TDD RED PHASE Test - Individual Products NOT in associatedOrderLines Should Be Excluded
 *
 * PRODUCTION BUG REPLICA - OP #243:
 *
 * SCENARIO:
 * This test replicates the exact issue found in production where the Consolidado Emplatado
 * report shows 12 extra units because the repository queries ALL individual products in
 * the orders' date range, NOT just the ones assigned to the Advance Order.
 *
 * EXPECTED BEHAVIOR (Test Assertions):
 * - Only individual products in associatedOrderLines should be counted
 * - Total INDIVIDUAL: 234 units (NOT 246)
 * - Total PLATOS: 531 units (NOT 543)
 *
 * CURRENT BUGGY BEHAVIOR (Will Cause Test to FAIL):
 * - Repository queries ALL order_lines in the order_ids
 * - Includes 12 extra units from individual products NOT in associatedOrderLines
 * - Total INDIVIDUAL: 246 units (WRONG)
 * - Total PLATOS: 543 units (WRONG)
 *
 * THE 12 EXTRA UNITS (distributed across 4 products):
 * - PASTA ALFREDO SUPREMA: +4 units
 * - SPAGHETTI BOLONESA DE LA CASA: +4 units
 * - LASANA GOURMET BOLONESA: +2 units
 * - LASANA FLORENTINA DE POLLO: +2 units
 *
 * CODE LOCATION OF BUG:
 * File: app/Repositories/ConsolidadoEmplatadoRepository.php
 * Lines: 52-64
 * Issue: Uses OrderLine::whereIn('order_id', $orderIds) instead of iterating associatedOrderLines
 *
 * WHEN THIS TEST PASSES:
 * The bug is fixed - repository only counts individual products assigned to the OP
 */
class ConsolidadoEmplatadoIndividualProductsNotInAssociatedOrderLinesTest extends TestCase
{
    use RefreshDatabase;
    use ReportGrouperTestHelper;

    /**
     * Test that individual products NOT in associatedOrderLines are EXCLUDED from totals
     *
     * SETUP:
     * - Create 2 companies with 2 branches each
     * - Create 4 HORECA products with their related INDIVIDUAL products
     * - Create orders with HORECA products → These will be in the OP
     * - Create ADDITIONAL orders with INDIVIDUAL products → These will NOT be in the OP
     * - The additional individual orders are in the SAME order_ids but won't be in associatedOrderLines
     *
     * EXPECTED:
     * - Reporte should count ONLY individual products from associatedOrderLines
     * - Should NOT count the extra individual products from the same orders
     *
     * CURRENT (BUGGY):
     * - Reporte counts ALL individual products from order_ids
     * - Includes extra units causing wrong totals
     */
    public function test_individual_products_not_in_associated_order_lines_should_be_excluded_from_totals(): void
    {
        // Reset factory state
        TestDataFactory::reset();

        // ===============================================================
        // 1. CREATE COMPANIES, BRANCHES, USERS
        // ===============================================================
        $company = TestDataFactory::createCompany();
        $branchA = TestDataFactory::createBranch($company, 'BRANCH A HORECA');
        $branchB = TestDataFactory::createBranch($company, 'BRANCH B HORECA');
        $userA = TestDataFactory::createUser($company, $branchA, 'USERA');
        $userB = TestDataFactory::createUser($company, $branchB, 'USERB');

        // ===============================================================
        // 2. CREATE CATEGORIES
        // ===============================================================
        $categoryHoreca = TestDataFactory::createCategory('PLATOS HORECA');
        $categoryIndividual = TestDataFactory::createCategory('PLATOS INDIVIDUAL');
        $categoryIngredients = TestDataFactory::createCategory('INGREDIENTES');

        // ===============================================================
        // 3. CREATE INGREDIENTS (shared by all plated dishes)
        // ===============================================================
        $ingredientBase = TestDataFactory::createProduct($categoryIngredients, 'BASE INGREDIENT', 'ING001', [
            'measure_unit' => 'GR',
        ]);

        // ===============================================================
        // 4. CREATE 4 HORECA PRODUCTS WITH THEIR RELATED INDIVIDUAL PRODUCTS
        // ===============================================================

        // Product 1: PASTA ALFREDO SUPREMA
        $individualPasta = TestDataFactory::createProduct($categoryIndividual, 'PPCF - PASTA ALFREDO SUPREMA', 'PPCF00000004', [
            'price' => 3500,
        ]);
        TestDataFactory::createPlatedDish($individualPasta, false, null);

        $horecaPasta = TestDataFactory::createProduct($categoryHoreca, 'PCFH - HORECA PASTA ALFREDO SUPREMA', 'PCFH00000004', [
            'price' => 5000,
        ]);
        $platedDishPasta = TestDataFactory::createPlatedDish($horecaPasta, true, $individualPasta);
        TestDataFactory::addIngredient($platedDishPasta, $ingredientBase, 200, 'GR', 1000, 1);

        // Product 2: SPAGHETTI BOLONESA DE LA CASA
        $individualSpaghetti = TestDataFactory::createProduct($categoryIndividual, 'PPCF - SPAGHETTI BOLONESA DE LA CASA', 'PPCF00000007', [
            'price' => 3500,
        ]);
        TestDataFactory::createPlatedDish($individualSpaghetti, false, null);

        $horecaSpaghetti = TestDataFactory::createProduct($categoryHoreca, 'PCFH - HORECA SPAGHETTI BOLONESA DE LA CASA', 'PCFH00000007', [
            'price' => 5000,
        ]);
        $platedDishSpaghetti = TestDataFactory::createPlatedDish($horecaSpaghetti, true, $individualSpaghetti);
        TestDataFactory::addIngredient($platedDishSpaghetti, $ingredientBase, 200, 'GR', 1000, 1);

        // Product 3: LASANA GOURMET BOLONESA
        $individualLasanaBolonesa = TestDataFactory::createProduct($categoryIndividual, 'PPCF - LASANA GOURMET BOLONESA', 'PPCF00000002', [
            'price' => 4000,
        ]);
        TestDataFactory::createPlatedDish($individualLasanaBolonesa, false, null);

        $horecaLasanaBolonesa = TestDataFactory::createProduct($categoryHoreca, 'PCFH - HORECA LASANA GOURMET BOLONESA', 'PCFH00000002', [
            'price' => 5500,
        ]);
        $platedDishLasanaBolonesa = TestDataFactory::createPlatedDish($horecaLasanaBolonesa, true, $individualLasanaBolonesa);
        TestDataFactory::addIngredient($platedDishLasanaBolonesa, $ingredientBase, 250, 'GR', 1000, 1);

        // Product 4: LASANA FLORENTINA DE POLLO
        $individualLasanaFlorentina = TestDataFactory::createProduct($categoryIndividual, 'PPCF - LASANA FLORENTINA DE POLLO', 'PPCF00000001', [
            'price' => 4000,
        ]);
        TestDataFactory::createPlatedDish($individualLasanaFlorentina, false, null);

        $horecaLasanaFlorentina = TestDataFactory::createProduct($categoryHoreca, 'PCFH - HORECA LASANA FLORENTINA DE POLLO', 'PCFH00000001', [
            'price' => 5500,
        ]);
        $platedDishLasanaFlorentina = TestDataFactory::createPlatedDish($horecaLasanaFlorentina, true, $individualLasanaFlorentina);
        TestDataFactory::addIngredient($platedDishLasanaFlorentina, $ingredientBase, 250, 'GR', 1000, 1);

        // ===============================================================
        // 5. CREATE REPORT GROUPERS
        // ===============================================================
        $this->createGroupersByBranchName([
            ['name' => 'BRANCH A HORECA', 'branch_id' => $branchA->id],
            ['name' => 'BRANCH B HORECA', 'branch_id' => $branchB->id],
        ]);

        // ===============================================================
        // 6. CREATE PRODUCTION AREA
        // ===============================================================
        $productionArea = TestDataFactory::createProductionArea();

        // ===============================================================
        // 7. CREATE ORDERS - HORECA PRODUCTS (will be in OP)
        // ===============================================================
        $deliveryDate = now()->addDays(3)->format('Y-m-d');

        // HORECA orders that will be included in the OP
        $orderA_Pasta = TestDataFactory::createHorecaOrder($userA, $branchA, $horecaPasta, 10, $deliveryDate);
        $orderA_Spaghetti = TestDataFactory::createHorecaOrder($userA, $branchA, $horecaSpaghetti, 8, $deliveryDate);
        $orderB_LasanaBolonesa = TestDataFactory::createHorecaOrder($userB, $branchB, $horecaLasanaBolonesa, 12, $deliveryDate);
        $orderB_LasanaFlorentina = TestDataFactory::createHorecaOrder($userB, $branchB, $horecaLasanaFlorentina, 7, $deliveryDate);

        // Individual products ASSOCIATED with HORECA (will be in associatedOrderLines)
        // These should be counted: TOTAL = 16 + 18 + 21 + 14 = 69
        $orderA_IndivPasta = TestDataFactory::createHorecaOrder($userA, $branchA, $individualPasta, 16, $deliveryDate);
        $orderA_IndivSpaghetti = TestDataFactory::createHorecaOrder($userA, $branchA, $individualSpaghetti, 18, $deliveryDate);
        $orderB_IndivLasanaBolonesa = TestDataFactory::createHorecaOrder($userB, $branchB, $individualLasanaBolonesa, 21, $deliveryDate);
        $orderB_IndivLasanaFlorentina = TestDataFactory::createHorecaOrder($userB, $branchB, $individualLasanaFlorentina, 14, $deliveryDate);

        // ===============================================================
        // 8. CREATE ADVANCE ORDER FROM HORECA AND ASSOCIATED INDIVIDUAL ORDERS
        // ===============================================================
        $orderRepository = new OrderRepository();
        $advanceOrder = $orderRepository->createAdvanceOrderFromOrders(
            [
                $orderA_Pasta->id,
                $orderA_Spaghetti->id,
                $orderB_LasanaBolonesa->id,
                $orderB_LasanaFlorentina->id,
                $orderA_IndivPasta->id,
                $orderA_IndivSpaghetti->id,
                $orderB_IndivLasanaBolonesa->id,
                $orderB_IndivLasanaFlorentina->id,
            ],
            now()->format('Y-m-d H:i:s'),
            [$productionArea->id]
        );

        // ===============================================================
        // 9. CREATE EXTRA INDIVIDUAL ORDERS (NOT in OP, but in same order_ids range)
        // ===============================================================
        // These are the "ghost" units that should NOT be counted
        // They exist in the same orders' date range but are NOT in associatedOrderLines
        // This simulates production scenario where some individual products were added AFTER OP creation
        // or belong to a different OP but same date range

        // CRITICAL: We add these to EXISTING orders to simulate the exact production scenario
        // In production, order #9053 (for example) has BOTH HORECA and INDIVIDUAL products
        // But only HORECA is in the OP, the INDIVIDUAL is from another source

        // Extra 4 units of Pasta Alfredo (NOT in OP)
        \App\Models\OrderLine::create([
            'order_id' => $orderA_Pasta->id, // Same order as HORECA Pasta
            'product_id' => $individualPasta->id,
            'quantity' => 4, // Extra units
            'unit_price' => 3500,
            'total_price' => 14000,
        ]);

        // Extra 4 units of Spaghetti (NOT in OP)
        \App\Models\OrderLine::create([
            'order_id' => $orderA_Spaghetti->id, // Same order as HORECA Spaghetti
            'product_id' => $individualSpaghetti->id,
            'quantity' => 4,
            'unit_price' => 3500,
            'total_price' => 14000,
        ]);

        // Extra 2 units of Lasana Bolonesa (NOT in OP)
        \App\Models\OrderLine::create([
            'order_id' => $orderB_LasanaBolonesa->id, // Same order as HORECA Lasana Bolonesa
            'product_id' => $individualLasanaBolonesa->id,
            'quantity' => 2,
            'unit_price' => 4000,
            'total_price' => 8000,
        ]);

        // Extra 2 units of Lasana Florentina (NOT in OP)
        \App\Models\OrderLine::create([
            'order_id' => $orderB_LasanaFlorentina->id, // Same order as HORECA Lasana Florentina
            'product_id' => $individualLasanaFlorentina->id,
            'quantity' => 2,
            'unit_price' => 4000,
            'total_price' => 8000,
        ]);

        // TOTAL EXTRA UNITS: 4 + 4 + 2 + 2 = 12 units (THE BUG)

        // ===============================================================
        // 10. EXECUTE REPOSITORY AND VERIFY CORRECT BEHAVIOR
        // ===============================================================
        $repository = app(ConsolidadoEmplatadoRepository::class);
        $nestedData = $repository->getConsolidatedPlatedDishData([$advanceOrder->id], false);

        // ===============================================================
        // 11. ASSERTIONS - EXPECTED CORRECT BEHAVIOR
        // ===============================================================

        // Should have 4 product groups (one per HORECA product)
        $this->assertCount(4, $nestedData, 'Should have 4 HORECA product groups');

        // Calculate totals
        $totalIndividual = 0;
        $totalHoreca = 0;

        foreach ($nestedData as $productGroup) {
            $totalIndividual += $productGroup['total_individual'];
            $totalHoreca += $productGroup['total_horeca'];
        }

        // CRITICAL ASSERTIONS:
        // Total INDIVIDUAL should be 69 (16 + 18 + 21 + 14)
        // NOT 81 (69 + 12 extra units)
        $this->assertEquals(69, $totalIndividual,
            "Total INDIVIDUAL should be 69 (only from associatedOrderLines), NOT 81 (which includes 12 ghost units from same orders)"
        );

        // Total HORECA should be 37 (10 + 8 + 12 + 7)
        $this->assertEquals(37, $totalHoreca,
            "Total HORECA should be 37"
        );

        // Total PLATOS should be 106 (69 + 37)
        // NOT 118 (81 + 37 = with ghost units)
        $totalPlatos = $totalIndividual + $totalHoreca;
        $this->assertEquals(106, $totalPlatos,
            "Total PLATOS should be 106 (69 INDIVIDUAL + 37 HORECA), NOT 118 (which includes 12 ghost units)"
        );

        // ===============================================================
        // 12. DETAILED ASSERTIONS PER PRODUCT
        // ===============================================================

        // Find each product group and verify individual counts
        $pastaGroup = collect($nestedData)->firstWhere('product_id', $horecaPasta->id);
        $this->assertNotNull($pastaGroup, 'Pasta product group should exist');
        $this->assertEquals(16, $pastaGroup['total_individual'],
            "Pasta INDIVIDUAL should be 16, NOT 20 (16 + 4 ghost units)"
        );

        $spaghettiGroup = collect($nestedData)->firstWhere('product_id', $horecaSpaghetti->id);
        $this->assertNotNull($spaghettiGroup, 'Spaghetti product group should exist');
        $this->assertEquals(18, $spaghettiGroup['total_individual'],
            "Spaghetti INDIVIDUAL should be 18, NOT 22 (18 + 4 ghost units)"
        );

        $lasanaBolognesaGroup = collect($nestedData)->firstWhere('product_id', $horecaLasanaBolonesa->id);
        $this->assertNotNull($lasanaBolognesaGroup, 'Lasana Bolonesa product group should exist');
        $this->assertEquals(21, $lasanaBolognesaGroup['total_individual'],
            "Lasana Bolonesa INDIVIDUAL should be 21, NOT 23 (21 + 2 ghost units)"
        );

        $lasanaFlorentinaGroup = collect($nestedData)->firstWhere('product_id', $horecaLasanaFlorentina->id);
        $this->assertNotNull($lasanaFlorentinaGroup, 'Lasana Florentina product group should exist');
        $this->assertEquals(14, $lasanaFlorentinaGroup['total_individual'],
            "Lasana Florentina INDIVIDUAL should be 14, NOT 16 (14 + 2 ghost units)"
        );

        // ===============================================================
        // 13. DEBUGGING INFO (will show when test fails)
        // ===============================================================
        if ($totalIndividual != 69) {
            $this->fail(
                "\n\n" .
                "❌ TEST FAILED - BUG CONFIRMED\n" .
                "================================\n" .
                "Expected INDIVIDUAL total: 69\n" .
                "Actual INDIVIDUAL total: {$totalIndividual}\n" .
                "Difference: " . ($totalIndividual - 69) . " ghost units\n\n" .
                "This confirms the bug in ConsolidadoEmplatadoRepository.php (lines 52-64)\n" .
                "The repository is counting individual products from ALL order_lines,\n" .
                "not just those in associatedOrderLines.\n\n" .
                "Expected behavior:\n" .
                "  - Pasta Alfredo: 16 (actual: {$pastaGroup['total_individual']})\n" .
                "  - Spaghetti Bolonesa: 18 (actual: {$spaghettiGroup['total_individual']})\n" .
                "  - Lasana Bolonesa: 21 (actual: {$lasanaBolognesaGroup['total_individual']})\n" .
                "  - Lasana Florentina: 14 (actual: {$lasanaFlorentinaGroup['total_individual']})\n" .
                "  TOTAL: 69 (actual: {$totalIndividual})\n\n" .
                "To fix: Modify repository to iterate associatedOrderLines instead of querying OrderLine model.\n"
            );
        }
    }
}
