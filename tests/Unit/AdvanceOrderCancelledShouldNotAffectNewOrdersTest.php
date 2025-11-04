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
use Tests\TestCase;

/**
 * TDD Test - Production Bug: Cancelled OP Should Not Affect New Orders
 *
 * PRODUCTION SCENARIO (Real data from production):
 *
 * CUSTOMER ORDERS:
 * - 10 orders with product "SANDWICH MARRAQUETA" for dispatch date 2025-11-03
 * - Total quantity: 62 units
 *
 * PRODUCTION ORDERS:
 * - Production Order #7: Range [2025-11-03 to 2025-11-03] - CREATED
 *   - ordered_quantity = 62
 *   - ordered_quantity_new = 62
 *   - Status: CANCELLED (user cancelled this OP)
 *
 * - Production Order #8: Range [2025-11-03 to 2025-11-03] - CREATED
 *   - ordered_quantity = 62
 *   - ordered_quantity_new = 0 ❌ (CURRENT BUG - should be 62)
 *   - Should produce: 0 ❌ (PROBLEM - should produce 62)
 *
 * THE BUG:
 * The cancelled OP #7 is being considered as a "previous OP" by the repository.
 * When OP #8 is created, the system sees OP #7 in getPreviousAdvanceOrdersWithSameDates()
 * even though OP #7 is CANCELLED and never produced anything.
 *
 * CORRECT CALCULATION (Expected):
 * - OP #7 is CANCELLED → should be IGNORED
 * - OP #8 has no valid previous OPs
 * - ordered_quantity_new = 62 - 0 = 62 ✅
 *
 * EXPECTED BEHAVIOR:
 * Production Order #8 should produce 62 units
 *
 * CURRENT BEHAVIOR (BUG):
 * Production Order #8 produces 0 units because it sees cancelled OP #7
 *
 * TEST STATUS:
 * This test will FAIL initially, replicating the production bug.
 * Once the bug is fixed, this test should PASS.
 */
class AdvanceOrderCancelledShouldNotAffectNewOrdersTest extends TestCase
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

        // Create category for sandwiches
        $this->category = Category::create([
            'name' => 'SANDWICHES',
            'code' => 'SND',
            'description' => 'Sandwiches y bocadillos',
            'active' => true,
        ]);

        // Create product: SANDWICH MARRAQUETA JAMON QUESO
        $this->product = Product::create([
            'code' => 'SND-MAR-JQ',
            'name' => 'SND - SAND. MARRAQUETA JAMON QUESO',
            'description' => 'Sandwich en marraqueta con jamón y queso',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $this->warehouseRepository = new WarehouseRepository();
        $this->advanceOrderRepository = new AdvanceOrderRepository();
        $this->orderRepository = new OrderRepository();
    }

    /**
     * Test: Cancelled production order should not affect new production orders
     *
     * SCENARIO:
     * 1. Create 62 sandwiches ordered for 2025-11-03
     * 2. Create OP #1 for 2025-11-03 with 62 units → CANCEL IT
     * 3. Create OP #2 for 2025-11-03 → should still show 62 new units
     *
     * EXPECTED: OP #2 should have ordered_quantity_new = 62 (not 0)
     * CURRENT BUG: OP #2 shows ordered_quantity_new = 0 because it sees cancelled OP #1
     */
    public function test_cancelled_production_order_should_not_affect_new_orders(): void
    {
        // Reset stock
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            0
        );

        // ==================== STEP 1: Create customer orders ====================
        // Simulate 10 orders with different quantities totaling 62 units
        $orderQuantities = [5, 3, 3, 2, 6, 8, 10, 7, 9, 9]; // Total = 62

        foreach ($orderQuantities as $index => $qty) {
            $order = Order::create([
                'user_id' => $this->user->id,
                'dispatch_date' => '2025-11-03',
                'status' => 'PROCESSED',
                'total' => $qty * 2500, // $2500 per sandwich
            ]);
            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $this->product->id,
                'quantity' => $qty,
                'unit_price' => 2500,
                'total_price' => $qty * 2500,
            ]);
        }

        // Verify we have 62 units ordered
        $ordersInRange = $this->orderRepository->getProductsFromOrdersInDateRange(
            '2025-11-03',
            '2025-11-03'
        );
        $productData = $ordersInRange->firstWhere('product_id', $this->product->id);
        $this->assertEquals(62, $productData['ordered_quantity'], 'Should have 62 units ordered');

        // ==================== STEP 2: Create OP #1 and CANCEL it ====================
        $op1 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-02 10:00:00',
            'initial_dispatch_date' => '2025-11-03',
            'final_dispatch_date' => '2025-11-03',
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #1 - To be cancelled',
        ]);

        // Get products and calculate quantities for OP #1
        $productsDataOp1 = $this->orderRepository->getProductsFromOrdersInDateRange(
            '2025-11-03',
            '2025-11-03'
        );
        $previousOp1 = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($op1);
        $productDataOp1 = $productsDataOp1->firstWhere('product_id', $this->product->id);
        $orderedQtyOp1 = $productDataOp1['ordered_quantity'] ?? 0;
        $maxPrevOp1 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $this->product->id,
            $previousOp1,
            $op1
        );
        $newQtyOp1 = max(0, $orderedQtyOp1 - $maxPrevOp1);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op1->id,
            'product_id' => $this->product->id,
            'quantity' => $newQtyOp1,
            'ordered_quantity' => $orderedQtyOp1,
            'ordered_quantity_new' => $newQtyOp1,
        ]);

        // Validate OP #1 before cancellation
        $this->assertEquals(62, $orderedQtyOp1, 'OP #1: Should have 62 ordered quantity');
        $this->assertEquals(62, $newQtyOp1, 'OP #1: Should have 62 NEW quantity (no previous OPs)');

        // CANCEL OP #1
        $op1->update(['status' => AdvanceOrderStatus::CANCELLED]);

        // Verify OP #1 is cancelled
        $op1->refresh();
        $this->assertEquals(AdvanceOrderStatus::CANCELLED, $op1->status, 'OP #1 should be CANCELLED');

        // ==================== STEP 3: Create OP #2 (after OP #1 is cancelled) ====================
        $op2 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-03 11:00:00',
            'initial_dispatch_date' => '2025-11-03',
            'final_dispatch_date' => '2025-11-03',
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #2 - Should not be affected by cancelled OP #1',
        ]);

        // Get products and calculate quantities for OP #2
        $productsDataOp2 = $this->orderRepository->getProductsFromOrdersInDateRange(
            '2025-11-03',
            '2025-11-03'
        );
        $previousOp2 = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($op2);
        $productDataOp2 = $productsDataOp2->firstWhere('product_id', $this->product->id);
        $orderedQtyOp2 = $productDataOp2['ordered_quantity'] ?? 0;
        $maxPrevOp2 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $this->product->id,
            $previousOp2,
            $op2
        );
        $newQtyOp2 = max(0, $orderedQtyOp2 - $maxPrevOp2);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op2->id,
            'product_id' => $this->product->id,
            'quantity' => $newQtyOp2,
            'ordered_quantity' => $orderedQtyOp2,
            'ordered_quantity_new' => $newQtyOp2,
        ]);

        // ==================== VALIDATIONS ====================

        // Validate that cancelled OP #1 is NOT in previous OPs list
        $this->assertEquals(
            0,
            $previousOp2->count(),
            'CANCELLED OP #1 should NOT be in previous OPs list for OP #2'
        );

        // Validate that OP #2 has correct quantities
        $this->assertEquals(62, $orderedQtyOp2, 'OP #2: Should have 62 ordered quantity');
        $this->assertEquals(0, $maxPrevOp2, 'OP #2: Should have 0 from previous OPs (OP #1 is cancelled)');
        $this->assertEquals(
            62,
            $newQtyOp2,
            'BUG: OP #2 should have 62 NEW quantity. ' .
            'Cancelled OP #1 should NOT affect this calculation. ' .
            'Current bug: getPreviousAdvanceOrdersWithSameDates() includes CANCELLED orders.'
        );
    }
}
