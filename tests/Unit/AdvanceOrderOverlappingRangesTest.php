<?php

namespace Tests\Unit;

use App\Enums\AdvanceOrderStatus;
use App\Enums\WarehouseTransactionStatus;
use App\Events\AdvanceOrderExecuted;
use App\Models\AdvanceOrder;
use App\Models\AdvanceOrderProduct;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseTransaction;
use App\Models\Category;
use App\Models\User;
use App\Repositories\AdvanceOrderRepository;
use App\Repositories\WarehouseRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TDD Test - Three Stages with Overlapping Dispatch Ranges
 *
 * SCENARIO:
 * Three production orders with:
 * - DIFFERENT preparation dates (2025-11-08, 2025-11-09, 2025-11-10)
 * - DIFFERENT dispatch ranges (overlapping but not identical)
 * - Orders created with dispatch dates that intersect all three ranges
 *
 * EXPECTED BEHAVIOR:
 * Each production order should correctly identify previous orders with overlapping
 * dispatch ranges and calculate ordered_quantity_new by subtracting the maximum
 * ordered_quantity from previous overlapping orders.
 *
 * CURRENT BUG (why test will FAIL):
 * getPreviousAdvanceOrdersWithSameDates() only finds orders with EXACT same dates
 * (preparation_datetime, initial_dispatch_date, final_dispatch_date).
 * It doesn't detect overlapping ranges.
 *
 * TEST DATA:
 * - ETAPA 1: Prep=2025-11-08, Range=[2025-11-10 to 2025-11-12], Orders=4
 * - ETAPA 2: Prep=2025-11-09, Range=[2025-11-11 to 2025-11-14], Orders=20 (4+16 new)
 * - ETAPA 3: Prep=2025-11-10, Range=[2025-11-12 to 2025-11-15], Orders=35 (20+15 new)
 *
 * Overlaps:
 * - ETAPA 2 overlaps ETAPA 1 on [2025-11-11, 2025-11-12]
 * - ETAPA 3 overlaps ETAPA 2 on [2025-11-12, 2025-11-13, 2025-11-14]
 * - ETAPA 3 overlaps ETAPA 1 on [2025-11-12]
 */
class AdvanceOrderOverlappingRangesTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;
    protected Product $product;
    protected Category $category;
    protected User $user;
    protected WarehouseRepository $warehouseRepository;
    protected AdvanceOrderRepository $advanceOrderRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();

        $this->category = Category::create([
            'name' => 'ENSALADAS',
            'code' => 'ENS',
            'description' => 'Ensaladas frescas',
            'active' => true,
        ]);

        $this->product = Product::create([
            'code' => 'ENS001',
            'name' => 'ENSALADA DELICIUS CRUJIENTE',
            'description' => 'Ensalada fresca y crujiente',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $this->warehouseRepository = new WarehouseRepository();
        $this->advanceOrderRepository = new AdvanceOrderRepository();
    }

    /**
     * Helper: Create an order with order lines
     */
    protected function createOrder(int $quantity, string $dispatchDate, int $unitPrice = 5000): Order
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => $dispatchDate,
            'status' => 'PROCESSED',  // Use PROCESSED so it's included in production orders
            'total' => $quantity * $unitPrice,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $quantity * $unitPrice,
        ]);

        return $order;
    }

    /**
     * Helper: Create and execute a production order
     *
     * This simulates the REAL system behavior: calculates ordered_quantity_new
     * using the repository's getPreviousAdvanceOrdersWithSameDates() method.
     */
    protected function createAndExecuteProductionOrder(
        string $description,
        string $preparationDatetime,
        string $initialDispatchDate,
        string $finalDispatchDate,
        int $advanceQuantity
    ): array {
        // Create production order
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => $preparationDatetime,
            'initial_dispatch_date' => $initialDispatchDate,
            'final_dispatch_date' => $finalDispatchDate,
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => $description,
        ]);

        // Calculate ordered_quantity using repository (simulates real system)
        $orderRepository = new \App\Repositories\OrderRepository();
        $productsData = $orderRepository->getProductsFromOrdersInDateRange(
            $initialDispatchDate,
            $finalDispatchDate
        );

        // Find this product's data
        $productData = $productsData->firstWhere('product_id', $this->product->id);
        $orderedQuantity = $productData['ordered_quantity'] ?? 0;

        // Calculate ordered_quantity_new using repository (THIS IS WHAT WE'RE TESTING)
        $previousAdvanceOrders = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($advanceOrder);
        $maxPreviousQuantity = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $this->product->id,
            $previousAdvanceOrders
        );
        $orderedQuantityNew = max(0, $orderedQuantity - $maxPreviousQuantity);

        // Associate product
        $advanceOrderProduct = AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product->id,
            'quantity' => $advanceQuantity,
            'ordered_quantity' => $orderedQuantity,
            'ordered_quantity_new' => $orderedQuantityNew,
        ]);

        // Calculate total to produce
        $totalToProduce = $advanceOrderProduct->calculateTotalToProduce();
        $advanceOrderProduct->update(['total_to_produce' => $totalToProduce]);

        // Execute
        $advanceOrder->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($advanceOrder));

        $advanceOrder->refresh();
        $advanceOrderProduct->refresh();

        // Get transaction
        $transaction = WarehouseTransaction::where('advance_order_id', $advanceOrder->id)->first();

        return [
            'order' => $advanceOrder,
            'product' => $advanceOrderProduct,
            'transaction' => $transaction,
            'total_to_produce' => $totalToProduce,
        ];
    }

    /**
     * Helper: Get current stock
     */
    protected function getCurrentStock(): int
    {
        return $this->warehouseRepository->getProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id
        );
    }

    /**
     * Test: Three production orders with overlapping dispatch ranges
     *
     * Timeline:
     *        Lun-10    Mar-11    Mié-12    Jue-13    Vie-14    Sáb-15
     * ETAPA 1: [=========== 4 und ===========]
     * ETAPA 2:          [=========== 20 und (4+16) ===========]
     * ETAPA 3:                      [=========== 35 und (4+16+15) ===========]
     *
     * Order 1 (4 und):                ↑ dispatch_date = Mié-12
     * Order 2 (16 und):                         ↑ dispatch_date = Jue-13
     * Order 3 (15 und):                                   ↑ dispatch_date = Vie-14
     */
    public function test_three_stages_with_overlapping_dispatch_ranges_different_dates_and_new_orders_in_stage_3(): void
    {
        // ==================== SETUP: Initial Stock = 10 ====================
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            10
        );

        $initialStock = $this->getCurrentStock();
        $this->assertEquals(10, $initialStock, 'Initial inventory should be 10');

        // ==================== ETAPA 1 (Viernes 2025-11-08) ====================
        // Create order: 4 units for dispatch on Miércoles 2025-11-12
        $this->createOrder(4, '2025-11-12');

        // Create and execute production order
        // Prep: 2025-11-08, Range: 2025-11-10 to 2025-11-12
        $stage1 = $this->createAndExecuteProductionOrder(
            'ETAPA 1 - Viernes con rango Lun-Mié',
            '2025-11-08 08:00:00',  // preparation_datetime
            '2025-11-10',            // initial_dispatch_date
            '2025-11-12',            // final_dispatch_date
            11                       // advance quantity
        );

        // Validate ETAPA 1
        $this->assertEquals(4, $stage1['product']->ordered_quantity, 'ETAPA 1: Ordered quantity should be 4');
        $this->assertEquals(4, $stage1['product']->ordered_quantity_new, 'ETAPA 1: New orders should be 4');
        $this->assertEquals(1, $stage1['total_to_produce'], 'ETAPA 1: Total to produce should be 1');

        $stockAfterStage1 = $this->getCurrentStock();
        $this->assertEquals(7, $stockAfterStage1, 'ETAPA 1: Final stock should be 7 (10 + 1 - 4)');

        $this->assertNotNull($stage1['transaction'], 'ETAPA 1: Transaction should be created');
        $this->assertEquals(WarehouseTransactionStatus::EXECUTED, $stage1['transaction']->status);

        // Validate transaction line
        $transactionLine1 = $stage1['transaction']->lines()->where('product_id', $this->product->id)->first();
        $this->assertEquals(10, $transactionLine1->stock_before, 'ETAPA 1: Stock before should be 10');
        $this->assertEquals(7, $transactionLine1->stock_after, 'ETAPA 1: Stock after should be 7');

        // ==================== ETAPA 2 (Sábado 2025-11-09) ====================
        // Create order: 16 units for dispatch on Jueves 2025-11-13
        $this->createOrder(16, '2025-11-13');

        // Create and execute production order
        // Prep: 2025-11-09, Range: 2025-11-11 to 2025-11-14 (OVERLAPS with ETAPA 1 on 11-12)
        $stage2 = $this->createAndExecuteProductionOrder(
            'ETAPA 2 - Sábado con rango Mar-Vie',
            '2025-11-09 08:00:00',  // preparation_datetime (DIFFERENT from ETAPA 1)
            '2025-11-11',            // initial_dispatch_date (DIFFERENT from ETAPA 1)
            '2025-11-14',            // final_dispatch_date (DIFFERENT from ETAPA 1)
            0                        // advance quantity
        );

        // Validate ETAPA 2
        $this->assertEquals(20, $stage2['product']->ordered_quantity, 'ETAPA 2: Ordered quantity should be 20 (4+16)');
        $this->assertEquals(16, $stage2['product']->ordered_quantity_new, 'ETAPA 2: New orders should be 16 (must subtract 4 from ETAPA 1)');
        $this->assertEquals(9, $stage2['total_to_produce'], 'ETAPA 2: Total to produce should be 9');

        $stockAfterStage2 = $this->getCurrentStock();
        $this->assertEquals(0, $stockAfterStage2, 'ETAPA 2: Final stock should be 0 (7 + 9 - 16)');

        $this->assertNotNull($stage2['transaction'], 'ETAPA 2: Transaction should be created');
        $this->assertEquals(WarehouseTransactionStatus::EXECUTED, $stage2['transaction']->status);

        // Validate transaction line
        $transactionLine2 = $stage2['transaction']->lines()->where('product_id', $this->product->id)->first();
        $this->assertEquals(7, $transactionLine2->stock_before, 'ETAPA 2: Stock before should be 7');
        $this->assertEquals(0, $transactionLine2->stock_after, 'ETAPA 2: Stock after should be 0');

        // ==================== ETAPA 3 (Domingo 2025-11-10) ====================
        // Create order: 15 NEW units for dispatch on Viernes 2025-11-14
        $this->createOrder(15, '2025-11-14');

        // Create and execute production order
        // Prep: 2025-11-10, Range: 2025-11-12 to 2025-11-15 (OVERLAPS with ETAPA 1 and ETAPA 2)
        $stage3 = $this->createAndExecuteProductionOrder(
            'ETAPA 3 - Domingo con rango Mié-Sáb y 15 pedidos nuevos',
            '2025-11-10 08:00:00',  // preparation_datetime (DIFFERENT from ETAPA 1 and 2)
            '2025-11-12',            // initial_dispatch_date (DIFFERENT from ETAPA 1 and 2)
            '2025-11-15',            // final_dispatch_date (DIFFERENT from ETAPA 1 and 2)
            18                       // advance quantity (NEW: 18 units to produce)
        );

        // Validate ETAPA 3
        $this->assertEquals(35, $stage3['product']->ordered_quantity, 'ETAPA 3: Ordered quantity should be 35 (4+16+15)');
        $this->assertEquals(15, $stage3['product']->ordered_quantity_new, 'ETAPA 3: New orders should be 15 (must subtract max 20 from ETAPA 2)');
        $this->assertEquals(18, $stage3['total_to_produce'], 'ETAPA 3: Total to produce should be 18 (MAX(0, 18 - 0))');

        $stockAfterStage3 = $this->getCurrentStock();
        $this->assertEquals(3, $stockAfterStage3, 'ETAPA 3: Final stock should be 3 (0 + 18 - 15)');

        $this->assertNotNull($stage3['transaction'], 'ETAPA 3: Transaction should be created');
        $this->assertEquals(WarehouseTransactionStatus::EXECUTED, $stage3['transaction']->status);

        // Validate transaction line
        $transactionLine3 = $stage3['transaction']->lines()->where('product_id', $this->product->id)->first();
        $this->assertEquals(0, $transactionLine3->stock_before, 'ETAPA 3: Stock before should be 0');
        $this->assertEquals(3, $transactionLine3->stock_after, 'ETAPA 3: Stock after should be 3');

        // ==================== SUMMARY ====================
        $this->assertTrue(true, sprintf(
            "THREE STAGES WITH OVERLAPPING RANGES Test PASSED:\n\n" .
            "ETAPA 1 (Prep: 2025-11-08, Range: 10-12):\n" .
            "  - Initial Stock: 10\n" .
            "  - Ordered Quantity: 4\n" .
            "  - Ordered Quantity New: 4\n" .
            "  - Advance: 11\n" .
            "  - Total to Produce: 1\n" .
            "  - Final Stock: 7\n\n" .
            "ETAPA 2 (Prep: 2025-11-09, Range: 11-14, OVERLAPS ETAPA 1):\n" .
            "  - Initial Stock: 7\n" .
            "  - Ordered Quantity: 20 (4 from ETAPA 1 + 16 new)\n" .
            "  - Ordered Quantity New: 16 (detected ETAPA 1 overlap)\n" .
            "  - Advance: 0\n" .
            "  - Total to Produce: 9\n" .
            "  - Final Stock: 0\n\n" .
            "ETAPA 3 (Prep: 2025-11-10, Range: 12-15, OVERLAPS ETAPA 1 & 2):\n" .
            "  - Initial Stock: 0\n" .
            "  - Ordered Quantity: 35 (4 + 16 + 15 new)\n" .
            "  - Ordered Quantity New: 15 (detected ETAPA 2 max overlap)\n" .
            "  - Advance: 18\n" .
            "  - Total to Produce: 18\n" .
            "  - Final Stock: 3\n"
        ));
    }
}