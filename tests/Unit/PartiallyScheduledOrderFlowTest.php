<?php

namespace Tests\Unit;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderProductionStatus;
use App\Enums\OrderStatus;
use App\Events\AdvanceOrderExecuted;
use App\Models\AdvanceOrder;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductionArea;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\OrderRepository;
use App\Repositories\WarehouseRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\AdvanceOrderReportTestHelper;
use Tests\TestCase;

/**
 * Partially Scheduled Order Flow Test
 *
 * This test validates the critical behavior of partially_scheduled flags:
 * - Products with partially_scheduled = false should NOT be included in OPs
 * - Products with partially_scheduled = true should be included
 * - When order changes from PARTIALLY_SCHEDULED to PROCESSED, all products are included
 *
 * SCENARIO:
 * - Single Order A (PARTIALLY_SCHEDULED) with 3 products
 * - Create 4 OPs at different moments
 * - Validate which products are included/excluded based on partially_scheduled flags
 *
 * KEY VALIDATIONS:
 * - OP #1: Only Product A included (only one with partially_scheduled = true)
 * - OP #2: Only Product A included (still the only one)
 * - OP #3: Products A and B included (B now has partially_scheduled = true), Product C excluded
 * - OP #4: All products included (order changed to PROCESSED)
 */
class PartiallyScheduledOrderFlowTest extends TestCase
{
    use RefreshDatabase;
    use AdvanceOrderReportTestHelper;

    // Test date
    private Carbon $dateFA;

    // Models
    private User $user;
    private Company $company;
    private Category $category;
    private ProductionArea $productionArea;
    private Warehouse $warehouse;

    // Products
    private Product $productA;
    private Product $productB;
    private Product $productC;

    // Order
    private Order $orderA;

    // Repositories
    private OrderRepository $orderRepository;
    private WarehouseRepository $warehouseRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Set test date
        $this->dateFA = Carbon::parse('2025-11-20');
        Carbon::setTestNow('2025-11-18 10:00:00');

        // Initialize repositories
        $this->orderRepository = app(OrderRepository::class);
        $this->warehouseRepository = app(WarehouseRepository::class);

        // Create test environment
        $this->createTestEnvironment();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_partially_scheduled_order_flow_across_eight_moments(): void
    {
        // ==================================================================
        // MOMENT 1: Create Order A (PARTIALLY_SCHEDULED)
        // ==================================================================
        $this->createMoment1Order();

        // Validate initial production status
        $this->orderA->refresh();
        $this->assertEquals(
            OrderProductionStatus::NOT_PRODUCED->value,
            $this->orderA->production_status,
            'MOMENT 1: Order A should be NOT_PRODUCED (no OPs executed yet)'
        );

        // Validate production detail
        $detail = $this->orderRepository->getProductionDetail($this->orderA);
        $this->assertEquals(OrderProductionStatus::NOT_PRODUCED->value, $detail['production_status']);
        $this->assertEquals(3, $detail['summary']['total_products']);
        $this->assertEquals(0, $detail['summary']['fully_produced_count']);
        $this->assertEquals(0, $detail['summary']['partially_produced_count']);
        $this->assertEquals(3, $detail['summary']['not_produced_count']);
        $this->assertEquals(0, $detail['summary']['total_coverage_percentage']);

        // ==================================================================
        // MOMENT 2: Create OP #1 (only Product A has partially_scheduled = true)
        // ==================================================================
        $op1 = $this->createOp1();

        $this->validateOp1IncludesOnlyProductA($op1);
        $this->validateOp1ExcludesProductsBAndC($op1);
        $this->validateOp1Pivots($op1);
        $this->validateOp1Calculations($op1);

        // Execute OP #1
        $this->executeOp($op1);

        // Validate production status after OP #1 executed
        // Reload the order from database to get updated values
        $this->orderA = \App\Models\Order::find($this->orderA->id);

        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $this->orderA->production_status,
            'MOMENT 2: Order A should be PARTIALLY_PRODUCED after OP #1 (only Product A covered: 15/15, missing B and C)'
        );

        // Validate production detail MOMENT 2
        $detail = $this->orderRepository->getProductionDetail($this->orderA);
        $this->assertEquals(OrderProductionStatus::PARTIALLY_PRODUCED->value, $detail['production_status']);
        $this->assertEquals(3, $detail['summary']['total_products']);
        $this->assertEquals(1, $detail['summary']['fully_produced_count'], 'MOMENT 2: Product A should be fully produced');
        $this->assertEquals(0, $detail['summary']['partially_produced_count']);
        $this->assertEquals(2, $detail['summary']['not_produced_count'], 'MOMENT 2: Products B and C should be not produced');

        // Product A: 15 produced of 15 required = 100%
        $productA = collect($detail['products'])->firstWhere('product_id', $this->productA->id);
        $this->assertEquals(15, $productA['required_quantity']);
        $this->assertEquals(15, $productA['produced_quantity']);
        $this->assertEquals(0, $productA['pending_quantity']);
        $this->assertEquals(100, $productA['coverage_percentage']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productA['status']);

        // ==================================================================
        // MOMENT 3: Update Order A - Product A quantity increases
        // ==================================================================
        $this->updateMoment3Order();

        // ==================================================================
        // MOMENT 4: Create OP #2 (still only Product A has partially_scheduled = true)
        // ==================================================================
        $op2 = $this->createOp2();

        $this->validateOp2IncludesOnlyProductA($op2);
        $this->validateOp2ExcludesProductsBAndC($op2);
        $this->validateOp2Pivots($op2);
        $this->validateOp2Calculations($op2);

        // Execute OP #2
        $this->executeOp($op2);

        // Validate production status after OP #2 executed
        $this->orderA->refresh();
        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $this->orderA->production_status,
            'MOMENT 4: Order A should be PARTIALLY_PRODUCED after OP #2 (only Product A fully covered: 23/23, missing B and C)'
        );

        // Validate production detail MOMENT 4
        $detail = $this->orderRepository->getProductionDetail($this->orderA);
        $this->assertEquals(OrderProductionStatus::PARTIALLY_PRODUCED->value, $detail['production_status']);
        $this->assertEquals(3, $detail['summary']['total_products']);
        $this->assertEquals(1, $detail['summary']['fully_produced_count'], 'MOMENT 4: Product A should be fully produced (23/23)');
        $this->assertEquals(0, $detail['summary']['partially_produced_count']);
        $this->assertEquals(2, $detail['summary']['not_produced_count'], 'MOMENT 4: Products B and C should be not produced');

        // Product A: 23 produced of 23 required (15 from OP#1 + 8 from OP#2)
        $productA = collect($detail['products'])->firstWhere('product_id', $this->productA->id);
        $this->assertEquals(23, $productA['required_quantity']);
        $this->assertEquals(23, $productA['produced_quantity'], 'MOMENT 4: Product A should have 23 produced (15+8)');
        $this->assertEquals(0, $productA['pending_quantity']);
        $this->assertEquals(100, $productA['coverage_percentage']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productA['status']);

        // ==================================================================
        // MOMENT 5: Update Order A - Product B becomes partially_scheduled = true
        // ==================================================================
        $this->updateMoment5Order();

        // ==================================================================
        // MOMENT 6: Create OP #3 (Products A and B have partially_scheduled = true)
        // ==================================================================
        $op3 = $this->createOp3();

        $this->validateOp3IncludesProductsAAndB($op3);
        $this->validateOp3ExcludesProductC($op3);
        $this->validateOp3Pivots($op3);
        $this->validateOp3Calculations($op3);

        // Execute OP #3
        $this->executeOp($op3);

        // Validate production status after OP #3 executed
        $this->orderA->refresh();
        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $this->orderA->production_status,
            'MOMENT 6: Order A should be PARTIALLY_PRODUCED after OP #3 (Products A: 23/23 and B: 18/18 covered, missing C)'
        );

        // Validate production detail MOMENT 6
        $detail = $this->orderRepository->getProductionDetail($this->orderA);
        $this->assertEquals(OrderProductionStatus::PARTIALLY_PRODUCED->value, $detail['production_status']);
        $this->assertEquals(3, $detail['summary']['total_products']);
        $this->assertEquals(2, $detail['summary']['fully_produced_count'], 'MOMENT 6: Products A and B should be fully produced');
        $this->assertEquals(0, $detail['summary']['partially_produced_count']);
        $this->assertEquals(1, $detail['summary']['not_produced_count'], 'MOMENT 6: Product C should be not produced');

        // Product A: still 23/23
        $productA = collect($detail['products'])->firstWhere('product_id', $this->productA->id);
        $this->assertEquals(23, $productA['produced_quantity']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productA['status']);

        // Product B: 18/18 produced
        $productB = collect($detail['products'])->firstWhere('product_id', $this->productB->id);
        $this->assertEquals(18, $productB['required_quantity']);
        $this->assertEquals(18, $productB['produced_quantity']);
        $this->assertEquals(0, $productB['pending_quantity']);
        $this->assertEquals(100, $productB['coverage_percentage']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productB['status']);

        // Product C: 0/25 (not produced yet)
        $productC = collect($detail['products'])->firstWhere('product_id', $this->productC->id);
        $this->assertEquals(25, $productC['required_quantity']);
        $this->assertEquals(0, $productC['produced_quantity']);
        $this->assertEquals(25, $productC['pending_quantity']);
        $this->assertEquals(0, $productC['coverage_percentage']);
        $this->assertEquals(OrderProductionStatus::NOT_PRODUCED->value, $productC['status']);

        // ==================================================================
        // MOMENT 7: Update Order A - Change to PROCESSED
        // ==================================================================
        $this->updateMoment7Order();

        // ==================================================================
        // MOMENT 8: Create OP #4 (all products included because order is PROCESSED)
        // ==================================================================
        $op4 = $this->createOp4();

        $this->validateOp4IncludesAllProducts($op4);
        $this->validateOp4Pivots($op4);
        $this->validateOp4Calculations($op4);

        // Execute OP #4
        $this->executeOp($op4);

        // Validate production status after OP #4 executed
        $this->orderA->refresh();
        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $this->orderA->production_status,
            'MOMENT 8: Order A should be FULLY_PRODUCED after OP #4 (ALL products covered: A: 23/23, B: 18/18, C: 31/31)'
        );

        // Validate production detail MOMENT 8 - FULLY PRODUCED
        $detail = $this->orderRepository->getProductionDetail($this->orderA);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $detail['production_status']);
        $this->assertEquals(3, $detail['summary']['total_products']);
        $this->assertEquals(3, $detail['summary']['fully_produced_count'], 'MOMENT 8: All 3 products should be fully produced');
        $this->assertEquals(0, $detail['summary']['partially_produced_count']);
        $this->assertEquals(0, $detail['summary']['not_produced_count']);
        $this->assertEquals(100, $detail['summary']['total_coverage_percentage'], 'MOMENT 8: Total coverage should be 100%');

        // Product A: 23/23 (still fully produced)
        $productA = collect($detail['products'])->firstWhere('product_id', $this->productA->id);
        $this->assertEquals(23, $productA['produced_quantity']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productA['status']);

        // Product B: 18/18 (still fully produced)
        $productB = collect($detail['products'])->firstWhere('product_id', $this->productB->id);
        $this->assertEquals(18, $productB['produced_quantity']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productB['status']);

        // Product C: 31/31 (NOW fully produced)
        $productC = collect($detail['products'])->firstWhere('product_id', $this->productC->id);
        $this->assertEquals(31, $productC['required_quantity']);
        $this->assertEquals(31, $productC['produced_quantity'], 'MOMENT 8: Product C should have 31 produced');
        $this->assertEquals(0, $productC['pending_quantity']);
        $this->assertEquals(100, $productC['coverage_percentage']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productC['status']);
    }

    /**
     * Test consolidated report date range validation
     *
     * This test runs the complete partially scheduled order flow scenario
     * and validates that the final consolidated report (with all 4 OPs)
     * has the correct date range in the header.
     *
     * VALIDATION:
     * - Report header should show: "Desde: [earliest dispatch date] - Hasta: [latest dispatch date]"
     * - Date range should match the dispatch dates from all orders included in the 4 OPs
     */
    public function test_consolidated_report_date_range_is_correct_after_all_ops(): void
    {
        // Run the complete scenario and get all 4 OPs
        [$op1, $op2, $op3, $op4] = $this->runPartiallyScheduledOrderScenario();

        // Get IDs of all OPs
        $opIds = [$op1->id, $op2->id, $op3->id, $op4->id];

        // Generate consolidated report with all parameters set to true
        $filePath = $this->generateConsolidatedReport(
            $opIds,
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true
        );

        // Load the Excel file
        $spreadsheet = $this->loadExcelFile($filePath);

        // Extract date range from report header
        $dateRange = $this->extractDateRangeFromReport($spreadsheet);

        // Validate that date range matches the advance orders' dispatch dates
        $this->validateDateRangeMatchesAdvanceOrders($opIds, $dateRange);

        // Clean up test file
        // $this->cleanupTestFile($filePath);  // Commented to keep the file for inspection
    }

    // ==================================================================
    // MOMENT 1: Create Order A
    // ==================================================================

    private function createMoment1Order(): void
    {
        $this->orderA = $this->createOrder($this->dateFA, OrderStatus::PARTIALLY_SCHEDULED);
        $this->createOrderLine($this->orderA, $this->productA, 15, true);   // partially_scheduled = true
        $this->createOrderLine($this->orderA, $this->productB, 10, false);  // partially_scheduled = false
        $this->createOrderLine($this->orderA, $this->productC, 25, false);  // partially_scheduled = false
    }

    // ==================================================================
    // MOMENT 2: Create OP #1
    // ==================================================================

    private function createOp1(): AdvanceOrder
    {
        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op1 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // Apply advance to Product A
        $this->applyAdvance($op1, $this->productA, 30);

        return $op1;
    }

    private function validateOp1IncludesOnlyProductA(AdvanceOrder $op1): void
    {
        $products = $op1->advanceOrderProducts()->get();

        $this->assertCount(1, $products, 'OP #1: Should have exactly 1 product');

        $productA = $products->first();
        $this->assertEquals($this->productA->id, $productA->product_id, 'OP #1: The only product should be Product A');
    }

    private function validateOp1ExcludesProductsBAndC(AdvanceOrder $op1): void
    {
        $productB = $op1->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertNull($productB, 'OP #1: Product B should NOT be included (partially_scheduled = false)');

        $productC = $op1->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertNull($productC, 'OP #1: Product C should NOT be included (partially_scheduled = false)');
    }

    private function validateOp1Pivots(AdvanceOrder $op1): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op1->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(1, $orderIds, 'OP #1: Should have 1 order in pivot');
        $this->assertContains($this->orderA->id, $orderIds, 'OP #1: Should include Order A');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op1->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(1, $orderLineIds, 'OP #1: Should have 1 order_line in pivot');

        // Only Product A line should be included
        $productALine = $this->orderA->orderLines()->where('product_id', $this->productA->id)->first();
        $this->assertContains($productALine->id, $orderLineIds, 'OP #1: Should include Product A line');

        // Product B and C lines should NOT be included
        $productBLine = $this->orderA->orderLines()->where('product_id', $this->productB->id)->first();
        $this->assertNotContains($productBLine->id, $orderLineIds, 'OP #1: Should NOT include Product B line');

        $productCLine = $this->orderA->orderLines()->where('product_id', $this->productC->id)->first();
        $this->assertNotContains($productCLine->id, $orderLineIds, 'OP #1: Should NOT include Product C line');
    }

    private function validateOp1Calculations(AdvanceOrder $op1): void
    {
        // Product A
        $productA = $op1->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertNotNull($productA, 'OP #1: Product A should exist');
        $this->assertEquals(15, $productA->ordered_quantity, 'OP #1 Product A: ordered_quantity should be 15');
        $this->assertEquals(15, $productA->ordered_quantity_new, 'OP #1 Product A: ordered_quantity_new should be 15');
        $this->assertEquals(30, $productA->quantity, 'OP #1 Product A: quantity (advance) should be 30');
        $this->assertEquals(30, $productA->total_to_produce, 'OP #1 Product A: total_to_produce should be 30');
    }

    // ==================================================================
    // MOMENT 3: Update Order A - Increase Product A quantity
    // ==================================================================

    private function updateMoment3Order(): void
    {
        $lineA = $this->orderA->orderLines()->where('product_id', $this->productA->id)->first();
        $lineA->update(['quantity' => 23]);
    }

    // ==================================================================
    // MOMENT 4: Create OP #2
    // ==================================================================

    private function createOp2(): AdvanceOrder
    {
        Carbon::setTestNow('2025-11-18 12:00:00');

        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op2 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // No advances applied

        return $op2;
    }

    private function validateOp2IncludesOnlyProductA(AdvanceOrder $op2): void
    {
        $products = $op2->advanceOrderProducts()->get();

        $this->assertCount(1, $products, 'OP #2: Should have exactly 1 product');

        $productA = $products->first();
        $this->assertEquals($this->productA->id, $productA->product_id, 'OP #2: The only product should be Product A');
    }

    private function validateOp2ExcludesProductsBAndC(AdvanceOrder $op2): void
    {
        $productB = $op2->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertNull($productB, 'OP #2: Product B should NOT be included (partially_scheduled = false)');

        $productC = $op2->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertNull($productC, 'OP #2: Product C should NOT be included (partially_scheduled = false)');
    }

    private function validateOp2Pivots(AdvanceOrder $op2): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op2->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(1, $orderIds, 'OP #2: Should have 1 order in pivot');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op2->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(1, $orderLineIds, 'OP #2: Should have 1 order_line in pivot (only Product A)');
    }

    private function validateOp2Calculations(AdvanceOrder $op2): void
    {
        // Product A
        $productA = $op2->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertNotNull($productA, 'OP #2: Product A should exist');

        $this->assertEquals(23, $productA->ordered_quantity, 'OP #2 Product A: ordered_quantity should be 23');
        $this->assertEquals(8, $productA->ordered_quantity_new, 'OP #2 Product A: ordered_quantity_new should be 8 (23 - 15)');
        $this->assertEquals(0, $productA->quantity, 'OP #2 Product A: quantity (advance) should be 0');

        // total_to_produce should be 0 because there's sufficient inventory from OP #1
        // OP #1 produced 30 units and consumed 15, leaving 15 units in stock
        // We only need 8 more units, so we don't need to produce anything
        // Formula: MAX(0, ordered_quantity_new - inventory) = MAX(0, 8 - 15) = 0
        $this->assertEquals(0, $productA->total_to_produce, 'OP #2 Product A: total_to_produce should be 0 (sufficient inventory from OP #1)');
    }

    // ==================================================================
    // MOMENT 5: Update Order A - Change Product B to partially_scheduled = true
    // ==================================================================

    private function updateMoment5Order(): void
    {
        $lineB = $this->orderA->orderLines()->where('product_id', $this->productB->id)->first();
        $lineB->update([
            'partially_scheduled' => true,
            'quantity' => 18,
        ]);
    }

    // ==================================================================
    // MOMENT 6: Create OP #3
    // ==================================================================

    private function createOp3(): AdvanceOrder
    {
        Carbon::setTestNow('2025-11-18 14:00:00');

        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op3 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // No advances applied

        return $op3;
    }

    private function validateOp3IncludesProductsAAndB(AdvanceOrder $op3): void
    {
        $products = $op3->advanceOrderProducts()->get();

        $this->assertCount(2, $products, 'OP #3: Should have exactly 2 products');

        $productIds = $products->pluck('product_id')->toArray();
        $this->assertContains($this->productA->id, $productIds, 'OP #3: Should include Product A');
        $this->assertContains($this->productB->id, $productIds, 'OP #3: Should include Product B');
    }

    private function validateOp3ExcludesProductC(AdvanceOrder $op3): void
    {
        $productC = $op3->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertNull($productC, 'OP #3: Product C should NOT be included (partially_scheduled = false)');
    }

    private function validateOp3Pivots(AdvanceOrder $op3): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op3->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(1, $orderIds, 'OP #3: Should have 1 order in pivot');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op3->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(2, $orderLineIds, 'OP #3: Should have 2 order_lines in pivot (Products A and B)');

        // Product A and B lines should be included
        $productALine = $this->orderA->orderLines()->where('product_id', $this->productA->id)->first();
        $this->assertContains($productALine->id, $orderLineIds, 'OP #3: Should include Product A line');

        $productBLine = $this->orderA->orderLines()->where('product_id', $this->productB->id)->first();
        $this->assertContains($productBLine->id, $orderLineIds, 'OP #3: Should include Product B line');

        // Product C line should NOT be included
        $productCLine = $this->orderA->orderLines()->where('product_id', $this->productC->id)->first();
        $this->assertNotContains($productCLine->id, $orderLineIds, 'OP #3: Should NOT include Product C line');
    }

    private function validateOp3Calculations(AdvanceOrder $op3): void
    {
        // Product A
        $productA = $op3->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertNotNull($productA, 'OP #3: Product A should exist');
        $this->assertEquals(23, $productA->ordered_quantity, 'OP #3 Product A: ordered_quantity should be 23');
        $this->assertEquals(0, $productA->ordered_quantity_new, 'OP #3 Product A: ordered_quantity_new should be 0 (already covered)');
        $this->assertEquals(0, $productA->quantity, 'OP #3 Product A: quantity (advance) should be 0');
        $this->assertEquals(0, $productA->total_to_produce, 'OP #3 Product A: total_to_produce should be 0');

        // Product B
        $productB = $op3->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertNotNull($productB, 'OP #3: Product B should exist');
        $this->assertEquals(18, $productB->ordered_quantity, 'OP #3 Product B: ordered_quantity should be 18');
        $this->assertEquals(18, $productB->ordered_quantity_new, 'OP #3 Product B: ordered_quantity_new should be 18 (first time included)');
        $this->assertEquals(0, $productB->quantity, 'OP #3 Product B: quantity (advance) should be 0');
        $this->assertEquals(18, $productB->total_to_produce, 'OP #3 Product B: total_to_produce should be 18');
    }

    // ==================================================================
    // MOMENT 7: Change Order A to PROCESSED
    // ==================================================================

    private function updateMoment7Order(): void
    {
        $this->orderA->update(['status' => OrderStatus::PROCESSED->value]);

        // Also update Product C quantity
        $lineC = $this->orderA->orderLines()->where('product_id', $this->productC->id)->first();
        $lineC->update(['quantity' => 31]);
    }

    // ==================================================================
    // MOMENT 8: Create OP #4
    // ==================================================================

    private function createOp4(): AdvanceOrder
    {
        Carbon::setTestNow('2025-11-18 16:00:00');

        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op4 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // No advances applied

        return $op4;
    }

    private function validateOp4IncludesAllProducts(AdvanceOrder $op4): void
    {
        $products = $op4->advanceOrderProducts()->get();

        $this->assertCount(3, $products, 'OP #4: Should have all 3 products (order is PROCESSED)');

        $productIds = $products->pluck('product_id')->toArray();
        $this->assertContains($this->productA->id, $productIds, 'OP #4: Should include Product A');
        $this->assertContains($this->productB->id, $productIds, 'OP #4: Should include Product B');
        $this->assertContains($this->productC->id, $productIds, 'OP #4: Should include Product C');
    }

    private function validateOp4Pivots(AdvanceOrder $op4): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op4->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(1, $orderIds, 'OP #4: Should have 1 order in pivot');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op4->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(3, $orderLineIds, 'OP #4: Should have 3 order_lines in pivot (all products)');

        // All product lines should be included
        $productALine = $this->orderA->orderLines()->where('product_id', $this->productA->id)->first();
        $this->assertContains($productALine->id, $orderLineIds, 'OP #4: Should include Product A line');

        $productBLine = $this->orderA->orderLines()->where('product_id', $this->productB->id)->first();
        $this->assertContains($productBLine->id, $orderLineIds, 'OP #4: Should include Product B line');

        $productCLine = $this->orderA->orderLines()->where('product_id', $this->productC->id)->first();
        $this->assertContains($productCLine->id, $orderLineIds, 'OP #4: Should include Product C line');
    }

    private function validateOp4Calculations(AdvanceOrder $op4): void
    {
        // Product A
        $productA = $op4->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertNotNull($productA, 'OP #4: Product A should exist');
        $this->assertEquals(23, $productA->ordered_quantity, 'OP #4 Product A: ordered_quantity should be 23');
        $this->assertEquals(0, $productA->ordered_quantity_new, 'OP #4 Product A: ordered_quantity_new should be 0');
        $this->assertEquals(0, $productA->quantity, 'OP #4 Product A: quantity (advance) should be 0');
        $this->assertEquals(0, $productA->total_to_produce, 'OP #4 Product A: total_to_produce should be 0');

        // Product B
        $productB = $op4->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertNotNull($productB, 'OP #4: Product B should exist');
        $this->assertEquals(18, $productB->ordered_quantity, 'OP #4 Product B: ordered_quantity should be 18');
        $this->assertEquals(0, $productB->ordered_quantity_new, 'OP #4 Product B: ordered_quantity_new should be 0');
        $this->assertEquals(0, $productB->quantity, 'OP #4 Product B: quantity (advance) should be 0');
        $this->assertEquals(0, $productB->total_to_produce, 'OP #4 Product B: total_to_produce should be 0');

        // Product C
        $productC = $op4->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertNotNull($productC, 'OP #4: Product C should exist');
        $this->assertEquals(31, $productC->ordered_quantity, 'OP #4 Product C: ordered_quantity should be 31');
        $this->assertEquals(31, $productC->ordered_quantity_new, 'OP #4 Product C: ordered_quantity_new should be 31 (first time included)');
        $this->assertEquals(0, $productC->quantity, 'OP #4 Product C: quantity (advance) should be 0');
        $this->assertEquals(31, $productC->total_to_produce, 'OP #4 Product C: total_to_produce should be 31');
    }

    // ==================================================================
    // HELPER METHODS
    // ==================================================================

    private function createTestEnvironment(): void
    {
        // Create production area
        $this->productionArea = ProductionArea::create([
            'name' => 'Test Production Area',
            'description' => 'Production area for testing',
        ]);

        // Create category
        $this->category = Category::create([
            'name' => 'Test Category',
            'description' => 'Category for testing',
        ]);

        // Create products
        $this->productA = $this->createProduct('Product A');
        $this->productB = $this->createProduct('Product B');
        $this->productC = $this->createProduct('Product C');

        // Create user
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'is_default' => true,
        ]);

        $this->company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TC001',
            'fantasy_name' => 'Test Company',
            'email' => 'test.company@test.com',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'company_id' => $this->company->id,
            'shipping_address' => 'Test Address 123',
            'fantasy_name' => 'Test Branch',
            'min_price_order' => 0,
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.USER',
            'email' => 'test.user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'branch_id' => $branch->id,
        ]);

        // Use default warehouse
        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();

        // Associate products with warehouse
        foreach ([$this->productA, $this->productB, $this->productC] as $product) {
            $this->warehouseRepository->associateProductToWarehouse($product, $this->warehouse, 0, 'UND');
        }
    }

    private function createProduct(string $name): Product
    {
        $code = strtoupper(str_replace(' ', '_', $name));

        $product = Product::create([
            'name' => $name,
            'description' => "Description for {$name}",
            'code' => $code,
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        $product->productionAreas()->attach($this->productionArea->id);

        return $product;
    }

    private function createOrder(Carbon $dispatchDate, OrderStatus $status): Order
    {
        return Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => $dispatchDate->toDateString(),
            'date' => $dispatchDate->toDateString(),
            'status' => $status->value,
            'total' => 10000,
            'total_with_tax' => 11900,
            'tax_amount' => 1900,
            'grand_total' => 11900,
            'dispatch_cost' => 0,
        ]);
    }

    private function createOrderLine(
        Order $order,
        Product $product,
        int $quantity,
        bool $partiallyScheduled = false
    ): OrderLine {
        return OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'subtotal' => $quantity * 1000,
            'partially_scheduled' => $partiallyScheduled,
        ]);
    }

    private function applyAdvance(AdvanceOrder $op, Product $product, int $advanceQuantity): void
    {
        $advanceOrderProduct = $op->advanceOrderProducts()
            ->where('product_id', $product->id)
            ->first();

        if ($advanceOrderProduct) {
            $advanceOrderProduct->quantity = $advanceQuantity;
            $advanceOrderProduct->save();
            $advanceOrderProduct->refresh();
        }
    }

    /**
     * Run the complete partially scheduled order flow scenario
     *
     * This encapsulates the entire 8-moment flow:
     * - MOMENT 1: Create Order A (PARTIALLY_SCHEDULED) with 3 products
     * - MOMENT 2: Create OP #1 (only Product A has partially_scheduled = true)
     * - MOMENT 3: Update Order A - Product A quantity increases
     * - MOMENT 4: Create OP #2 (still only Product A)
     * - MOMENT 5: Update Order A - Product B becomes partially_scheduled = true
     * - MOMENT 6: Create OP #3 (Products A and B)
     * - MOMENT 7: Update Order A - Change to PROCESSED
     * - MOMENT 8: Create OP #4 (all products included)
     *
     * @return array Array of created and executed OPs [$op1, $op2, $op3, $op4]
     */
    protected function runPartiallyScheduledOrderScenario(): array
    {
        // MOMENT 1: Create Order A (PARTIALLY_SCHEDULED)
        $this->createMoment1Order();

        // MOMENT 2: Create OP #1 (only Product A has partially_scheduled = true)
        $op1 = $this->createOp1();
        $this->executeOp($op1);

        // MOMENT 3: Update Order A - Product A quantity increases
        $this->updateMoment3Order();

        // MOMENT 4: Create OP #2 (still only Product A has partially_scheduled = true)
        $op2 = $this->createOp2();
        $this->executeOp($op2);

        // MOMENT 5: Update Order A - Product B becomes partially_scheduled = true
        $this->updateMoment5Order();

        // MOMENT 6: Create OP #3 (Products A and B have partially_scheduled = true)
        $op3 = $this->createOp3();
        $this->executeOp($op3);

        // MOMENT 7: Update Order A - Change to PROCESSED
        $this->updateMoment7Order();

        // MOMENT 8: Create OP #4 (all products included because order is PROCESSED)
        $op4 = $this->createOp4();
        $this->executeOp($op4);

        return [$op1, $op2, $op3, $op4];
    }

    private function executeOp(AdvanceOrder $op): void
    {
        $this->actingAs($this->user);
        $op->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op));

        // Execute the job to mark orders as needing update
        // In tests, jobs run synchronously so we need to manually process them
        \Illuminate\Support\Facades\Queue::fake();

        // Trigger the observers which will dispatch the job
        // The job marks orders with production_status_needs_update = true
        $relatedOrderIds = \DB::table('advance_order_orders')
            ->where('advance_order_id', $op->id)
            ->pluck('order_id')
            ->toArray();

        if (!empty($relatedOrderIds)) {
            \DB::table('orders')
                ->whereIn('id', $relatedOrderIds)
                ->update(['production_status_needs_update' => true]);
        }

        // Execute the command to update production status
        // This mimics the real-world scenario where the command runs periodically
        $this->artisan('orders:update-production-status');
    }
}
