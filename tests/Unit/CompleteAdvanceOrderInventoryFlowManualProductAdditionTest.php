<?php

namespace Tests\Unit;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderStatus;
use App\Events\AdvanceOrderExecuted;
use App\Models\AdvanceOrder;
use App\Models\AdvanceOrderProduct;
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
use App\Repositories\AdvanceOrderRepository;
use App\Repositories\OrderRepository;
use App\Repositories\WarehouseRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Complete AdvanceOrder Inventory Flow Test - MANUAL PRODUCT ADDITION
 *
 * This test validates the EXACT SAME scenario as CompleteAdvanceOrderInventoryFlowTest
 * but using MANUAL PRODUCT ADDITION from Filament RelationManager instead of
 * createAdvanceOrderFromOrders() or use_products_in_orders=true.
 *
 * CRITICAL: All three tests must validate EXACTLY THE SAME expectations because:
 * - All apply the same inclusion rules (PROCESSED/PARTIALLY_SCHEDULED)
 * - All consider partially_scheduled flags
 * - All synchronize pivots (via different events but same logic)
 * - All calculate inventory the same way
 *
 * The ONLY difference is the creation method:
 * - Original: createAdvanceOrderFromOrders() - user selects specific orders
 * - Test #2: Manual creation with use_products_in_orders = true - loads from date range
 * - This test: Manual OP creation (empty) + add each product via RelationManager
 *
 * Expected result: ALL validations should pass identically.
 */
class CompleteAdvanceOrderInventoryFlowManualProductAdditionTest extends TestCase
{
    use RefreshDatabase;

    // Test dates
    private Carbon $dateFA;
    private Carbon $dateFB;

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
    private Product $productD;

    // Orders
    private Order $orderA;
    private Order $orderB;
    private Order $orderC;
    private Order $orderD;
    private Order $orderE;
    private Order $orderF;
    private Order $orderG;

    // Repositories
    private OrderRepository $orderRepository;
    private WarehouseRepository $warehouseRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Set test dates
        $this->dateFA = Carbon::parse('2025-11-20');
        $this->dateFB = Carbon::parse('2025-11-21');
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

    public function test_complete_advance_order_inventory_flow_across_seven_moments(): void
    {
        // ==================================================================
        // MOMENT 1: Create Initial Orders
        // ==================================================================
        $this->createMoment1Orders();

        // ==================================================================
        // MOMENT 2: Create OP #1 (FA - FA)
        // ==================================================================
        $op1 = $this->createOp1();

        // Validate OP #1 pivots
        $this->validateOp1Pivots($op1);

        // Validate OP #1 calculations
        $this->validateOp1Calculations($op1);

        // Execute OP #1
        $this->executeOp($op1);

        // Validate OP #1 inventory
        $this->validateOp1Inventory();

        // ==================================================================
        // MOMENT 3: Update Orders
        // ==================================================================
        $this->updateMoment3Orders();

        // ==================================================================
        // MOMENT 4: Create New Orders
        // ==================================================================
        $this->createMoment4Orders();

        // ==================================================================
        // MOMENT 5: Create OP #2 (FA - FB)
        // ==================================================================
        $op2 = $this->createOp2();

        // Validate OP #2 pivots
        $this->validateOp2Pivots($op2);

        // Validate OP #2 calculations
        $this->validateOp2Calculations($op2);

        // Execute OP #2
        $this->executeOp($op2);

        // Validate OP #2 inventory
        $this->validateOp2Inventory();

        // ==================================================================
        // MOMENT 6: Update Orders to PROCESSED
        // ==================================================================
        $this->updateMoment6Orders();

        // ==================================================================
        // MOMENT 7: Create OP #3 (FA - FB) without advances
        // ==================================================================
        $op3 = $this->createOp3();

        // Validate OP #3 pivots
        $this->validateOp3Pivots($op3);

        // Validate OP #3 calculations
        $this->validateOp3Calculations($op3);

        // Execute OP #3
        $this->executeOp($op3);

        // Validate OP #3 inventory
        $this->validateOp3Inventory();
    }

    // ==================================================================
    // MOMENT 1: Create Initial Orders
    // ==================================================================

    private function createMoment1Orders(): void
    {
        // Order A: PARTIALLY_SCHEDULED (FA)
        $this->orderA = $this->createOrder($this->dateFA, OrderStatus::PARTIALLY_SCHEDULED);
        $this->createOrderLine($this->orderA, $this->productA, 15, true);
        $this->createOrderLine($this->orderA, $this->productB, 10, false);
        $this->createOrderLine($this->orderA, $this->productC, 25, false);

        // Order B: PROCESSED (FA)
        $this->orderB = $this->createOrder($this->dateFA, OrderStatus::PROCESSED);
        $this->createOrderLine($this->orderB, $this->productA, 7);
        $this->createOrderLine($this->orderB, $this->productB, 12);
        $this->createOrderLine($this->orderB, $this->productC, 9);

        // Order C: PENDING (FA)
        $this->orderC = $this->createOrder($this->dateFA, OrderStatus::PENDING);
        $this->createOrderLine($this->orderC, $this->productA, 7);
        $this->createOrderLine($this->orderC, $this->productB, 12);
        $this->createOrderLine($this->orderC, $this->productC, 9);

        // Order D: PENDING (FA)
        $this->orderD = $this->createOrder($this->dateFA, OrderStatus::PENDING);
        $this->createOrderLine($this->orderD, $this->productA, 2);
        $this->createOrderLine($this->orderD, $this->productB, 1);
        $this->createOrderLine($this->orderD, $this->productC, 4);

        // Order E: PENDING (FA)
        $this->orderE = $this->createOrder($this->dateFA, OrderStatus::PENDING);
        $this->createOrderLine($this->orderE, $this->productA, 6);
        $this->createOrderLine($this->orderE, $this->productB, 12);
        $this->createOrderLine($this->orderE, $this->productC, 14);
        $this->createOrderLine($this->orderE, $this->productD, 16);
    }

    // ==================================================================
    // MOMENT 2: Create OP #1
    // ==================================================================

    private function createOp1(): AdvanceOrder
    {
        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        // Create empty AdvanceOrder (simulating Filament form submission with use_products_in_orders = false)
        $op1 = AdvanceOrder::create([
            'preparation_datetime' => $preparationDatetime,
            'initial_dispatch_date' => $this->dateFA->format('Y-m-d'),
            'final_dispatch_date' => $this->dateFA->format('Y-m-d'),
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::PENDING,
        ]);

        // Get products data from orders (same as RelationManager does)
        $productsData = $this->orderRepository->getProductsFromOrdersInDateRange(
            $op1->initial_dispatch_date->format('Y-m-d'),
            $op1->final_dispatch_date->format('Y-m-d'),
            [$this->productionArea->id]
        );

        $advanceOrderRepo = app(AdvanceOrderRepository::class);

        // Add each product manually (simulating RelationManager CreateAction)
        // Product A
        $this->addProductToOp($op1, $this->productA, $productsData, $advanceOrderRepo, 30);

        // Product B
        $this->addProductToOp($op1, $this->productB, $productsData, $advanceOrderRepo, 40);

        // Product C
        $this->addProductToOp($op1, $this->productC, $productsData, $advanceOrderRepo, 100);

        return $op1;
    }

    private function validateOp1Pivots(AdvanceOrder $op1): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op1->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(2, $orderIds, 'OP #1: Should have 2 orders in pivot');
        $this->assertContains($this->orderA->id, $orderIds, 'OP #1: Should include Order A');
        $this->assertContains($this->orderB->id, $orderIds, 'OP #1: Should include Order B');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op1->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(4, $orderLineIds, 'OP #1: Should have 4 order_lines in pivot');

        // Order A should only include Product A (partially_scheduled = true)
        $orderALine = $this->orderA->orderLines()->where('product_id', $this->productA->id)->first();
        $this->assertContains($orderALine->id, $orderLineIds, 'OP #1: Should include Order A - Product A line');

        // Order B should include all products
        foreach ($this->orderB->orderLines as $line) {
            $this->assertContains($line->id, $orderLineIds, "OP #1: Should include Order B - Product {$line->product_id} line");
        }
    }

    private function validateOp1Calculations(AdvanceOrder $op1): void
    {
        // Product A
        $productA = $op1->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertEquals(22, $productA->ordered_quantity, 'OP #1 Product A: ordered_quantity should be 22');
        $this->assertEquals(22, $productA->ordered_quantity_new, 'OP #1 Product A: ordered_quantity_new should be 22');
        $this->assertEquals(30, $productA->quantity, 'OP #1 Product A: quantity (advance) should be 30');
        $this->assertEquals(30, $productA->total_to_produce, 'OP #1 Product A: total_to_produce should be 30');

        // Product B
        $productB = $op1->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertEquals(12, $productB->ordered_quantity, 'OP #1 Product B: ordered_quantity should be 12');
        $this->assertEquals(12, $productB->ordered_quantity_new, 'OP #1 Product B: ordered_quantity_new should be 12');
        $this->assertEquals(40, $productB->quantity, 'OP #1 Product B: quantity (advance) should be 40');
        $this->assertEquals(40, $productB->total_to_produce, 'OP #1 Product B: total_to_produce should be 40');

        // Product C
        $productC = $op1->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertEquals(9, $productC->ordered_quantity, 'OP #1 Product C: ordered_quantity should be 9');
        $this->assertEquals(9, $productC->ordered_quantity_new, 'OP #1 Product C: ordered_quantity_new should be 9');
        $this->assertEquals(100, $productC->quantity, 'OP #1 Product C: quantity (advance) should be 100');
        $this->assertEquals(100, $productC->total_to_produce, 'OP #1 Product C: total_to_produce should be 100');
    }

    private function validateOp1Inventory(): void
    {
        $this->assertEquals(8, $this->getStock($this->productA), 'OP #1: Product A stock should be 8');
        $this->assertEquals(28, $this->getStock($this->productB), 'OP #1: Product B stock should be 28');
        $this->assertEquals(91, $this->getStock($this->productC), 'OP #1: Product C stock should be 91');
        $this->assertEquals(0, $this->getStock($this->productD), 'OP #1: Product D stock should be 0');
    }

    // ==================================================================
    // MOMENT 3: Update Orders
    // ==================================================================

    private function updateMoment3Orders(): void
    {
        // Order A: Update quantities and partially_scheduled flags
        $lineA = $this->orderA->orderLines()->where('product_id', $this->productA->id)->first();
        $lineA->update(['quantity' => 22]);

        $lineB = $this->orderA->orderLines()->where('product_id', $this->productB->id)->first();
        $lineB->update(['partially_scheduled' => true]);

        // Order C: Change to PROCESSED
        $this->orderC->update(['status' => OrderStatus::PROCESSED->value]);

        // Order E: Change to PROCESSED
        $this->orderE->update(['status' => OrderStatus::PROCESSED->value]);
    }

    // ==================================================================
    // MOMENT 4: Create New Orders
    // ==================================================================

    private function createMoment4Orders(): void
    {
        // Order F: PROCESSED (FB)
        $this->orderF = $this->createOrder($this->dateFB, OrderStatus::PROCESSED);
        $this->createOrderLine($this->orderF, $this->productA, 6);
        $this->createOrderLine($this->orderF, $this->productB, 12);
        $this->createOrderLine($this->orderF, $this->productC, 14);
        $this->createOrderLine($this->orderF, $this->productD, 16);

        // Order G: PENDING (FB)
        $this->orderG = $this->createOrder($this->dateFB, OrderStatus::PENDING);
        $this->createOrderLine($this->orderG, $this->productA, 6);
        $this->createOrderLine($this->orderG, $this->productB, 12);
        $this->createOrderLine($this->orderG, $this->productC, 14);
        $this->createOrderLine($this->orderG, $this->productD, 16);
    }

    // ==================================================================
    // MOMENT 5: Create OP #2
    // ==================================================================

    private function createOp2(): AdvanceOrder
    {
        Carbon::setTestNow('2025-11-18 12:00:00');

        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        // Create empty AdvanceOrder (simulating Filament form submission with use_products_in_orders = false)
        $op2 = AdvanceOrder::create([
            'preparation_datetime' => $preparationDatetime,
            'initial_dispatch_date' => $this->dateFA->format('Y-m-d'),
            'final_dispatch_date' => $this->dateFB->format('Y-m-d'),
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::PENDING,
        ]);

        // Get products data from orders (same as RelationManager does)
        $productsData = $this->orderRepository->getProductsFromOrdersInDateRange(
            $op2->initial_dispatch_date->format('Y-m-d'),
            $op2->final_dispatch_date->format('Y-m-d'),
            [$this->productionArea->id]
        );

        $advanceOrderRepo = app(AdvanceOrderRepository::class);

        // Add each product manually (simulating RelationManager CreateAction)
        // Product A
        $this->addProductToOp($op2, $this->productA, $productsData, $advanceOrderRepo, 70);

        // Product B
        $this->addProductToOp($op2, $this->productB, $productsData, $advanceOrderRepo, 110);

        // Product C
        $this->addProductToOp($op2, $this->productC, $productsData, $advanceOrderRepo, 220);

        // Product D: no advance (0)
        $this->addProductToOp($op2, $this->productD, $productsData, $advanceOrderRepo, 0);

        return $op2;
    }

    private function validateOp2Pivots(AdvanceOrder $op2): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op2->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(5, $orderIds, 'OP #2: Should have 5 orders in pivot');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op2->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(16, $orderLineIds, 'OP #2: Should have 16 order_lines in pivot');
    }

    private function validateOp2Calculations(AdvanceOrder $op2): void
    {
        // Product A
        $productA = $op2->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertEquals(48, $productA->ordered_quantity, 'OP #2 Product A: ordered_quantity should be 48');
        $this->assertEquals(26, $productA->ordered_quantity_new, 'OP #2 Product A: ordered_quantity_new should be 26');
        $this->assertEquals(70, $productA->quantity, 'OP #2 Product A: quantity (advance) should be 70');
        $this->assertEquals(62, $productA->total_to_produce, 'OP #2 Product A: total_to_produce should be 62 (70 - 8 stock)');

        // Product B
        $productB = $op2->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertEquals(58, $productB->ordered_quantity, 'OP #2 Product B: ordered_quantity should be 58');
        $this->assertEquals(46, $productB->ordered_quantity_new, 'OP #2 Product B: ordered_quantity_new should be 46');
        $this->assertEquals(110, $productB->quantity, 'OP #2 Product B: quantity (advance) should be 110');
        $this->assertEquals(82, $productB->total_to_produce, 'OP #2 Product B: total_to_produce should be 82 (110 - 28 stock)');

        // Product C
        $productC = $op2->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertEquals(46, $productC->ordered_quantity, 'OP #2 Product C: ordered_quantity should be 46');
        $this->assertEquals(37, $productC->ordered_quantity_new, 'OP #2 Product C: ordered_quantity_new should be 37');
        $this->assertEquals(220, $productC->quantity, 'OP #2 Product C: quantity (advance) should be 220');
        $this->assertEquals(129, $productC->total_to_produce, 'OP #2 Product C: total_to_produce should be 129 (220 - 91 stock)');

        // Product D
        $productD = $op2->advanceOrderProducts()->where('product_id', $this->productD->id)->first();
        $this->assertEquals(32, $productD->ordered_quantity, 'OP #2 Product D: ordered_quantity should be 32');
        $this->assertEquals(32, $productD->ordered_quantity_new, 'OP #2 Product D: ordered_quantity_new should be 32');
        $this->assertEquals(0, $productD->quantity, 'OP #2 Product D: quantity (advance) should be 0');
        $this->assertEquals(32, $productD->total_to_produce, 'OP #2 Product D: total_to_produce should be 32');
    }

    private function validateOp2Inventory(): void
    {
        $this->assertEquals(44, $this->getStock($this->productA), 'OP #2: Product A stock should be 44');
        $this->assertEquals(64, $this->getStock($this->productB), 'OP #2: Product B stock should be 64');
        $this->assertEquals(183, $this->getStock($this->productC), 'OP #2: Product C stock should be 183');
        $this->assertEquals(0, $this->getStock($this->productD), 'OP #2: Product D stock should be 0');
    }

    // ==================================================================
    // MOMENT 6: Update Orders to PROCESSED
    // ==================================================================

    private function updateMoment6Orders(): void
    {
        // Order A: Change to PROCESSED
        $this->orderA->update(['status' => OrderStatus::PROCESSED->value]);

        // Order D: Change to PROCESSED
        $this->orderD->update(['status' => OrderStatus::PROCESSED->value]);

        // Order G: Change to PROCESSED
        $this->orderG->update(['status' => OrderStatus::PROCESSED->value]);
    }

    // ==================================================================
    // MOMENT 7: Create OP #3 (no advances)
    // ==================================================================

    private function createOp3(): AdvanceOrder
    {
        Carbon::setTestNow('2025-11-18 14:00:00');

        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        // Create empty AdvanceOrder (simulating Filament form submission with use_products_in_orders = false)
        $op3 = AdvanceOrder::create([
            'preparation_datetime' => $preparationDatetime,
            'initial_dispatch_date' => $this->dateFA->format('Y-m-d'),
            'final_dispatch_date' => $this->dateFB->format('Y-m-d'),
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::PENDING,
        ]);

        // Get products data from orders (same as RelationManager does)
        $productsData = $this->orderRepository->getProductsFromOrdersInDateRange(
            $op3->initial_dispatch_date->format('Y-m-d'),
            $op3->final_dispatch_date->format('Y-m-d'),
            [$this->productionArea->id]
        );

        $advanceOrderRepo = app(AdvanceOrderRepository::class);

        // Add each product manually (simulating RelationManager CreateAction)
        // No advances applied (all 0)
        // Product A
        $this->addProductToOp($op3, $this->productA, $productsData, $advanceOrderRepo, 0);

        // Product B
        $this->addProductToOp($op3, $this->productB, $productsData, $advanceOrderRepo, 0);

        // Product C
        $this->addProductToOp($op3, $this->productC, $productsData, $advanceOrderRepo, 0);

        // Product D
        $this->addProductToOp($op3, $this->productD, $productsData, $advanceOrderRepo, 0);

        return $op3;
    }

    private function validateOp3Pivots(AdvanceOrder $op3): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op3->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(7, $orderIds, 'OP #3: Should have 7 orders in pivot');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op3->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(24, $orderLineIds, 'OP #3: Should have 24 order_lines in pivot');
    }

    private function validateOp3Calculations(AdvanceOrder $op3): void
    {
        // Product A
        $productA = $op3->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertEquals(56, $productA->ordered_quantity, 'OP #3 Product A: ordered_quantity should be 56');
        $this->assertEquals(8, $productA->ordered_quantity_new, 'OP #3 Product A: ordered_quantity_new should be 8');
        $this->assertEquals(0, $productA->quantity, 'OP #3 Product A: quantity (advance) should be 0');
        $this->assertEquals(0, $productA->total_to_produce, 'OP #3 Product A: total_to_produce should be 0 (sufficient stock)');

        // Product B
        $productB = $op3->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertEquals(71, $productB->ordered_quantity, 'OP #3 Product B: ordered_quantity should be 71');
        $this->assertEquals(13, $productB->ordered_quantity_new, 'OP #3 Product B: ordered_quantity_new should be 13');
        $this->assertEquals(0, $productB->quantity, 'OP #3 Product B: quantity (advance) should be 0');
        $this->assertEquals(0, $productB->total_to_produce, 'OP #3 Product B: total_to_produce should be 0 (sufficient stock)');

        // Product C
        $productC = $op3->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertEquals(89, $productC->ordered_quantity, 'OP #3 Product C: ordered_quantity should be 89');
        $this->assertEquals(43, $productC->ordered_quantity_new, 'OP #3 Product C: ordered_quantity_new should be 43');
        $this->assertEquals(0, $productC->quantity, 'OP #3 Product C: quantity (advance) should be 0');
        $this->assertEquals(0, $productC->total_to_produce, 'OP #3 Product C: total_to_produce should be 0 (sufficient stock)');

        // Product D
        $productD = $op3->advanceOrderProducts()->where('product_id', $this->productD->id)->first();
        $this->assertEquals(48, $productD->ordered_quantity, 'OP #3 Product D: ordered_quantity should be 48');
        $this->assertEquals(16, $productD->ordered_quantity_new, 'OP #3 Product D: ordered_quantity_new should be 16');
        $this->assertEquals(0, $productD->quantity, 'OP #3 Product D: quantity (advance) should be 0');
        $this->assertEquals(16, $productD->total_to_produce, 'OP #3 Product D: total_to_produce should be 16 (no stock)');
    }

    private function validateOp3Inventory(): void
    {
        $this->assertEquals(36, $this->getStock($this->productA), 'OP #3: Product A stock should be 36');
        $this->assertEquals(51, $this->getStock($this->productB), 'OP #3: Product B stock should be 51');
        $this->assertEquals(140, $this->getStock($this->productC), 'OP #3: Product C stock should be 140');
        $this->assertEquals(0, $this->getStock($this->productD), 'OP #3: Product D stock should be 0');
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
        $this->productD = $this->createProduct('Product D');

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
        foreach ([$this->productA, $this->productB, $this->productC, $this->productD] as $product) {
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

    /**
     * Add a product to an AdvanceOrder (simulating Filament RelationManager CreateAction)
     *
     * This mimics what ProductsRelationManager does when creating a new product:
     * 1. Calculate ordered_quantity and ordered_quantity_new from productsData
     * 2. Create AdvanceOrderProduct (triggers Observer which calculates total_to_produce)
     * 3. Observer fires AdvanceOrderProductChanged event
     * 4. Listener syncs pivots for this product
     */
    private function addProductToOp(
        AdvanceOrder $advanceOrder,
        Product $product,
        $productsData,
        AdvanceOrderRepository $advanceOrderRepo,
        int $advanceQuantity
    ): void {
        // Get product data (same as RelationManager mutateFormDataUsing)
        $productData = $productsData->firstWhere('product_id', $product->id);
        $currentOrderedQuantity = $productData['ordered_quantity'] ?? 0;

        // Get max ordered quantity from previous advance orders
        $previousAdvanceOrders = $advanceOrderRepo->getPreviousAdvanceOrdersWithSameDates($advanceOrder);
        $maxPreviousQuantity = $advanceOrderRepo->getMaxOrderedQuantityForProduct(
            $product->id,
            $previousAdvanceOrders,
            $advanceOrder
        );

        // Create AdvanceOrderProduct (this triggers Observer → AdvanceOrderProductChanged event → pivot sync)
        AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $product->id,
            'ordered_quantity' => $currentOrderedQuantity,
            'ordered_quantity_new' => max(0, $currentOrderedQuantity - $maxPreviousQuantity),
            'quantity' => $advanceQuantity,
            // total_to_produce calculated by Observer
        ]);
    }

    private function applyAdvance(AdvanceOrder $op, Product $product, int $advanceQuantity): void
    {
        $advanceOrderProduct = $op->advanceOrderProducts()
            ->where('product_id', $product->id)
            ->first();

        $advanceOrderProduct->quantity = $advanceQuantity;
        $advanceOrderProduct->save(); // Triggers Observer
        $advanceOrderProduct->refresh();
    }

    private function executeOp(AdvanceOrder $op): void
    {
        $this->actingAs($this->user);
        $op->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op));
    }

    private function getStock(Product $product): int
    {
        return $this->warehouseRepository->getProductStockInWarehouse(
            $product->id,
            $this->warehouse->id
        );
    }
}
