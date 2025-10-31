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
use App\Repositories\WarehouseRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test for Advance Order Production Logic - ETAPA 1
 *
 * This test validates the production order scenario where:
 * 1. Initial inventory: 10 units
 * 2. Order quantity: 4 units
 * 3. Advance quantity: 11 units
 * 4. Expected total to produce: 1 unit
 * 5. Expected final stock: 7 units (10 + 1 - 4)
 */
class AdvanceOrderProductionTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;
    protected Product $product;
    protected Category $category;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user for transactions
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Use existing default warehouse from migration
        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();

        // Create category
        $this->category = Category::create([
            'name' => 'ENSALADAS',
            'code' => 'ENS',
            'description' => 'Ensaladas frescas',
            'active' => true,
        ]);

        // Create product: ENS - ENSALADA DELICIUS CRUJIENTE
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
    }

    /**
     * Test ETAPA 1: Complete production order flow
     *
     * SCENARIO (from spreadsheet):
     * - Initial Inventory: 10
     * - Order with 4 units (date: 2025-11-06)
     * - Production order covering that date
     * - Advance quantity: 11
     *
     * EXPECTED RESULTS:
     * - Total to produce: 1 (calculated: MAX(0, 11 - 10) = 1)
     * - Final stock: 7 (formula: initial + total_to_produce - ordered_quantity_new = 10 + 1 - 4 = 7)
     */
    public function test_etapa_1_production_order_with_advance_creates_warehouse_transaction_and_updates_stock(): void
    {
        // ==================== STEP 1: Set Initial Inventory to 10 ====================
        $warehouseRepository = new WarehouseRepository();

        // Update stock in existing warehouse-product association (from migration)
        $warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            10
        );

        $initialStock = $warehouseRepository->getProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id
        );
        $this->assertEquals(10, $initialStock, 'Initial inventory should be 10');

        // ==================== STEP 2: Create Order with 4 units ====================
        $order = Order::create([
            'user_id' => $this->user->id,
            'date' => '2025-11-06',
            'status' => 'PENDING',
            'total' => 20000,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 4,
            'unit_price' => 5000,
            'total_price' => 20000,
        ]);

        // ==================== STEP 3: Create Production Order ====================
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-05 00:00:00',
            'initial_dispatch_date' => '2025-11-06',
            'final_dispatch_date' => '2025-11-06',
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'ETAPA 1 - Production Order Test',
        ]);

        // ==================== STEP 4: Associate Product with Advance Quantity 11 ====================
        $advanceOrderProduct = AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product->id,
            'quantity' => 11, // Adelantar (Advance quantity)
            'ordered_quantity' => 4, // Total in orders (VENTARIO)
            'ordered_quantity_new' => 4, // New orders quantity
        ]);

        // Calculate and save total_to_produce
        $totalToProduce = $advanceOrderProduct->calculateTotalToProduce();
        $advanceOrderProduct->update(['total_to_produce' => $totalToProduce]);

        // ==================== STEP 5: Validate Total to Produce ====================
        // Formula: MAX(0, quantity - initial_inventory) = MAX(0, 11 - 10) = 1
        $this->assertEquals(1, $totalToProduce, 'Total to produce should be 1');

        // ==================== STEP 6: Execute Production Order ====================
        $advanceOrder->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($advanceOrder));

        // Refresh to get latest data
        $advanceOrder->refresh();
        $advanceOrderProduct->refresh();

        // ==================== STEP 7: Validate Warehouse Transaction Created ====================
        $transaction = WarehouseTransaction::where('advance_order_id', $advanceOrder->id)->first();

        $this->assertNotNull($transaction, 'Warehouse transaction should be created');
        $this->assertEquals(WarehouseTransactionStatus::EXECUTED, $transaction->status, 'Transaction should be executed');
        $this->assertEquals($this->warehouse->id, $transaction->warehouse_id, 'Transaction should be in default warehouse');
        $this->assertStringContainsString("Orden de Producción #{$advanceOrder->id}", $transaction->reason);

        // ==================== STEP 8: Validate Transaction Line ====================
        $transactionLine = $transaction->lines()->where('product_id', $this->product->id)->first();

        $this->assertNotNull($transactionLine, 'Transaction line for product should exist');
        $this->assertEquals(10, $transactionLine->stock_before, 'Stock before should be 10');

        // Expected final stock: initial + total_to_produce - ordered_quantity_new
        // = 10 + 1 - 4 = 7
        $expectedFinalStock = 7;
        $this->assertEquals($expectedFinalStock, $transactionLine->stock_after, 'Stock after should be 7');
        $this->assertEquals($expectedFinalStock - 10, $transactionLine->difference, 'Difference should be -3');

        // ==================== STEP 9: Validate Actual Warehouse Stock ====================
        $finalStock = $warehouseRepository->getProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id
        );

        $this->assertEquals($expectedFinalStock, $finalStock, 'Final warehouse stock should be 7');

        // ==================== SUMMARY ====================
        $this->assertTrue(true, sprintf(
            "ETAPA 1 Test PASSED:\n" .
            "  Initial Inventory: %d\n" .
            "  Order Quantity: %d\n" .
            "  Advance Quantity: %d\n" .
            "  Total to Produce: %d (Expected: 1)\n" .
            "  Final Stock: %d (Expected: 7)\n" .
            "  Formula: %d (initial) + %d (produced) - %d (ordered_new) = %d",
            $initialStock,
            $advanceOrderProduct->ordered_quantity,
            $advanceOrderProduct->quantity,
            $totalToProduce,
            $finalStock,
            $initialStock,
            $totalToProduce,
            $advanceOrderProduct->ordered_quantity_new,
            $finalStock
        ));
    }

    /**
     * Test ETAPA 1 with CANCELLATION: Complete production order flow and then cancel it
     *
     * SCENARIO (from spreadsheet):
     * - Initial Inventory: 10
     * - Order with 4 units (date: 2025-11-06)
     * - Production order covering that date
     * - Advance quantity: 11
     * - Execute the order (stock becomes 7)
     * - Cancel the order (stock should return to 10)
     *
     * EXPECTED RESULTS AFTER CANCELLATION:
     * - Warehouse transaction should be CANCELLED
     * - Stock should return to initial value: 10
     */
    public function test_etapa_1_production_order_execution_and_cancellation_reverts_stock(): void
    {
        // ==================== STEP 1: Set Initial Inventory to 10 ====================
        $warehouseRepository = new WarehouseRepository();

        // Update stock in existing warehouse-product association (from migration)
        $warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            10
        );

        $initialStock = $warehouseRepository->getProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id
        );
        $this->assertEquals(10, $initialStock, 'Initial inventory should be 10');

        // ==================== STEP 2: Create Order with 4 units ====================
        $order = Order::create([
            'user_id' => $this->user->id,
            'date' => '2025-11-06',
            'status' => 'PENDING',
            'total' => 20000,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 4,
            'unit_price' => 5000,
            'total_price' => 20000,
        ]);

        // ==================== STEP 3: Create Production Order ====================
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-05 00:00:00',
            'initial_dispatch_date' => '2025-11-06',
            'final_dispatch_date' => '2025-11-06',
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'ETAPA 1 - Production Order Cancellation Test',
        ]);

        // ==================== STEP 4: Associate Product with Advance Quantity 11 ====================
        $advanceOrderProduct = AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product->id,
            'quantity' => 11, // Adelantar (Advance quantity)
            'ordered_quantity' => 4, // Total in orders (VENTARIO)
            'ordered_quantity_new' => 4, // New orders quantity
        ]);

        // Calculate and save total_to_produce
        $totalToProduce = $advanceOrderProduct->calculateTotalToProduce();
        $advanceOrderProduct->update(['total_to_produce' => $totalToProduce]);

        $this->assertEquals(1, $totalToProduce, 'Total to produce should be 1');

        // ==================== STEP 5: Execute Production Order ====================
        $advanceOrder->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new \App\Events\AdvanceOrderExecuted($advanceOrder));

        // Refresh to get latest data
        $advanceOrder->refresh();

        // Validate transaction was created and executed
        $transaction = WarehouseTransaction::where('advance_order_id', $advanceOrder->id)->first();
        $this->assertNotNull($transaction, 'Warehouse transaction should be created');
        $this->assertEquals(WarehouseTransactionStatus::EXECUTED, $transaction->status, 'Transaction should be executed');

        // Validate stock after execution
        $stockAfterExecution = $warehouseRepository->getProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id
        );
        $this->assertEquals(7, $stockAfterExecution, 'Stock after execution should be 7');

        // ==================== STEP 6: Cancel Production Order ====================
        $advanceOrder->update(['status' => AdvanceOrderStatus::CANCELLED]);
        event(new \App\Events\AdvanceOrderCancelled($advanceOrder));

        // Refresh to get latest data
        $advanceOrder->refresh();
        $transaction->refresh();

        // ==================== STEP 7: Validate Transaction is Cancelled ====================
        $this->assertEquals(AdvanceOrderStatus::CANCELLED, $advanceOrder->status, 'Order should be cancelled');
        $this->assertEquals(WarehouseTransactionStatus::CANCELLED, $transaction->status, 'Transaction should be cancelled');
        $this->assertNotNull($transaction->cancelled_at, 'Transaction should have cancellation timestamp');
        $this->assertNotNull($transaction->cancelled_by, 'Transaction should have cancelled_by user');
        $this->assertStringContainsString("Cancelación de Orden de Producción #{$advanceOrder->id}", $transaction->cancellation_reason);

        // ==================== STEP 8: Validate Stock Returned to Initial Value ====================
        $finalStock = $warehouseRepository->getProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id
        );
        $this->assertEquals($initialStock, $finalStock, 'Stock should return to initial value (10) after cancellation');

        // ==================== SUMMARY ====================
        $this->assertTrue(true, sprintf(
            "ETAPA 1 CANCELLATION Test PASSED:\n" .
            "  Initial Inventory: %d\n" .
            "  Stock After Execution: %d\n" .
            "  Stock After Cancellation: %d\n" .
            "  Transaction Status: %s\n" .
            "  Order Status: %s",
            $initialStock,
            $stockAfterExecution,
            $finalStock,
            $transaction->status->value,
            $advanceOrder->status->value
        ));
    }
}
