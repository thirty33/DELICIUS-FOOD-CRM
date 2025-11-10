<?php

namespace Tests\Unit;

use App\Enums\AdvanceOrderStatus;
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
use Tests\TestCase;

/**
 * Complete AdvanceOrder Inventory Flow Test
 *
 * This test validates the complete production order flow across 7 moments:
 * - Moment 1: Create initial 5 orders (A-E) with different states
 * - Moment 2: Create OP #1 with partially scheduled orders
 * - Moment 3: Update orders A, C, E
 * - Moment 4: Create new orders F, G
 * - Moment 5: Create OP #2 spanning two dispatch dates
 * - Moment 6: Update orders A, D, G to PROCESSED
 * - Moment 7: Create OP #3 without advances
 *
 * Validates:
 * - Pivot synchronization (advance_order_orders, advance_order_order_lines)
 * - Correct calculations (ordered_quantity, ordered_quantity_new, total_to_produce)
 * - Warehouse stock updates after OP execution
 * - Partially scheduled order handling
 */
class CompleteAdvanceOrderInventoryFlowTest extends TestCase
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

        $op1 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id, $this->orderB->id],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // Apply advances (simulating Filament user input)
        $this->applyAdvance($op1, $this->productA, 30);
        $this->applyAdvance($op1, $this->productB, 40);
        $this->applyAdvance($op1, $this->productC, 100);

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

        $op2 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id, $this->orderB->id, $this->orderC->id, $this->orderE->id, $this->orderF->id],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // Apply advances
        $this->applyAdvance($op2, $this->productA, 70);
        $this->applyAdvance($op2, $this->productB, 110);
        $this->applyAdvance($op2, $this->productC, 220);
        // Product D: no advance (0)

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

        $op3 = $this->orderRepository->createAdvanceOrderFromOrders(
            [
                $this->orderA->id,
                $this->orderB->id,
                $this->orderC->id,
                $this->orderD->id,
                $this->orderE->id,
                $this->orderF->id,
                $this->orderG->id,
            ],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // No advances applied

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
