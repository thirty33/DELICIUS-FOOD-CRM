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
 * Test for Three Consecutive Production Orders (3 ETAPAS)
 *
 * Validates three consecutive production orders for:
 * ENS - ENSALADA DELICIUS CRUJIENTE
 *
 * All orders share same preparation_datetime and dispatch date range
 */
class AdvanceOrderThreeStagesTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;
    protected Product $product;
    protected Category $category;
    protected User $user;
    protected WarehouseRepository $warehouseRepository;
    protected AdvanceOrderRepository $advanceOrderRepository;

    // Shared dates for all three stages
    protected string $preparationDate = '2025-11-05 00:00:00';
    protected string $dispatchDate = '2025-11-06';

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
    protected function createOrder(int $quantity, int $unitPrice = 5000): Order
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'date' => $this->dispatchDate,
            'status' => 'PENDING',
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
     */
    protected function createAndExecuteProductionOrder(
        string $description,
        int $advanceQuantity,
        int $orderedQuantity,
        int $orderedQuantityNew
    ): array {
        // Create production order
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => $this->preparationDate,
            'initial_dispatch_date' => $this->dispatchDate,
            'final_dispatch_date' => $this->dispatchDate,
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => $description,
        ]);

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
     * Test 3 ETAPAS for ENS - ENSALADA DELICIUS CRUJIENTE
     *
     * ETAPA 1: Initial=10, NewOrders=4, Advance=11, ToProduce=1, Final=7
     * ETAPA 2: Initial=7, NewOrders=16, Advance=0, ToProduce=9, Final=0
     * ETAPA 3: Initial=0, NewOrders=0, Advance=0, ToProduce=0, Final=0
     */
    public function test_three_consecutive_production_orders_for_ensalada_delicius_crujiente(): void
    {
        // ==================== SETUP: Initial Stock = 10 ====================
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            10
        );

        $initialStock = $this->getCurrentStock();
        $this->assertEquals(10, $initialStock, 'Initial inventory should be 10');

        // ==================== ETAPA 1 ====================
        // Create order with 4 units (new orders)
        $this->createOrder(4);

        // Create and execute production order
        $stage1 = $this->createAndExecuteProductionOrder(
            'ETAPA 1',
            11, // advance quantity
            4,  // ordered quantity
            4   // ordered quantity new
        );

        // Validate ETAPA 1
        $this->assertEquals(4, $stage1['product']->ordered_quantity_new, 'ETAPA 1: New orders should be 4');
        $this->assertEquals(1, $stage1['total_to_produce'], 'ETAPA 1: Total to produce should be 1');

        $stockAfterStage1 = $this->getCurrentStock();
        $this->assertEquals(7, $stockAfterStage1, 'ETAPA 1: Final stock should be 7');

        $this->assertNotNull($stage1['transaction'], 'ETAPA 1: Transaction should be created');
        $this->assertEquals(WarehouseTransactionStatus::EXECUTED, $stage1['transaction']->status);

        // Validate transaction line
        $transactionLine1 = $stage1['transaction']->lines()->where('product_id', $this->product->id)->first();
        $this->assertEquals(10, $transactionLine1->stock_before, 'ETAPA 1: Stock before should be 10');
        $this->assertEquals(7, $transactionLine1->stock_after, 'ETAPA 1: Stock after should be 7');

        // ==================== ETAPA 2 ====================
        // Create order with 16 units (new orders)
        $this->createOrder(16);

        $totalOrders2 = 20; // 4 + 16

        // Create and execute production order
        $stage2 = $this->createAndExecuteProductionOrder(
            'ETAPA 2',
            0,              // advance quantity
            $totalOrders2,  // ordered quantity
            16              // ordered quantity new (new orders in this stage)
        );

        // Validate ETAPA 2
        $this->assertEquals(16, $stage2['product']->ordered_quantity_new, 'ETAPA 2: New orders should be 16');
        $this->assertEquals(9, $stage2['total_to_produce'], 'ETAPA 2: Total to produce should be 9');

        $stockAfterStage2 = $this->getCurrentStock();
        $this->assertEquals(0, $stockAfterStage2, 'ETAPA 2: Final stock should be 0');

        $this->assertNotNull($stage2['transaction'], 'ETAPA 2: Transaction should be created');
        $this->assertEquals(WarehouseTransactionStatus::EXECUTED, $stage2['transaction']->status);

        // Validate transaction line
        $transactionLine2 = $stage2['transaction']->lines()->where('product_id', $this->product->id)->first();
        $this->assertEquals(7, $transactionLine2->stock_before, 'ETAPA 2: Stock before should be 7');
        $this->assertEquals(0, $transactionLine2->stock_after, 'ETAPA 2: Stock after should be 0');

        // ==================== ETAPA 3 ====================
        // No new orders for stage 3

        $totalOrders3 = 20; // No new orders, same as before

        // Create and execute production order
        $stage3 = $this->createAndExecuteProductionOrder(
            'ETAPA 3',
            0,              // advance quantity
            $totalOrders3,  // ordered quantity
            0               // ordered quantity new (no new orders in this stage)
        );

        // Validate ETAPA 3
        $this->assertEquals(0, $stage3['product']->ordered_quantity_new, 'ETAPA 3: New orders should be 0');
        $this->assertEquals(0, $stage3['total_to_produce'], 'ETAPA 3: Total to produce should be 0');

        $stockAfterStage3 = $this->getCurrentStock();
        $this->assertEquals(0, $stockAfterStage3, 'ETAPA 3: Final stock should be 0');

        $this->assertNotNull($stage3['transaction'], 'ETAPA 3: Transaction should be created');
        $this->assertEquals(WarehouseTransactionStatus::EXECUTED, $stage3['transaction']->status);

        // Validate transaction line
        $transactionLine3 = $stage3['transaction']->lines()->where('product_id', $this->product->id)->first();
        $this->assertEquals(0, $transactionLine3->stock_before, 'ETAPA 3: Stock before should be 0');
        $this->assertEquals(0, $transactionLine3->stock_after, 'ETAPA 3: Stock after should be 0');

        // ==================== SUMMARY ====================
        $this->assertTrue(true, sprintf(
            "THREE STAGES Test PASSED:\n\n" .
            "ETAPA 1:\n" .
            "  Initial Stock: %d\n" .
            "  New Orders: %d\n" .
            "  Advance: %d\n" .
            "  To Produce: %d\n" .
            "  Final Stock: %d\n\n" .
            "ETAPA 2:\n" .
            "  Initial Stock: %d\n" .
            "  New Orders: %d\n" .
            "  Advance: %d\n" .
            "  To Produce: %d\n" .
            "  Final Stock: %d\n\n" .
            "ETAPA 3:\n" .
            "  Initial Stock: %d\n" .
            "  New Orders: %d\n" .
            "  Advance: %d\n" .
            "  To Produce: %d\n" .
            "  Final Stock: %d",
            10,
            4,
            11,
            $stage1['total_to_produce'],
            7,
            7,
            16,
            0,
            $stage2['total_to_produce'],
            0,
            0,
            0,
            0,
            $stage3['total_to_produce'],
            0
        ));
    }
}
