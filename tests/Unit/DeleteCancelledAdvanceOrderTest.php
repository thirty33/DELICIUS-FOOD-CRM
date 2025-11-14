<?php

namespace Tests\Unit;

use App\Enums\AdvanceOrderStatus;
use App\Events\AdvanceOrderExecuted;
use App\Models\AdvanceOrder;
use App\Models\AdvanceOrderProduct;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Category;
use App\Models\User;
use App\Repositories\AdvanceOrderRepository;
use App\Repositories\OrderRepository;
use App\Repositories\WarehouseRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TDD Test - Delete Cancelled Production Orders with All Related Records
 *
 * REQUIREMENTS:
 * 1. Only CANCELLED production orders can be deleted
 * 2. When deleting, ALL related records must be removed:
 *    - advance_order_products
 *    - advance_order_orders (pivot)
 *    - advance_order_order_lines (pivot)
 *    - advance_order itself
 *
 * TEST SCENARIOS:
 * 1. Cannot delete PENDING production order
 * 2. Cannot delete EXECUTED production order
 * 3. Can delete CANCELLED production order
 * 4. Deleting removes all pivot records
 */
class DeleteCancelledAdvanceOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;
    protected Product $product;
    protected Category $category;
    protected User $user;
    protected WarehouseRepository $warehouseRepository;
    protected AdvanceOrderRepository $advanceOrderRepository;
    protected OrderRepository $orderRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();

        $this->category = Category::create([
            'name' => 'TEST CATEGORY',
            'code' => 'TST',
            'description' => 'Test category',
            'active' => true,
        ]);

        $this->product = Product::create([
            'code' => 'TST-001',
            'name' => 'TEST PRODUCT',
            'description' => 'Test product for deletion tests',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $this->warehouseRepository = new WarehouseRepository();
        $this->advanceOrderRepository = app(\App\Repositories\AdvanceOrderRepository::class);
        $this->orderRepository = new OrderRepository();
    }

    /**
     * Test: Cannot delete PENDING production order
     */
    public function test_cannot_delete_pending_production_order(): void
    {
        // Create customer order
        $order = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-12-01',
            'status' => 'PROCESSED',
            'total' => 10000,
        ]);
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'unit_price' => 1000,
            'total_price' => 10000,
        ]);

        // Create PENDING production order
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-30 10:00:00',
            'initial_dispatch_date' => '2025-12-01',
            'final_dispatch_date' => '2025-12-01',
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'Test PENDING order',
        ]);

        AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'ordered_quantity' => 10,
            'ordered_quantity_new' => 10,
        ]);

        // Verify cannot delete
        $this->assertFalse(
            $this->advanceOrderRepository->canDeleteAdvanceOrder($advanceOrder),
            'PENDING production order should NOT be deletable'
        );

        // Attempt to delete should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot delete advance order. Only CANCELLED orders can be deleted.');
        $this->advanceOrderRepository->deleteAdvanceOrder($advanceOrder);
    }

    /**
     * Test: Cannot delete EXECUTED production order
     */
    public function test_cannot_delete_executed_production_order(): void
    {
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            0
        );

        // Create customer order
        $order = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-12-01',
            'status' => 'PROCESSED',
            'total' => 10000,
        ]);
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'unit_price' => 1000,
            'total_price' => 10000,
        ]);

        // Create and EXECUTE production order
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-30 10:00:00',
            'initial_dispatch_date' => '2025-12-01',
            'final_dispatch_date' => '2025-12-01',
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'Test EXECUTED order',
        ]);

        AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'ordered_quantity' => 10,
            'ordered_quantity_new' => 10,
        ]);

        // Execute the order
        $advanceOrder->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($advanceOrder));

        // Verify cannot delete
        $this->assertFalse(
            $this->advanceOrderRepository->canDeleteAdvanceOrder($advanceOrder),
            'EXECUTED production order should NOT be deletable'
        );

        // Attempt to delete should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot delete advance order. Only CANCELLED orders can be deleted.');
        $this->advanceOrderRepository->deleteAdvanceOrder($advanceOrder);
    }

    /**
     * Test: Can delete CANCELLED production order and all related records
     */
    public function test_can_delete_cancelled_production_order_with_all_pivots(): void
    {
        // Create customer orders
        $order1 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-12-01',
            'status' => 'PROCESSED',
            'total' => 5000,
        ]);
        OrderLine::create([
            'order_id' => $order1->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 1000,
            'total_price' => 5000,
        ]);

        $order2 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-12-01',
            'status' => 'PROCESSED',
            'total' => 3000,
        ]);
        OrderLine::create([
            'order_id' => $order2->id,
            'product_id' => $this->product->id,
            'quantity' => 3,
            'unit_price' => 1000,
            'total_price' => 3000,
        ]);

        // Create production order
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-30 10:00:00',
            'initial_dispatch_date' => '2025-12-01',
            'final_dispatch_date' => '2025-12-01',
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'Test order to be cancelled and deleted',
        ]);

        AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product->id,
            'quantity' => 8,
            'ordered_quantity' => 8,
            'ordered_quantity_new' => 8,
        ]);

        // Pivots are created by events/listeners
        // Verify pivots were created
        $this->assertGreaterThan(
            0,
            DB::table('advance_order_orders')->where('advance_order_id', $advanceOrder->id)->count(),
            'Pivot advance_order_orders should have records'
        );
        $this->assertGreaterThan(
            0,
            DB::table('advance_order_order_lines')->where('advance_order_id', $advanceOrder->id)->count(),
            'Pivot advance_order_order_lines should have records'
        );

        $ordersPivotCount = DB::table('advance_order_orders')->where('advance_order_id', $advanceOrder->id)->count();
        $linesPivotCount = DB::table('advance_order_order_lines')->where('advance_order_id', $advanceOrder->id)->count();
        $productsCount = AdvanceOrderProduct::where('advance_order_id', $advanceOrder->id)->count();

        $this->assertEquals(2, $ordersPivotCount, 'Should have 2 orders in pivot');
        $this->assertEquals(2, $linesPivotCount, 'Should have 2 order lines in pivot');
        $this->assertEquals(1, $productsCount, 'Should have 1 product');

        // Cancel the order
        $advanceOrder->update(['status' => AdvanceOrderStatus::CANCELLED]);
        $advanceOrder->refresh();

        // Verify can delete
        $this->assertTrue(
            $this->advanceOrderRepository->canDeleteAdvanceOrder($advanceOrder),
            'CANCELLED production order should be deletable'
        );

        // Delete the order
        $result = $this->advanceOrderRepository->deleteAdvanceOrder($advanceOrder);
        $this->assertTrue($result, 'deleteAdvanceOrder should return true');

        // Verify all related records were deleted
        $this->assertEquals(
            0,
            DB::table('advance_order_orders')->where('advance_order_id', $advanceOrder->id)->count(),
            'All records in advance_order_orders pivot should be deleted'
        );
        $this->assertEquals(
            0,
            DB::table('advance_order_order_lines')->where('advance_order_id', $advanceOrder->id)->count(),
            'All records in advance_order_order_lines pivot should be deleted'
        );
        $this->assertEquals(
            0,
            AdvanceOrderProduct::where('advance_order_id', $advanceOrder->id)->count(),
            'All advance_order_products should be deleted'
        );
        $this->assertNull(
            AdvanceOrder::find($advanceOrder->id),
            'The advance_order itself should be deleted'
        );
    }

    /**
     * Test: Deleting cancelled OP does not affect other OPs
     */
    public function test_deleting_cancelled_op_does_not_affect_other_ops(): void
    {
        // Create customer order
        $order = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-12-01',
            'status' => 'PROCESSED',
            'total' => 10000,
        ]);
        $orderLine = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'unit_price' => 1000,
            'total_price' => 10000,
        ]);

        // Create OP #1 (to be cancelled and deleted)
        $op1 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-30 10:00:00',
            'initial_dispatch_date' => '2025-12-01',
            'final_dispatch_date' => '2025-12-01',
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #1 - To be deleted',
        ]);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op1->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'ordered_quantity' => 10,
            'ordered_quantity_new' => 10,
        ]);

        // Create OP #2 (should remain unaffected)
        $op2 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-30 11:00:00',
            'initial_dispatch_date' => '2025-12-01',
            'final_dispatch_date' => '2025-12-01',
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #2 - Should remain',
        ]);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op2->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'ordered_quantity' => 10,
            'ordered_quantity_new' => 0,
        ]);

        // Get pivot counts before deletion
        $op1OrdersPivot = DB::table('advance_order_orders')->where('advance_order_id', $op1->id)->count();
        $op1LinesPivot = DB::table('advance_order_order_lines')->where('advance_order_id', $op1->id)->count();
        $op2OrdersPivot = DB::table('advance_order_orders')->where('advance_order_id', $op2->id)->count();
        $op2LinesPivot = DB::table('advance_order_order_lines')->where('advance_order_id', $op2->id)->count();

        $this->assertGreaterThan(0, $op1OrdersPivot, 'OP #1 should have pivot orders');
        $this->assertGreaterThan(0, $op1LinesPivot, 'OP #1 should have pivot lines');
        $this->assertGreaterThan(0, $op2OrdersPivot, 'OP #2 should have pivot orders');
        $this->assertGreaterThan(0, $op2LinesPivot, 'OP #2 should have pivot lines');

        // Cancel and delete OP #1
        $op1->update(['status' => AdvanceOrderStatus::CANCELLED]);
        $this->advanceOrderRepository->deleteAdvanceOrder($op1);

        // Verify OP #1 was deleted completely
        $this->assertNull(AdvanceOrder::find($op1->id), 'OP #1 should be deleted');
        $this->assertEquals(
            0,
            DB::table('advance_order_orders')->where('advance_order_id', $op1->id)->count(),
            'OP #1 pivot orders should be deleted'
        );
        $this->assertEquals(
            0,
            DB::table('advance_order_order_lines')->where('advance_order_id', $op1->id)->count(),
            'OP #1 pivot lines should be deleted'
        );

        // Verify OP #2 remains unaffected
        $this->assertNotNull(AdvanceOrder::find($op2->id), 'OP #2 should still exist');
        $this->assertEquals(
            $op2OrdersPivot,
            DB::table('advance_order_orders')->where('advance_order_id', $op2->id)->count(),
            'OP #2 pivot orders should remain unchanged'
        );
        $this->assertEquals(
            $op2LinesPivot,
            DB::table('advance_order_order_lines')->where('advance_order_id', $op2->id)->count(),
            'OP #2 pivot lines should remain unchanged'
        );
        $this->assertEquals(
            1,
            AdvanceOrderProduct::where('advance_order_id', $op2->id)->count(),
            'OP #2 products should remain unchanged'
        );
    }
}