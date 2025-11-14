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
use App\Repositories\OrderRepository;
use App\Repositories\WarehouseRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TDD Test - Production Replica Bug: LASAÑA BOLONESA Overlapping Dates
 *
 * PRODUCTION BUG (Real data from production database):
 *
 * CUSTOMER ORDERS:
 * - Order 284: 1 LASAÑA BOLONESA for dispatch 2025-11-07
 * - Order 317: 1 LASAÑA BOLONESA for dispatch 2025-11-07
 * - Order 289: 1 LASAÑA BOLONESA for dispatch 2025-11-09
 *
 * PRODUCTION ORDERS:
 * - Production Order #5: Range [2025-11-07 to 2025-11-08]
 *   - ordered_quantity = 2 (Orders 284 + 317 for 2025-11-07)
 *   - ordered_quantity_new = 2
 *   - Produces 2 lasagnas ✅
 *
 * - Production Order #6: Range [2025-11-08 to 2025-11-09]
 *   - ordered_quantity = 1 (Order 289 for 2025-11-09)
 *   - ordered_quantity_new = 0 ❌ (CURRENT BUG - should be 1)
 *   - Produces 0 lasagnas ❌ (PROBLEM - should produce 1)
 *
 * THE BUG:
 * Current calculation: ordered_quantity_new = MAX(0, 1 - 2) = 0
 * - Compares total quantities across entire ranges
 * - Doesn't consider that the 2 lasagnas from Order #5 are for 2025-11-07 (already delivered)
 * - The 1 lasagna in Order #6 is for 2025-11-09 (different customer, different date)
 *
 * CORRECT CALCULATION (Expected):
 * Overlap dates between Order #5 and Order #6: [2025-11-08]
 * Customer orders in overlap dates: 0 (no orders for 2025-11-08)
 * ordered_quantity_new = MAX(0, 1 - 0) = 1 ✅
 *
 * EXPECTED BEHAVIOR:
 * Production Order #6 should produce 1 lasagna for Order 289
 *
 * CURRENT BEHAVIOR (BUG):
 * Production Order #6 produces 0 lasagnas (Order 289 won't be fulfilled)
 *
 * TEST STATUS:
 * This test will FAIL initially, replicating the production bug.
 * Once the bug is fixed, this test should PASS.
 */
class AdvanceOrderOverlapBugLasagnaBologneseTest extends TestCase
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

        // Create category for pasta dishes
        $this->category = Category::create([
            'name' => 'PLATOS DE FONDO',
            'code' => 'PLATO',
            'description' => 'Platos principales',
            'active' => true,
        ]);

        // Create product: LASAÑA BOLONESA
        $this->product = Product::create([
            'code' => 'PLATO-LAS-BOL',
            'name' => 'LASAÑA BOLONESA',
            'description' => 'Lasaña con salsa bolonesa',
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
     * Helper: Create a customer order with a specific dispatch date
     */
    protected function createCustomerOrder(
        string $description,
        int $quantity,
        string $dispatchDate,
        int $unitPrice = 5000
    ): Order {
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
        string $finalDispatchDate
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
        $productsData = $this->orderRepository->getProductsFromOrdersInDateRange(
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
            $previousAdvanceOrders,
            $advanceOrder
        );
        $orderedQuantityNew = max(0, $orderedQuantity - $maxPreviousQuantity);

        // Associate product with advance quantity = ordered_quantity_new (no extra advance)
        $advanceOrderProduct = AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product->id,
            'quantity' => $orderedQuantityNew,  // Produce exactly what's needed
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
            'ordered_quantity' => $orderedQuantity,
            'ordered_quantity_new' => $orderedQuantityNew,
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
     * Test: Production Bug Replica - LASAÑA BOLONESA with Overlapping Date Ranges
     *
     * Timeline:
     *        2025-11-07    2025-11-08    2025-11-09
     * Orders: 2 lasagnas   (no orders)   1 lasagna
     *         (#284, #317)               (#289)
     *
     * Prod Order #5: [=========== 2025-11-07 to 2025-11-08 ===========]
     *                   Covers: 2 lasagnas for 2025-11-07 ✅
     *
     * Prod Order #6:            [=========== 2025-11-08 to 2025-11-09 ===========]
     *                              Should cover: 1 lasagna for 2025-11-09
     *                              BUT currently covers: 0 (BUG) ❌
     *
     * OVERLAP DATE: 2025-11-08 (no customer orders on this date)
     *
     * EXPECTED: Production Order #6 should produce 1 lasagna
     * ACTUAL BUG: Production Order #6 produces 0 lasagnas
     */
    public function test_production_bug_replica_lasagna_bolognese_overlapping_dates_different_customers(): void
    {
        // ==================== SETUP: Initial Stock = 0 ====================
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            0
        );

        $initialStock = $this->getCurrentStock();
        $this->assertEquals(0, $initialStock, 'Initial inventory should be 0');

        // ==================== CREATE CUSTOMER ORDERS ====================

        // Order 284: 1 lasagna for dispatch on 2025-11-07
        $order284 = $this->createCustomerOrder(
            'Customer Order #284 (replicates production order 284)',
            1,
            '2025-11-07'
        );

        // Order 317: 1 lasagna for dispatch on 2025-11-07
        $order317 = $this->createCustomerOrder(
            'Customer Order #317 (replicates production order 317)',
            1,
            '2025-11-07'
        );

        // Order 289: 1 lasagna for dispatch on 2025-11-09
        $order289 = $this->createCustomerOrder(
            'Customer Order #289 (replicates production order 289)',
            1,
            '2025-11-09'
        );

        // ==================== PRODUCTION ORDER #5 (Range: 07-08) ====================

        $prodOrder5 = $this->createAndExecuteProductionOrder(
            'Production Order #5 - Range 2025-11-07 to 2025-11-08',
            '2025-11-06 08:00:00',  // preparation_datetime
            '2025-11-07',            // initial_dispatch_date
            '2025-11-08'             // final_dispatch_date
        );

        // Validate Production Order #5
        $this->assertEquals(
            2,
            $prodOrder5['ordered_quantity'],
            'Production Order #5: Should have 2 orders in range (Orders 284 + 317 for 2025-11-07)'
        );

        $this->assertEquals(
            2,
            $prodOrder5['ordered_quantity_new'],
            'Production Order #5: Should have 2 NEW orders (no previous production orders)'
        );

        $this->assertEquals(
            2,
            $prodOrder5['total_to_produce'],
            'Production Order #5: Should produce 2 lasagnas'
        );

        $stockAfterProdOrder5 = $this->getCurrentStock();
        $this->assertEquals(
            0,
            $stockAfterProdOrder5,
            'Production Order #5: Final stock should be 0 (0 + 2 - 2)'
        );

        // ==================== PRODUCTION ORDER #6 (Range: 08-09) ====================

        $prodOrder6 = $this->createAndExecuteProductionOrder(
            'Production Order #6 - Range 2025-11-08 to 2025-11-09',
            '2025-11-07 08:00:00',  // preparation_datetime (next day)
            '2025-11-08',            // initial_dispatch_date
            '2025-11-09'             // final_dispatch_date
        );

        // Validate Production Order #6
        $this->assertEquals(
            1,
            $prodOrder6['ordered_quantity'],
            'Production Order #6: Should have 1 order in range (Order 289 for 2025-11-09)'
        );

        // ==================== THIS IS THE BUG ====================
        // EXPECTED: ordered_quantity_new = 1 (1 new lasagna for 2025-11-09)
        // ACTUAL: ordered_quantity_new = 0 (system thinks it's covered by Production Order #5)
        //
        // WHY THE BUG OCCURS:
        // Current logic: ordered_quantity_new = MAX(0, 1 - 2) = 0
        // - Takes ordered_quantity (1) from range [08-09]
        // - Subtracts max_previous_quantity (2) from Production Order #5
        // - Doesn't consider that those 2 lasagnas are for 2025-11-07 (different date)
        //
        // CORRECT LOGIC (expected):
        // Overlap dates: [2025-11-08]
        // Orders in overlap: 0 (no customer orders for 2025-11-08)
        // ordered_quantity_new = MAX(0, 1 - 0) = 1
        //
        // This test will FAIL here until the bug is fixed:
        $this->assertEquals(
            1,
            $prodOrder6['ordered_quantity_new'],
            'Production Order #6: Should have 1 NEW order (Order 289 for 2025-11-09 is NOT covered by Production Order #5). ' .
            'Current bug: System calculates MAX(0, 1 - 2) = 0 because it compares total quantities ' .
            'without considering specific delivery dates. ' .
            'Expected: Should compare only orders in overlapping dates [2025-11-08] which has 0 orders.'
        );

        $this->assertEquals(
            1,
            $prodOrder6['total_to_produce'],
            'Production Order #6: Should produce 1 lasagna for Order 289'
        );

        $stockAfterProdOrder6 = $this->getCurrentStock();
        $this->assertEquals(
            0,
            $stockAfterProdOrder6,
            'Production Order #6: Final stock should be 0 (0 + 1 - 1)'
        );

        // ==================== SUMMARY ====================
        $this->assertTrue(true, sprintf(
            "LASAÑA BOLONESA OVERLAP BUG Test:\n\n" .
            "CUSTOMER ORDERS:\n" .
            "  Order #284: 1 lasagna for 2025-11-07\n" .
            "  Order #317: 1 lasagna for 2025-11-07\n" .
            "  Order #289: 1 lasagna for 2025-11-09\n\n" .
            "PRODUCTION ORDER #5 (Range: 2025-11-07 to 2025-11-08):\n" .
            "  Initial Stock: %d\n" .
            "  Ordered Quantity: %d (Orders 284 + 317)\n" .
            "  Ordered Quantity New: %d\n" .
            "  Total to Produce: %d\n" .
            "  Final Stock: %d\n\n" .
            "PRODUCTION ORDER #6 (Range: 2025-11-08 to 2025-11-09):\n" .
            "  Initial Stock: %d\n" .
            "  Ordered Quantity: %d (Order 289)\n" .
            "  Ordered Quantity New: %d (EXPECTED: 1, ACTUAL: %d) %s\n" .
            "  Total to Produce: %d (EXPECTED: 1, ACTUAL: %d) %s\n" .
            "  Final Stock: %d\n\n" .
            "OVERLAP DATE: 2025-11-08 (0 customer orders on this date)\n" .
            "BUG: System compares total quantities (1 - 2 = 0) instead of comparing only orders in overlap dates (1 - 0 = 1)",
            $initialStock,
            $prodOrder5['ordered_quantity'],
            $prodOrder5['ordered_quantity_new'],
            $prodOrder5['total_to_produce'],
            $stockAfterProdOrder5,
            $stockAfterProdOrder5,
            $prodOrder6['ordered_quantity'],
            1, // expected
            $prodOrder6['ordered_quantity_new'], // actual
            $prodOrder6['ordered_quantity_new'] == 1 ? '✅' : '❌',
            1, // expected
            $prodOrder6['total_to_produce'], // actual
            $prodOrder6['total_to_produce'] == 1 ? '✅' : '❌',
            $stockAfterProdOrder6
        ));
    }

    /**
     * Test: Escenario 1 - Solapamiento parcial con pedidos en diferentes días
     *
     * SCENARIO:
     * - Orden Prod #A: Rango [Lun-Mié], 5 productos para Lunes
     * - Orden Prod #B: Rango [Mar-Jue], 3 productos para Jueves
     * - Solapamiento: [Mar-Mié]
     * - Si no hay pedidos para Mar-Mié, la Orden #B debe producir 3 (no 0)
     *
     * Timeline:
     *        Lun-10    Mar-11    Mié-12    Jue-13
     * Orders: 5 units   (no)      (no)     3 units
     *
     * Prod Order #A: [=========== Lun-Mié ===========]
     *                   Covers: 5 units for Monday ✅
     *
     * Prod Order #B:            [=========== Mar-Jue ===========]
     *                              Should cover: 3 units for Thursday
     *                              BUT currently covers: 0 (BUG) ❌
     *
     * OVERLAP DATES: [Mar-Mié] (no customer orders on these dates)
     */
    public function test_scenario_1_partial_overlap_with_orders_on_different_days(): void
    {
        // Reset stock
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            0
        );

        // Create 5 units for Monday (2025-11-10)
        $this->createCustomerOrder('Order for Monday', 5, '2025-11-10');

        // Create 3 units for Thursday (2025-11-13)
        $this->createCustomerOrder('Order for Thursday', 3, '2025-11-13');

        // Production Order #A (Range: Monday to Wednesday)
        $prodOrderA = $this->createAndExecuteProductionOrder(
            'Prod Order A - Range Lun-Mié',
            '2025-11-09 08:00:00',
            '2025-11-10',  // Monday
            '2025-11-12'   // Wednesday
        );

        $this->assertEquals(5, $prodOrderA['ordered_quantity'], 'Order A: Should have 5 orders (5 for Monday)');
        $this->assertEquals(5, $prodOrderA['ordered_quantity_new'], 'Order A: Should have 5 NEW orders');
        $this->assertEquals(5, $prodOrderA['total_to_produce'], 'Order A: Should produce 5 units');

        // Production Order #B (Range: Tuesday to Thursday)
        $prodOrderB = $this->createAndExecuteProductionOrder(
            'Prod Order B - Range Mar-Jue',
            '2025-11-10 08:00:00',
            '2025-11-11',  // Tuesday
            '2025-11-13'   // Thursday
        );

        $this->assertEquals(3, $prodOrderB['ordered_quantity'], 'Order B: Should have 3 orders (3 for Thursday)');

        // BUG: System calculates MAX(0, 3 - 5) = 0 instead of MAX(0, 3 - 0) = 3
        $this->assertEquals(
            3,
            $prodOrderB['ordered_quantity_new'],
            'SCENARIO 1 BUG: Order B should have 3 NEW orders (Thursday orders NOT covered by Order A). ' .
            'Overlap dates [Tue-Wed] have 0 orders, so 3 - 0 = 3. Current bug: 3 - 5 = 0'
        );

        $this->assertEquals(3, $prodOrderB['total_to_produce'], 'Order B: Should produce 3 units');
    }

    /**
     * Test: Escenario 2 - Múltiples solapamientos con distribución irregular de pedidos
     *
     * SCENARIO:
     * - Orden Prod #A: Rango [Lun-Vie], 10 productos todos para Lunes
     * - Orden Prod #B: Rango [Jue-Sáb], 5 productos todos para Sábado
     * - Solapamiento: [Jue-Vie]
     * - Si no hay pedidos para Jue-Vie, la Orden #B debe producir 5 (no 0)
     *
     * Timeline:
     *        Lun-10    Mar-11    Mié-12    Jue-13    Vie-14    Sáb-15
     * Orders: 10 units  (no)      (no)      (no)      (no)     5 units
     *
     * Prod Order #A: [======================= Lun-Vie =======================]
     *                   Covers: 10 units for Monday ✅
     *
     * Prod Order #B:                                [=========== Jue-Sáb ===========]
     *                                                  Should cover: 5 units for Saturday
     *                                                  BUT currently covers: 0 (BUG) ❌
     *
     * OVERLAP DATES: [Jue-Vie] (no customer orders on these dates)
     */
    public function test_scenario_2_multiple_overlaps_with_irregular_distribution(): void
    {
        // Reset stock
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            0
        );

        // Create 10 units for Monday (2025-11-10)
        $this->createCustomerOrder('Order for Monday', 10, '2025-11-10');

        // Create 5 units for Saturday (2025-11-15)
        $this->createCustomerOrder('Order for Saturday', 5, '2025-11-15');

        // Production Order #A (Range: Monday to Friday)
        $prodOrderA = $this->createAndExecuteProductionOrder(
            'Prod Order A - Range Lun-Vie',
            '2025-11-09 08:00:00',
            '2025-11-10',  // Monday
            '2025-11-14'   // Friday
        );

        $this->assertEquals(10, $prodOrderA['ordered_quantity'], 'Order A: Should have 10 orders (10 for Monday)');
        $this->assertEquals(10, $prodOrderA['ordered_quantity_new'], 'Order A: Should have 10 NEW orders');
        $this->assertEquals(10, $prodOrderA['total_to_produce'], 'Order A: Should produce 10 units');

        // Production Order #B (Range: Thursday to Saturday)
        $prodOrderB = $this->createAndExecuteProductionOrder(
            'Prod Order B - Range Jue-Sáb',
            '2025-11-12 08:00:00',
            '2025-11-13',  // Thursday
            '2025-11-15'   // Saturday
        );

        $this->assertEquals(5, $prodOrderB['ordered_quantity'], 'Order B: Should have 5 orders (5 for Saturday)');

        // BUG: System calculates MAX(0, 5 - 10) = 0 instead of MAX(0, 5 - 0) = 5
        $this->assertEquals(
            5,
            $prodOrderB['ordered_quantity_new'],
            'SCENARIO 2 BUG: Order B should have 5 NEW orders (Saturday orders NOT covered by Order A). ' .
            'Overlap dates [Thu-Fri] have 0 orders, so 5 - 0 = 5. Current bug: 5 - 10 = 0'
        );

        $this->assertEquals(5, $prodOrderB['total_to_produce'], 'Order B: Should produce 5 units');
    }

    /**
     * Test: Escenario 3 - Solapamiento total pero pedidos en extremos
     *
     * SCENARIO:
     * - Orden Prod #A: Rango [Lun-Dom], 8 productos para Lunes
     * - Orden Prod #B: Rango [Mié-Vie], 4 productos para Viernes
     * - Solapamiento: [Mié-Vie]
     * - Si no hay pedidos para Mié-Vie, la Orden #B debe producir 4 (no 0)
     *
     * Timeline:
     *        Lun-10    Mar-11    Mié-12    Jue-13    Vie-14    Sáb-15    Dom-16
     * Orders: 8 units   (no)      (no)      (no)     4 units    (no)      (no)
     *
     * Prod Order #A: [========================== Lun-Dom ==========================]
     *                   Covers: 8 units for Monday ✅
     *
     * Prod Order #B:                      [=========== Mié-Vie ===========]
     *                                        Should cover: 4 units for Friday
     *                                        BUT currently covers: 0 (BUG) ❌
     *
     * OVERLAP DATES: [Mié-Vie] (no customer orders on Wed-Thu, 4 orders on Fri but that's for Order B range)
     */
    public function test_scenario_3_total_overlap_but_orders_at_extremes(): void
    {
        // Reset stock
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            0
        );

        // Create 8 units for Monday (2025-11-10)
        $this->createCustomerOrder('Order for Monday', 8, '2025-11-10');

        // Create 4 units for Friday (2025-11-14)
        $this->createCustomerOrder('Order for Friday', 4, '2025-11-14');

        // Production Order #A (Range: Monday to Sunday)
        $prodOrderA = $this->createAndExecuteProductionOrder(
            'Prod Order A - Range Lun-Dom',
            '2025-11-09 08:00:00',
            '2025-11-10',  // Monday
            '2025-11-16'   // Sunday
        );

        $this->assertEquals(12, $prodOrderA['ordered_quantity'], 'Order A: Should have 12 orders (8 for Mon + 4 for Fri)');
        $this->assertEquals(12, $prodOrderA['ordered_quantity_new'], 'Order A: Should have 12 NEW orders');
        $this->assertEquals(12, $prodOrderA['total_to_produce'], 'Order A: Should produce 12 units');

        // Production Order #B (Range: Wednesday to Friday)
        $prodOrderB = $this->createAndExecuteProductionOrder(
            'Prod Order B - Range Mié-Vie',
            '2025-11-11 08:00:00',
            '2025-11-12',  // Wednesday
            '2025-11-14'   // Friday
        );

        $this->assertEquals(4, $prodOrderB['ordered_quantity'], 'Order B: Should have 4 orders (4 for Friday)');

        // BUG: System calculates MAX(0, 4 - 12) = 0 instead of MAX(0, 4 - 4) = 0
        // Wait, this case the overlap DOES contain the Friday order (4 units), so the correct answer is 0
        // But we need to test that it compares correctly
        // Let me reconsider: Order A range includes Friday and already covers those 4 units
        // So ordered_quantity_new SHOULD be 0 in this case
        // This is actually a CORRECT case, not a bug case

        // Actually, Order A already produced 12 units which includes the 4 for Friday
        // So Order B should produce 0 (this is correct behavior)
        $this->assertEquals(
            0,
            $prodOrderB['ordered_quantity_new'],
            'SCENARIO 3 CORRECT: Order B should have 0 NEW orders because Order A already covers Friday orders. ' .
            'Overlap dates [Wed-Fri] include the 4 Friday orders, so 4 - 4 = 0.'
        );

        $this->assertEquals(0, $prodOrderB['total_to_produce'], 'Order B: Should produce 0 units (already covered)');
    }

    /**
     * Test: Escenario 4 - Caso CORRECTO (solapamiento real de pedidos)
     *
     * SCENARIO:
     * - Orden Prod #A: Rango [Lun-Mié], 5 productos para Martes
     * - Orden Prod #B: Rango [Mar-Jue], 3 productos para Martes
     * - Solapamiento: [Mar-Mié]
     * - Pedidos en solapamiento: 5 para Martes (ya cubiertos)
     * - La Orden #B debe producir MAX(0, 3 - 5) = 0 ✅ (correcto)
     *
     * Timeline:
     *        Lun-10    Mar-11    Mié-12    Jue-13
     * Orders: (no)      5 units   (no)      (no)
     *
     * Prod Order #A: [=========== Lun-Mié ===========]
     *                   Covers: 5 units for Tuesday ✅
     *
     * Prod Order #B:            [=========== Mar-Jue ===========]
     *                              Needs: 3 units for Tuesday
     *                              Already covered by Order A: 5 units
     *                              Should produce: 0 (CORRECT) ✅
     *
     * OVERLAP DATES: [Mar-Mié] (5 customer orders for Tuesday - overlap date)
     */
    public function test_scenario_4_correct_case_real_overlap_of_orders(): void
    {
        // Reset stock
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            0
        );

        // Create 5 units for Tuesday (2025-11-11)
        $this->createCustomerOrder('First order for Tuesday', 5, '2025-11-11');

        // Production Order #A (Range: Monday to Wednesday)
        $prodOrderA = $this->createAndExecuteProductionOrder(
            'Prod Order A - Range Lun-Mié',
            '2025-11-09 08:00:00',
            '2025-11-10',  // Monday
            '2025-11-12'   // Wednesday
        );

        $this->assertEquals(5, $prodOrderA['ordered_quantity'], 'Order A: Should have 5 orders (5 for Tuesday)');
        $this->assertEquals(5, $prodOrderA['ordered_quantity_new'], 'Order A: Should have 5 NEW orders');
        $this->assertEquals(5, $prodOrderA['total_to_produce'], 'Order A: Should produce 5 units');

        // Now create 3 MORE units for Tuesday (simulating late orders)
        // In reality this would be before Order B is created
        $this->createCustomerOrder('Additional order for Tuesday', 3, '2025-11-11');

        // Production Order #B (Range: Tuesday to Thursday)
        $prodOrderB = $this->createAndExecuteProductionOrder(
            'Prod Order B - Range Mar-Jue',
            '2025-11-10 08:00:00',
            '2025-11-11',  // Tuesday
            '2025-11-13'   // Thursday
        );

        $this->assertEquals(8, $prodOrderB['ordered_quantity'], 'Order B: Should have 8 orders (5+3 for Tuesday)');

        // CORRECT BEHAVIOR: System should calculate MAX(0, 8 - 5) = 3
        // Because in overlap dates [Tue-Wed], there are 8 orders (all for Tuesday)
        // And Order A already covered 5 of them
        // So Order B needs to produce 3 more
        $this->assertEquals(
            3,
            $prodOrderB['ordered_quantity_new'],
            'SCENARIO 4 CORRECT: Order B should have 3 NEW orders. ' .
            'Overlap dates [Tue-Wed] have 8 orders for Tuesday. Order A covered 5, so 8 - 5 = 3.'
        );

        $this->assertEquals(3, $prodOrderB['total_to_produce'], 'Order B: Should produce 3 units');
    }

    /**
     * Test: Escenario 1 - Overlap Parcial con Múltiples Productos y Clientes
     *
     * PRODUCTOS: LASAÑA BOLONESA, ENSALADA CÉSAR, JUGO NARANJA
     * CLIENTES: 3 empresas diferentes
     *
     * TIMELINE (Date nomenclature):
     * A = 2025-11-10 (Lun)
     * B = 2025-11-11 (Mar)
     * C = 2025-11-12 (Mié)
     * D = 2025-11-13 (Jue)
     * E = 2025-11-14 (Vie)
     * F = 2025-11-15 (Sáb)
     *
     * MOMENTO 1: Llegan primeros pedidos
     * - Fecha A: Cliente A: 5 Lasañas, Cliente B: 3 Ensaladas, Cliente A: 2 Jugos
     * - Fecha C: Cliente B: 4 Lasañas, Cliente C: 2 Ensaladas
     * - Fecha D: Cliente A: 3 Lasañas, Cliente B: 1 Ensalada, Cliente C: 5 Jugos
     *
     * MOMENTO 2: Se crea OP #1 [A-D]
     * - Lasañas: ordered_quantity = 12, ordered_quantity_new = 12
     * - Ensaladas: ordered_quantity = 6, ordered_quantity_new = 6
     * - Jugos: ordered_quantity = 7, ordered_quantity_new = 7
     *
     * MOMENTO 3: Llegan pedidos NUEVOS (después de crear OP #1)
     * - Fecha C: Cliente A: 6 Lasañas (NUEVAS), Cliente B: 2 Jugos (NUEVOS)
     * - Fecha F: Cliente C: 8 Lasañas, Cliente A: 4 Ensaladas, Cliente B: 3 Jugos
     *
     * MOMENTO 4: Se crea OP #2 [C-F]
     * - Lasañas: ordered_quantity = 21, overlap [C-D] = 13, OP#1 cubrió 12, NEW = 9
     * - Ensaladas: ordered_quantity = 7, overlap [C-D] = 3, OP#1 cubrió 6, NEW = 4
     * - Jugos: ordered_quantity = 10, overlap [C-D] = 7, OP#1 cubrió 7, NEW = 3
     */
    public function test_escenario_1_overlap_parcial_multiples_productos_y_clientes(): void
    {
        // ==================== SETUP: Create additional products ====================
        $ensalada = Product::create([
            'code' => 'ENS-CES',
            'name' => 'ENSALADA CÉSAR',
            'description' => 'Ensalada César con pollo',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $jugo = Product::create([
            'code' => 'BEB-JUG-NAR',
            'name' => 'JUGO NARANJA',
            'description' => 'Jugo natural de naranja',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Set initial stock for all products
        foreach ([$this->product, $ensalada, $jugo] as $product) {
            $this->warehouseRepository->updateProductStockInWarehouse(
                $product->id,
                $this->warehouse->id,
                0
            );
        }

        // ==================== MOMENTO 1: Llegan primeros pedidos ====================

        // Fecha A (Lun 10) - Cliente A: 5 Lasañas
        $orderA1 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-10',
            'status' => 'PROCESSED',
            'total' => 25000,
        ]);
        OrderLine::create([
            'order_id' => $orderA1->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 5000,
            'total_price' => 25000,
        ]);

        // Fecha A (Lun 10) - Cliente B: 3 Ensaladas
        $orderA2 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-10',
            'status' => 'PROCESSED',
            'total' => 9000,
        ]);
        OrderLine::create([
            'order_id' => $orderA2->id,
            'product_id' => $ensalada->id,
            'quantity' => 3,
            'unit_price' => 3000,
            'total_price' => 9000,
        ]);

        // Fecha A (Lun 10) - Cliente A: 2 Jugos
        OrderLine::create([
            'order_id' => $orderA1->id,
            'product_id' => $jugo->id,
            'quantity' => 2,
            'unit_price' => 2000,
            'total_price' => 4000,
        ]);

        // Fecha C (Mié 12) - Cliente B: 4 Lasañas
        $orderC1 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-12',
            'status' => 'PROCESSED',
            'total' => 20000,
        ]);
        OrderLine::create([
            'order_id' => $orderC1->id,
            'product_id' => $this->product->id,
            'quantity' => 4,
            'unit_price' => 5000,
            'total_price' => 20000,
        ]);

        // Fecha C (Mié 12) - Cliente C: 2 Ensaladas
        $orderC2 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-12',
            'status' => 'PROCESSED',
            'total' => 6000,
        ]);
        OrderLine::create([
            'order_id' => $orderC2->id,
            'product_id' => $ensalada->id,
            'quantity' => 2,
            'unit_price' => 3000,
            'total_price' => 6000,
        ]);

        // Fecha D (Jue 13) - Cliente A: 3 Lasañas
        $orderD1 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-13',
            'status' => 'PROCESSED',
            'total' => 15000,
        ]);
        OrderLine::create([
            'order_id' => $orderD1->id,
            'product_id' => $this->product->id,
            'quantity' => 3,
            'unit_price' => 5000,
            'total_price' => 15000,
        ]);

        // Fecha D (Jue 13) - Cliente B: 1 Ensalada
        $orderD2 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-13',
            'status' => 'PROCESSED',
            'total' => 3000,
        ]);
        OrderLine::create([
            'order_id' => $orderD2->id,
            'product_id' => $ensalada->id,
            'quantity' => 1,
            'unit_price' => 3000,
            'total_price' => 3000,
        ]);

        // Fecha D (Jue 13) - Cliente C: 5 Jugos
        $orderD3 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-13',
            'status' => 'PROCESSED',
            'total' => 10000,
        ]);
        OrderLine::create([
            'order_id' => $orderD3->id,
            'product_id' => $jugo->id,
            'quantity' => 5,
            'unit_price' => 2000,
            'total_price' => 10000,
        ]);

        // ==================== MOMENTO 2: Se crea OP #1 [A-D] ====================
        $op1 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-09 08:00:00',
            'initial_dispatch_date' => '2025-11-10',  // A
            'final_dispatch_date' => '2025-11-13',    // D
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #1 - Range [A-D]',
        ]);

        $productsDataOp1 = $this->orderRepository->getProductsFromOrdersInDateRange(
            '2025-11-10',
            '2025-11-13'
        );

        $previousOp1 = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($op1);

        // Process Lasañas for OP #1
        $lasagnaDataOp1 = $productsDataOp1->firstWhere('product_id', $this->product->id);
        $lasagnaOrderedQtyOp1 = $lasagnaDataOp1['ordered_quantity'] ?? 0;
        $lasagnaMaxPrevOp1 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $this->product->id,
            $previousOp1,
            $op1
        );
        $lasagnaNewOp1 = max(0, $lasagnaOrderedQtyOp1 - $lasagnaMaxPrevOp1);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op1->id,
            'product_id' => $this->product->id,
            'quantity' => $lasagnaNewOp1,
            'ordered_quantity' => $lasagnaOrderedQtyOp1,
            'ordered_quantity_new' => $lasagnaNewOp1,
        ]);

        // Process Ensaladas for OP #1
        $ensaladaDataOp1 = $productsDataOp1->firstWhere('product_id', $ensalada->id);
        $ensaladaOrderedQtyOp1 = $ensaladaDataOp1['ordered_quantity'] ?? 0;
        $ensaladaMaxPrevOp1 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $ensalada->id,
            $previousOp1,
            $op1
        );
        $ensaladaNewOp1 = max(0, $ensaladaOrderedQtyOp1 - $ensaladaMaxPrevOp1);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op1->id,
            'product_id' => $ensalada->id,
            'quantity' => $ensaladaNewOp1,
            'ordered_quantity' => $ensaladaOrderedQtyOp1,
            'ordered_quantity_new' => $ensaladaNewOp1,
        ]);

        // Process Jugos for OP #1
        $jugoDataOp1 = $productsDataOp1->firstWhere('product_id', $jugo->id);
        $jugoOrderedQtyOp1 = $jugoDataOp1['ordered_quantity'] ?? 0;
        $jugoMaxPrevOp1 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $jugo->id,
            $previousOp1,
            $op1
        );
        $jugoNewOp1 = max(0, $jugoOrderedQtyOp1 - $jugoMaxPrevOp1);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op1->id,
            'product_id' => $jugo->id,
            'quantity' => $jugoNewOp1,
            'ordered_quantity' => $jugoOrderedQtyOp1,
            'ordered_quantity_new' => $jugoNewOp1,
        ]);

        // Execute OP #1
        $op1->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op1));

        // Validate OP #1
        $this->assertEquals(12, $lasagnaOrderedQtyOp1, 'OP #1: Lasañas ordered_quantity = 5+4+3');
        $this->assertEquals(12, $lasagnaNewOp1, 'OP #1: Lasañas ordered_quantity_new = 12');
        $this->assertEquals(6, $ensaladaOrderedQtyOp1, 'OP #1: Ensaladas ordered_quantity = 3+2+1');
        $this->assertEquals(6, $ensaladaNewOp1, 'OP #1: Ensaladas ordered_quantity_new = 6');
        $this->assertEquals(7, $jugoOrderedQtyOp1, 'OP #1: Jugos ordered_quantity = 2+5');
        $this->assertEquals(7, $jugoNewOp1, 'OP #1: Jugos ordered_quantity_new = 7');

        // ==================== MOMENTO 3: Llegan pedidos NUEVOS ====================

        // Fecha C (Mié 12) - Cliente A: 6 Lasañas (NUEVAS - llegaron tarde)
        $orderC3 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-12',
            'status' => 'PROCESSED',
            'total' => 30000,
        ]);
        OrderLine::create([
            'order_id' => $orderC3->id,
            'product_id' => $this->product->id,
            'quantity' => 6,
            'unit_price' => 5000,
            'total_price' => 30000,
        ]);

        // Fecha C (Mié 12) - Cliente B: 2 Jugos (NUEVOS)
        $orderC4 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-12',
            'status' => 'PROCESSED',
            'total' => 4000,
        ]);
        OrderLine::create([
            'order_id' => $orderC4->id,
            'product_id' => $jugo->id,
            'quantity' => 2,
            'unit_price' => 2000,
            'total_price' => 4000,
        ]);

        // Fecha F (Sáb 15) - Cliente C: 8 Lasañas
        $orderF1 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-15',
            'status' => 'PROCESSED',
            'total' => 40000,
        ]);
        OrderLine::create([
            'order_id' => $orderF1->id,
            'product_id' => $this->product->id,
            'quantity' => 8,
            'unit_price' => 5000,
            'total_price' => 40000,
        ]);

        // Fecha F (Sáb 15) - Cliente A: 4 Ensaladas
        $orderF2 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-15',
            'status' => 'PROCESSED',
            'total' => 12000,
        ]);
        OrderLine::create([
            'order_id' => $orderF2->id,
            'product_id' => $ensalada->id,
            'quantity' => 4,
            'unit_price' => 3000,
            'total_price' => 12000,
        ]);

        // Fecha F (Sáb 15) - Cliente B: 3 Jugos
        $orderF3 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-15',
            'status' => 'PROCESSED',
            'total' => 6000,
        ]);
        OrderLine::create([
            'order_id' => $orderF3->id,
            'product_id' => $jugo->id,
            'quantity' => 3,
            'unit_price' => 2000,
            'total_price' => 6000,
        ]);

        // ==================== MOMENTO 4: Se crea OP #2 [C-F] ====================
        $op2 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-11 08:00:00',
            'initial_dispatch_date' => '2025-11-12',  // C
            'final_dispatch_date' => '2025-11-15',    // F
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #2 - Range [C-F]',
        ]);

        $productsDataOp2 = $this->orderRepository->getProductsFromOrdersInDateRange(
            '2025-11-12',
            '2025-11-15'
        );

        $previousOp2 = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($op2);

        // Process Lasañas for OP #2
        $lasagnaDataOp2 = $productsDataOp2->firstWhere('product_id', $this->product->id);
        $lasagnaOrderedQtyOp2 = $lasagnaDataOp2['ordered_quantity'] ?? 0;
        $lasagnaMaxPrevOp2 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $this->product->id,
            $previousOp2,
            $op2
        );
        $lasagnaNewOp2 = max(0, $lasagnaOrderedQtyOp2 - $lasagnaMaxPrevOp2);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op2->id,
            'product_id' => $this->product->id,
            'quantity' => $lasagnaNewOp2,
            'ordered_quantity' => $lasagnaOrderedQtyOp2,
            'ordered_quantity_new' => $lasagnaNewOp2,
        ]);

        // Process Ensaladas for OP #2
        $ensaladaDataOp2 = $productsDataOp2->firstWhere('product_id', $ensalada->id);
        $ensaladaOrderedQtyOp2 = $ensaladaDataOp2['ordered_quantity'] ?? 0;
        $ensaladaMaxPrevOp2 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $ensalada->id,
            $previousOp2,
            $op2
        );
        $ensaladaNewOp2 = max(0, $ensaladaOrderedQtyOp2 - $ensaladaMaxPrevOp2);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op2->id,
            'product_id' => $ensalada->id,
            'quantity' => $ensaladaNewOp2,
            'ordered_quantity' => $ensaladaOrderedQtyOp2,
            'ordered_quantity_new' => $ensaladaNewOp2,
        ]);

        // Process Jugos for OP #2
        $jugoDataOp2 = $productsDataOp2->firstWhere('product_id', $jugo->id);
        $jugoOrderedQtyOp2 = $jugoDataOp2['ordered_quantity'] ?? 0;
        $jugoMaxPrevOp2 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $jugo->id,
            $previousOp2,
            $op2
        );
        $jugoNewOp2 = max(0, $jugoOrderedQtyOp2 - $jugoMaxPrevOp2);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op2->id,
            'product_id' => $jugo->id,
            'quantity' => $jugoNewOp2,
            'ordered_quantity' => $jugoOrderedQtyOp2,
            'ordered_quantity_new' => $jugoNewOp2,
        ]);

        // ==================== VALIDACIONES OP #2 ====================

        // Lasañas: ordered_quantity = 4+6+3+8 = 21 (en rango C-F)
        // CON PIVOTS: OP #1 solo cubrió pedidos que existían cuando se creó EN EL OVERLAP
        // - OP #1 [A-D] pivots: A=5, B=4, C=4, D=3 lasañas
        // - Overlap [C-D]: OP #1 cubrió C=4, D=3 = 7
        // - Las 6 tardías (orderC3) son nuevas
        // ordered_quantity_new = 21 - 7 = 14
        $this->assertEquals(21, $lasagnaOrderedQtyOp2, 'OP #2: Lasañas ordered_quantity = 4+6+3+8 = 21');
        $this->assertEquals(
            14,
            $lasagnaNewOp2,
            'OP #2: Lasañas ordered_quantity_new = 21 - 7 = 14. ' .
            'OP #1 pivots en overlap [C-D] = 7. Las 6 tardías + 8 de fecha F son nuevas.'
        );

        // Ensaladas: ordered_quantity = 2+1+4 = 7 (en rango C-F)
        // CON PIVOTS: OP #1 [A-D] cubrió en overlap [C-D]: C=2, D=1 = 3
        // ordered_quantity_new = 7 - 3 = 4
        $this->assertEquals(7, $ensaladaOrderedQtyOp2, 'OP #2: Ensaladas ordered_quantity = 2+1+4 = 7');
        $this->assertEquals(
            4,
            $ensaladaNewOp2,
            'OP #2: Ensaladas ordered_quantity_new = 7 - 3 = 4. ' .
            'Overlap [C-D] has 3 orders (2+1), OP #1 covered 6, min(3,6)=3, so 7-3=4'
        );

        // Jugos: ordered_quantity = 5+2+3 = 10 (en rango C-F)
        // CON PIVOTS: OP #1 [A-D] solo tenía jugos en fecha A=2 y D=5 cuando se creó
        // - Overlap [C-D]: OP #1 cubrió D=5 (fecha C no tenía jugos cuando OP #1 se creó)
        // - Los 2 jugos tardíos (orderC4 en fecha C) llegaron DESPUÉS de OP #1
        // ordered_quantity_new = 10 - 5 = 5
        $this->assertEquals(10, $jugoOrderedQtyOp2, 'OP #2: Jugos ordered_quantity = 5+2+3 = 10');
        $this->assertEquals(
            5,
            $jugoNewOp2,
            'OP #2: Jugos ordered_quantity_new = 10 - 5 = 5. ' .
            'OP #1 pivots en overlap [C-D] = 5 (solo fecha D). Los 2 tardíos de fecha C + 3 de fecha F son nuevos.'
        );
    }

    /**
     * Test: Escenario 2 - OP Grande que Contiene a OP Pequeña
     *
     * PRODUCTO: SPAGHETTI BOLONESA
     * CLIENTES: 3 empresas (Catering, Hotel, Casino)
     *
     * TIMELINE:
     * A = 2025-11-10 (Lun)
     * C = 2025-11-12 (Mié)
     * F = 2025-11-15 (Sáb)
     * G = 2025-11-16 (Dom)
     *
     * MOMENTO 1: Llegan primeros pedidos
     * - Fecha A: Catering: 10 Spaghetti
     * - Fecha C: Hotel: 5 Spaghetti, Casino: 3 Spaghetti
     * - Fecha G: Catering: 7 Spaghetti
     *
     * MOMENTO 2: Se crea OP #1 [A-G]
     * - ordered_quantity = 25 (10+5+3+7)
     * - ordered_quantity_new = 25
     *
     * MOMENTO 3: Llegan pedidos NUEVOS (después de OP #1)
     * - Fecha C: Hotel: 8 Spaghetti (NUEVOS), Catering: 2 Spaghetti (NUEVOS)
     * - Fecha F: Casino: 12 Spaghetti (NUEVOS)
     *
     * MOMENTO 4: Se crea OP #2 [C-F] (completamente dentro de OP #1)
     * - ordered_quantity = 30 (5+3+8+2+12 en rango C-F)
     * - Overlap [C-F] con OP #1 (OP #2 completamente dentro)
     * - OP #1 cubrió 25 en total
     * - effectiveQuantityInOverlap = min(30, 25) = 25
     * - ordered_quantity_new = 30 - 25 = 5
     */
    public function test_escenario_2_op_grande_contiene_op_pequena(): void
    {
        // ==================== SETUP: Create SPAGHETTI product ====================
        $spaghetti = Product::create([
            'code' => 'PLATO-SPA-BOL',
            'name' => 'SPAGHETTI BOLONESA',
            'description' => 'Spaghetti con salsa bolonesa',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $this->warehouseRepository->updateProductStockInWarehouse(
            $spaghetti->id,
            $this->warehouse->id,
            0
        );

        // ==================== MOMENTO 1: Llegan primeros pedidos ====================

        // Fecha A (Lun 10) - Catering: 10 Spaghetti
        $orderA = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-10',
            'status' => 'PROCESSED',
            'total' => 50000,
        ]);
        OrderLine::create([
            'order_id' => $orderA->id,
            'product_id' => $spaghetti->id,
            'quantity' => 10,
            'unit_price' => 5000,
            'total_price' => 50000,
        ]);

        // Fecha C (Mié 12) - Hotel: 5 Spaghetti
        $orderC1 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-12',
            'status' => 'PROCESSED',
            'total' => 25000,
        ]);
        OrderLine::create([
            'order_id' => $orderC1->id,
            'product_id' => $spaghetti->id,
            'quantity' => 5,
            'unit_price' => 5000,
            'total_price' => 25000,
        ]);

        // Fecha C (Mié 12) - Casino: 3 Spaghetti
        $orderC2 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-12',
            'status' => 'PROCESSED',
            'total' => 15000,
        ]);
        OrderLine::create([
            'order_id' => $orderC2->id,
            'product_id' => $spaghetti->id,
            'quantity' => 3,
            'unit_price' => 5000,
            'total_price' => 15000,
        ]);

        // Fecha G (Dom 16) - Catering: 7 Spaghetti
        $orderG = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-16',
            'status' => 'PROCESSED',
            'total' => 35000,
        ]);
        OrderLine::create([
            'order_id' => $orderG->id,
            'product_id' => $spaghetti->id,
            'quantity' => 7,
            'unit_price' => 5000,
            'total_price' => 35000,
        ]);

        // ==================== MOMENTO 2: Se crea OP #1 [A-G] ====================
        $op1 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-09 08:00:00',
            'initial_dispatch_date' => '2025-11-10',  // A
            'final_dispatch_date' => '2025-11-16',    // G
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #1 - Range [A-G]',
        ]);

        $productsDataOp1 = $this->orderRepository->getProductsFromOrdersInDateRange(
            '2025-11-10',
            '2025-11-16'
        );

        $previousOp1 = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($op1);

        $spaghettiDataOp1 = $productsDataOp1->firstWhere('product_id', $spaghetti->id);
        $orderedQtyOp1 = $spaghettiDataOp1['ordered_quantity'] ?? 0;
        $maxPrevOp1 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $spaghetti->id,
            $previousOp1,
            $op1
        );
        $newQtyOp1 = max(0, $orderedQtyOp1 - $maxPrevOp1);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op1->id,
            'product_id' => $spaghetti->id,
            'quantity' => $newQtyOp1,
            'ordered_quantity' => $orderedQtyOp1,
            'ordered_quantity_new' => $newQtyOp1,
        ]);

        // Execute OP #1
        $op1->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op1));

        // Validate OP #1
        $this->assertEquals(25, $orderedQtyOp1, 'OP #1: ordered_quantity = 10+5+3+7 = 25');
        $this->assertEquals(25, $newQtyOp1, 'OP #1: ordered_quantity_new = 25 (no previous OP)');

        // ==================== MOMENTO 3: Llegan pedidos NUEVOS ====================

        // Fecha C (Mié 12) - Hotel: 8 Spaghetti (NUEVOS - llegaron tarde)
        $orderC3 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-12',
            'status' => 'PROCESSED',
            'total' => 40000,
        ]);
        OrderLine::create([
            'order_id' => $orderC3->id,
            'product_id' => $spaghetti->id,
            'quantity' => 8,
            'unit_price' => 5000,
            'total_price' => 40000,
        ]);

        // Fecha C (Mié 12) - Catering: 2 Spaghetti (NUEVOS)
        $orderC4 = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-12',
            'status' => 'PROCESSED',
            'total' => 10000,
        ]);
        OrderLine::create([
            'order_id' => $orderC4->id,
            'product_id' => $spaghetti->id,
            'quantity' => 2,
            'unit_price' => 5000,
            'total_price' => 10000,
        ]);

        // Fecha F (Sáb 15) - Casino: 12 Spaghetti (NUEVOS)
        $orderF = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => '2025-11-15',
            'status' => 'PROCESSED',
            'total' => 60000,
        ]);
        OrderLine::create([
            'order_id' => $orderF->id,
            'product_id' => $spaghetti->id,
            'quantity' => 12,
            'unit_price' => 5000,
            'total_price' => 60000,
        ]);

        // ==================== MOMENTO 4: Se crea OP #2 [C-F] ====================
        $op2 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-13 08:00:00',
            'initial_dispatch_date' => '2025-11-12',  // C
            'final_dispatch_date' => '2025-11-15',    // F
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #2 - Range [C-F] (dentro de OP #1)',
        ]);

        $productsDataOp2 = $this->orderRepository->getProductsFromOrdersInDateRange(
            '2025-11-12',
            '2025-11-15'
        );

        $previousOp2 = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($op2);

        $spaghettiDataOp2 = $productsDataOp2->firstWhere('product_id', $spaghetti->id);
        $orderedQtyOp2 = $spaghettiDataOp2['ordered_quantity'] ?? 0;
        $maxPrevOp2 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $spaghetti->id,
            $previousOp2,
            $op2
        );
        $newQtyOp2 = max(0, $orderedQtyOp2 - $maxPrevOp2);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op2->id,
            'product_id' => $spaghetti->id,
            'quantity' => $newQtyOp2,
            'ordered_quantity' => $orderedQtyOp2,
            'ordered_quantity_new' => $newQtyOp2,
        ]);

        // ==================== VALIDACIONES OP #2 ====================

        // ordered_quantity = 5+3+8+2+12 = 30 (en rango C-F)
        // CON PIVOTS: OP #1 solo cubrió los pedidos que existían cuando se creó EN EL OVERLAP
        // - OP #1 [A-G] pivots: A=10, C=5+3=8, G=7 (total 25)
        // - Overlap con OP #2 [C-F] es [C-F]
        // - OP #1 pivots en [C-F]: C=8, F=0 (F no existía cuando se creó OP#1) = 8
        // - Los tardíos: C=8+2=10 nuevos, F=12 nuevos
        // ordered_quantity_new = 30 - 8 = 22
        $this->assertEquals(30, $orderedQtyOp2, 'OP #2: ordered_quantity = 5+3+8+2+12 = 30');
        $this->assertEquals(
            22,
            $newQtyOp2,
            'OP #2: ordered_quantity_new = 30 - 8 = 22. ' .
            'OP #1 pivots en overlap [C-F] = 8 (solo C, F no existía). Los 10 tardíos de C + 12 de F son nuevos.'
        );
    }

    /**
     * Test: Escenario 3 - OP Pequeña PRIMERO → OP Grande que la Contiene DESPUÉS
     *
     * PRODUCTO: LASAÑA BOLONESA
     *
     * TIMELINE:
     * A = 2025-11-10 (Lun)
     * B = 2025-11-11 (Mar)
     * C = 2025-11-12 (Mié)
     * D = 2025-11-13 (Jue)
     * E = 2025-11-14 (Vie)
     * F = 2025-11-15 (Sáb)
     *
     * ORDEN DE CREACIÓN:
     * 1. Se crea OP #1 [C-D] (pequeña) PRIMERO
     * 2. Se crea OP #2 [A-F] (grande que contiene a OP #1) DESPUÉS
     *
     * MOMENTO 1: Llegan primeros pedidos (antes de OP #1)
     * - Fecha A: 8 lasañas (NO overlap con OP #1)
     * - Fecha C: 5 lasañas (SÍ overlap con OP #1)
     * - Fecha D: 3 lasañas (SÍ overlap con OP #1)
     * - Fecha E: 4 lasañas (NO overlap con OP #1)
     *
     * MOMENTO 2: Se crea OP #1 [C-D]
     * - ordered_quantity = 8 (5 del C + 3 del D)
     * - ordered_quantity_new = 8 (no hay OPs previas)
     *
     * MOMENTO 3: Llegan pedidos NUEVOS (después de OP #1)
     * - Fecha B: 6 lasañas (NO overlap con OP #1)
     * - Fecha C: 2 lasañas NUEVAS (SÍ overlap con OP #1 - llegaron tarde)
     * - Fecha F: 5 lasañas (NO overlap con OP #1)
     *
     * MOMENTO 4: Se crea OP #2 [A-F] (contiene completamente a OP #1)
     * - ordered_quantity = 33 (8+6+5+2+3+4+5 en rango A-F)
     * - Overlap con OP #1: [A-F] ∩ [C-D] = [C-D]
     * - Pedidos actuales en overlap [C-D]: 10 (5+2 del C + 3 del D)
     * - OP #1 cubrió: 8 lasañas
     * - effectiveQuantityInOverlap = min(10, 8) = 8
     * - ordered_quantity_new = 33 - 8 = 25
     */
    public function test_escenario_3_op_pequena_primero_luego_op_grande_que_la_contiene(): void
    {
        // Reset stock
        $this->warehouseRepository->updateProductStockInWarehouse(
            $this->product->id,
            $this->warehouse->id,
            0
        );

        // ==================== MOMENTO 1: Llegan primeros pedidos ====================

        // Fecha A (Lun 10): 8 lasañas - NO overlap con OP #1 [C-D]
        $orderA = $this->createCustomerOrder('Order A - 8 lasañas', 8, '2025-11-10');

        // Fecha C (Mié 12): 5 lasañas - SÍ overlap con OP #1 [C-D]
        $orderC1 = $this->createCustomerOrder('Order C1 - 5 lasañas', 5, '2025-11-12');

        // Fecha D (Jue 13): 3 lasañas - SÍ overlap con OP #1 [C-D]
        $orderD1 = $this->createCustomerOrder('Order D1 - 3 lasañas', 3, '2025-11-13');

        // Fecha E (Vie 14): 4 lasañas - NO overlap con OP #1 [C-D]
        $orderE = $this->createCustomerOrder('Order E - 4 lasañas', 4, '2025-11-14');

        // ==================== MOMENTO 2: Se crea OP #1 [C-D] (PEQUEÑA) ====================

        $op1 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-11 08:00:00',
            'initial_dispatch_date' => '2025-11-12',  // C
            'final_dispatch_date' => '2025-11-13',    // D
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #1 - Range [C-D] - PEQUEÑA',
        ]);

        $productsDataOp1 = $this->orderRepository->getProductsFromOrdersInDateRange(
            '2025-11-12',
            '2025-11-13'
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

        // Execute OP #1
        $op1->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op1));

        // ==================== VALIDACIONES OP #1 ====================

        // Pedidos en rango [C-D]: 5 del C + 3 del D = 8
        $this->assertEquals(
            8,
            $orderedQtyOp1,
            'OP #1: ordered_quantity debe ser 8 (5 lasañas del día C + 3 lasañas del día D)'
        );

        // No hay OPs previas
        $this->assertEquals(
            0,
            $maxPrevOp1,
            'OP #1: maxPreviousQuantity debe ser 0 (no hay OPs previas)'
        );

        // Todas son nuevas
        $this->assertEquals(
            8,
            $newQtyOp1,
            'OP #1: ordered_quantity_new debe ser 8 (todas son nuevas, no hay OPs previas)'
        );

        // ==================== MOMENTO 3: Llegan pedidos NUEVOS ====================

        // Fecha B (Mar 11): 6 lasañas - NO overlap con OP #1 [C-D]
        $orderB = $this->createCustomerOrder('Order B - 6 lasañas (NUEVAS)', 6, '2025-11-11');

        // Fecha C (Mié 12): 2 lasañas NUEVAS - SÍ overlap con OP #1 (llegaron tarde)
        $orderC2 = $this->createCustomerOrder('Order C2 - 2 lasañas (NUEVAS - llegaron tarde)', 2, '2025-11-12');

        // Fecha F (Sáb 15): 5 lasañas - NO overlap con OP #1 [C-D]
        $orderF = $this->createCustomerOrder('Order F - 5 lasañas (NUEVAS)', 5, '2025-11-15');

        // ==================== MOMENTO 4: Se crea OP #2 [A-F] (GRANDE que contiene a OP #1) ====================

        $op2 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-12 08:00:00',
            'initial_dispatch_date' => '2025-11-10',  // A
            'final_dispatch_date' => '2025-11-15',    // F
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #2 - Range [A-F] - GRANDE que contiene a OP #1',
        ]);

        $productsDataOp2 = $this->orderRepository->getProductsFromOrdersInDateRange(
            '2025-11-10',
            '2025-11-15'
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

        // ==================== VALIDACIONES EXHAUSTIVAS OP #2 ====================

        // VALIDACIÓN 1: Verificar que previousOp2 detectó correctamente a OP #1
        $this->assertCount(
            1,
            $previousOp2,
            'OP #2: Debe detectar exactamente 1 OP previa (OP #1)'
        );
        $this->assertEquals(
            $op1->id,
            $previousOp2->first()->id,
            'OP #2: La OP previa detectada debe ser OP #1'
        );

        // VALIDACIÓN 2: ordered_quantity total en rango [A-F]
        // A: 8, B: 6, C: 5+2=7, D: 3, E: 4, F: 5
        // Total: 8+6+7+3+4+5 = 33
        $this->assertEquals(
            33,
            $orderedQtyOp2,
            'OP #2: ordered_quantity debe ser 33 (8+6+7+3+4+5 de todas las fechas A-F)'
        );

        // VALIDACIÓN 3: Verificar manualmente los pedidos en overlap [C-D]
        // Fecha C: 5 (iniciales) + 2 (tardíos) = 7
        // Fecha D: 3
        // Total en overlap: 10
        $ordersInOverlap = Order::whereBetween('dispatch_date', ['2025-11-12', '2025-11-13'])
            ->where('status', 'PROCESSED')
            ->whereHas('orderLines', function($q) {
                $q->where('product_id', $this->product->id);
            })
            ->with(['orderLines' => function($q) {
                $q->where('product_id', $this->product->id);
            }])
            ->get()
            ->sum(function($order) {
                return $order->orderLines->sum('quantity');
            });

        $this->assertEquals(
            10,
            $ordersInOverlap,
            'VERIFICACIÓN MANUAL: Pedidos en overlap [C-D] deben ser 10 (5+2 del C + 3 del D)'
        );

        // VALIDACIÓN 4: OP #1 cubrió 8 lasañas
        $op1Product = AdvanceOrderProduct::where('advance_order_id', $op1->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($op1Product, 'OP #1 debe tener producto asociado');
        $this->assertEquals(
            8,
            $op1Product->ordered_quantity,
            'OP #1 debe haber cubierto 8 lasañas'
        );

        // VALIDACIÓN 5: effectiveQuantityInOverlap = min(10, 8) = 8
        // maxPrevOp2 debe ser 8 (no 10)
        $this->assertEquals(
            8,
            $maxPrevOp2,
            'OP #2: maxPreviousQuantity debe ser 8 (min(10 pedidos en overlap, 8 que cubrió OP #1))'
        );

        // VALIDACIÓN 6: ordered_quantity_new = 33 - 8 = 25
        $this->assertEquals(
            25,
            $newQtyOp2,
            'OP #2: ordered_quantity_new debe ser 25 (33 total - 8 ya cubiertos por OP #1). ' .
            'Breakdown: Pedidos nuevos en A (8) + B (6) + E (4) + F (5) = 23, ' .
            'más 2 pedidos tardíos en overlap (C) = 25 total'
        );

        // VALIDACIÓN 7: Verificar que los pedidos FUERA del overlap se cuentan completos
        $ordersOutsideOverlap = 8 + 6 + 4 + 5; // A + B + E + F
        $lateOrdersInOverlap = 2; // Pedidos tardíos en C que OP #1 no cubrió
        $expectedNew = $ordersOutsideOverlap + $lateOrdersInOverlap;

        $this->assertEquals(
            25,
            $expectedNew,
            'VERIFICACIÓN MANUAL: ordered_quantity_new = 23 (fuera de overlap) + 2 (tardíos en overlap) = 25'
        );
    }

    /**
     * Test: Escenario 4 - Múltiples OPs Encadenadas (4 OPs Progresivas)
     *
     * PRODUCTO: SPAGHETTI BOLONESA
     *
     * TIMELINE:
     * A = 2025-11-10 (Lun)
     * B = 2025-11-11 (Mar)
     * C = 2025-11-12 (Mié)
     * D = 2025-11-13 (Jue)
     * E = 2025-11-14 (Vie)
     * F = 2025-11-15 (Sáb)
     * G = 2025-11-16 (Dom)
     *
     * ORDEN DE CREACIÓN:
     * → |——— OP #1 [A-C] ———|
     *   |————————— OP #2 [B-F] —————————|
     *           |——— OP #3 [D-E] ———|
     *                       |————— OP #4 [E-G] —————|
     *
     * MOMENTO 1: Llegan primeros pedidos
     * - Fecha A: 10 spaghetti
     * - Fecha B: 5 spaghetti
     * - Fecha C: 8 spaghetti
     *
     * MOMENTO 2: Se crea OP #1 [A-C]
     * - ordered_quantity = 23 (10+5+8)
     * - ordered_quantity_new = 23
     *
     * MOMENTO 3: Llegan más pedidos + se crea OP #2 [B-F]
     * - Fecha B: 3 spaghetti NUEVOS (overlap con OP #1)
     * - Fecha D: 6 spaghetti (NO overlap con OP #1)
     * - Fecha E: 4 spaghetti (NO overlap con OP #1)
     * - Fecha F: 7 spaghetti (NO overlap con OP #1)
     * - Se crea OP #2 [B-F]:
     *   - ordered_quantity = 33 (5+3+8+6+4+7)
     *   - Overlap con OP #1: [B-C]
     *   - Pedidos en overlap [B-C]: 16 (5+3 del B + 8 del C)
     *   - OP #1 cubrió: 23 total
     *   - effectiveQuantityInOverlap = min(16, 23) = 16
     *   - ordered_quantity_new = 33 - 16 = 17
     *
     * MOMENTO 4: Llegan más pedidos + se crea OP #3 [D-E]
     * - Fecha D: 2 spaghetti NUEVOS (overlap con OP #2)
     * - Fecha E: 3 spaghetti NUEVOS (overlap con OP #2)
     * - Se crea OP #3 [D-E]:
     *   - ordered_quantity = 15 (6+2+4+3)
     *   - Overlap con OP #2: [D-E] (completo)
     *   - No overlap con OP #1
     *   - Pedidos en overlap [D-E]: 15
     *   - OP #2 cubrió: 33 total, en overlap había 10 (6+4)
     *   - effectiveQuantityInOverlap = min(15, 33) = 15
     *   - ordered_quantity_new = 15 - 10 = 5
     *
     * MOMENTO 5: Llegan más pedidos + se crea OP #4 [E-G]
     * - Fecha E: 4 spaghetti NUEVOS
     * - Fecha G: 8 spaghetti
     * - Se crea OP #4 [E-G]:
     *   - ordered_quantity = 26 (4+3+4+7+8)
     *   - Overlap con OP #2: [E-F]
     *   - Overlap con OP #3: [E]
     *   - No overlap con OP #1
     *   - Pedidos en overlap con OP #2 [E-F]: 18 (4+3+4+7)
     *   - OP #2 cubrió 33 total
     *   - Pedidos en overlap con OP #3 [E]: 11 (4+3+4)
     *   - OP #3 cubrió 15 total
     *   - effectiveFromOP2 = min(18, 33) = 18
     *   - effectiveFromOP3 = min(11, 15) = 11
     *   - maxPrevious = max(18, 11) = 18
     *   - ordered_quantity_new = 26 - 18 = 8
     */
    public function test_escenario_4_multiples_ops_encadenadas_cuatro_ops_progresivas(): void
    {
        // Create SPAGHETTI product
        $spaghetti = Product::create([
            'code' => 'PLATO-SPA-BOL-2',
            'name' => 'SPAGHETTI BOLONESA',
            'description' => 'Spaghetti con salsa bolonesa',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $this->warehouseRepository->updateProductStockInWarehouse(
            $spaghetti->id,
            $this->warehouse->id,
            0
        );

        // ==================== MOMENTO 1: Llegan primeros pedidos ====================

        // Fecha A (Lun 10): 10 spaghetti
        $orderA = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-10', 'status' => 'PROCESSED', 'total' => 50000]);
        OrderLine::create(['order_id' => $orderA->id, 'product_id' => $spaghetti->id, 'quantity' => 10, 'unit_price' => 5000, 'total_price' => 50000]);

        // Fecha B (Mar 11): 5 spaghetti
        $orderB1 = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-11', 'status' => 'PROCESSED', 'total' => 25000]);
        OrderLine::create(['order_id' => $orderB1->id, 'product_id' => $spaghetti->id, 'quantity' => 5, 'unit_price' => 5000, 'total_price' => 25000]);

        // Fecha C (Mié 12): 8 spaghetti
        $orderC1 = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-12', 'status' => 'PROCESSED', 'total' => 40000]);
        OrderLine::create(['order_id' => $orderC1->id, 'product_id' => $spaghetti->id, 'quantity' => 8, 'unit_price' => 5000, 'total_price' => 40000]);

        // ==================== MOMENTO 2: Se crea OP #1 [A-C] ====================

        $op1 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-09 08:00:00',
            'initial_dispatch_date' => '2025-11-10',  // A
            'final_dispatch_date' => '2025-11-12',    // C
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #1 - Range [A-C]',
        ]);

        $productsDataOp1 = $this->orderRepository->getProductsFromOrdersInDateRange('2025-11-10', '2025-11-12');
        $previousOp1 = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($op1);
        $dataOp1 = $productsDataOp1->firstWhere('product_id', $spaghetti->id);
        $orderedQtyOp1 = $dataOp1['ordered_quantity'] ?? 0;
        $maxPrevOp1 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct($spaghetti->id, $previousOp1, $op1);
        $newQtyOp1 = max(0, $orderedQtyOp1 - $maxPrevOp1);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op1->id,
            'product_id' => $spaghetti->id,
            'quantity' => $newQtyOp1,
            'ordered_quantity' => $orderedQtyOp1,
            'ordered_quantity_new' => $newQtyOp1,
        ]);

        $op1->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op1));

        // VALIDACIONES OP #1
        $this->assertEquals(23, $orderedQtyOp1, 'OP #1: ordered_quantity = 10+5+8 = 23');
        $this->assertEquals(0, $maxPrevOp1, 'OP #1: No hay OPs previas');
        $this->assertEquals(23, $newQtyOp1, 'OP #1: ordered_quantity_new = 23 (todas nuevas)');

        // ==================== MOMENTO 3: Llegan más pedidos + crear OP #2 [B-F] ====================

        // Fecha B (Mar 11): 3 spaghetti NUEVOS (overlap con OP #1)
        $orderB2 = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-11', 'status' => 'PROCESSED', 'total' => 15000]);
        OrderLine::create(['order_id' => $orderB2->id, 'product_id' => $spaghetti->id, 'quantity' => 3, 'unit_price' => 5000, 'total_price' => 15000]);

        // Fecha D (Jue 13): 6 spaghetti (NO overlap con OP #1)
        $orderD1 = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-13', 'status' => 'PROCESSED', 'total' => 30000]);
        OrderLine::create(['order_id' => $orderD1->id, 'product_id' => $spaghetti->id, 'quantity' => 6, 'unit_price' => 5000, 'total_price' => 30000]);

        // Fecha E (Vie 14): 4 spaghetti (NO overlap con OP #1)
        $orderE1 = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-14', 'status' => 'PROCESSED', 'total' => 20000]);
        OrderLine::create(['order_id' => $orderE1->id, 'product_id' => $spaghetti->id, 'quantity' => 4, 'unit_price' => 5000, 'total_price' => 20000]);

        // Fecha F (Sáb 15): 7 spaghetti (NO overlap con OP #1)
        $orderF1 = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-15', 'status' => 'PROCESSED', 'total' => 35000]);
        OrderLine::create(['order_id' => $orderF1->id, 'product_id' => $spaghetti->id, 'quantity' => 7, 'unit_price' => 5000, 'total_price' => 35000]);

        // Crear OP #2 [B-F]
        $op2 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-10 08:00:00',
            'initial_dispatch_date' => '2025-11-11',  // B
            'final_dispatch_date' => '2025-11-15',    // F
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #2 - Range [B-F]',
        ]);

        $productsDataOp2 = $this->orderRepository->getProductsFromOrdersInDateRange('2025-11-11', '2025-11-15');
        $previousOp2 = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($op2);
        $dataOp2 = $productsDataOp2->firstWhere('product_id', $spaghetti->id);
        $orderedQtyOp2 = $dataOp2['ordered_quantity'] ?? 0;
        $maxPrevOp2 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct($spaghetti->id, $previousOp2, $op2);
        $newQtyOp2 = max(0, $orderedQtyOp2 - $maxPrevOp2);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op2->id,
            'product_id' => $spaghetti->id,
            'quantity' => $newQtyOp2,
            'ordered_quantity' => $orderedQtyOp2,
            'ordered_quantity_new' => $newQtyOp2,
        ]);

        $op2->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op2));

        // VALIDACIONES EXHAUSTIVAS OP #2
        $this->assertCount(1, $previousOp2, 'OP #2: Debe detectar 1 OP previa (OP #1)');
        $this->assertEquals($op1->id, $previousOp2->first()->id, 'OP #2: OP previa debe ser OP #1');

        // ordered_quantity en [B-F]: 5+3+8+6+4+7 = 33
        $this->assertEquals(33, $orderedQtyOp2, 'OP #2: ordered_quantity = 5+3+8+6+4+7 = 33');

        // Verificar pedidos ACTUALES en overlap [B-C] manualmente
        $ordersInOverlapBC = Order::whereBetween('dispatch_date', ['2025-11-11', '2025-11-12'])
            ->where('status', 'PROCESSED')
            ->whereHas('orderLines', fn($q) => $q->where('product_id', $spaghetti->id))
            ->with(['orderLines' => fn($q) => $q->where('product_id', $spaghetti->id)])
            ->get()
            ->sum(fn($order) => $order->orderLines->sum('quantity'));

        $this->assertEquals(16, $ordersInOverlapBC, 'VERIFICACIÓN: Pedidos ACTUALES en overlap [B-C] = 5+3+8 = 16');

        // CON PIVOTS: OP #1 solo cubrió los pedidos que existían cuando se creó (5+8=13)
        // Los 3 pedidos nuevos (orderB2) llegaron DESPUÉS de OP #1, por lo tanto son responsabilidad de OP #2
        // quantityCoveredByOP1InOverlap = 13 (desde pivot table)
        // ordered_quantity_new = 33 - 13 = 20
        $this->assertEquals(13, $maxPrevOp2, 'OP #2: maxPrevious = 13 (lo que OP #1 tenía en [B-C] cuando se creó, sin contar los 3 tardíos)');
        $this->assertEquals(20, $newQtyOp2, 'OP #2: ordered_quantity_new = 33 - 13 = 20 (incluye los 3 pedidos tardíos de fecha B)');

        // ==================== MOMENTO 4: Llegan más pedidos + crear OP #3 [D-E] ====================

        // Fecha D (Jue 13): 2 spaghetti NUEVOS (overlap con OP #2)
        $orderD2 = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-13', 'status' => 'PROCESSED', 'total' => 10000]);
        OrderLine::create(['order_id' => $orderD2->id, 'product_id' => $spaghetti->id, 'quantity' => 2, 'unit_price' => 5000, 'total_price' => 10000]);

        // Fecha E (Vie 14): 3 spaghetti NUEVOS (overlap con OP #2)
        $orderE2 = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-14', 'status' => 'PROCESSED', 'total' => 15000]);
        OrderLine::create(['order_id' => $orderE2->id, 'product_id' => $spaghetti->id, 'quantity' => 3, 'unit_price' => 5000, 'total_price' => 15000]);

        // Crear OP #3 [D-E]
        $op3 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-12 08:00:00',
            'initial_dispatch_date' => '2025-11-13',  // D
            'final_dispatch_date' => '2025-11-14',    // E
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #3 - Range [D-E]',
        ]);

        $productsDataOp3 = $this->orderRepository->getProductsFromOrdersInDateRange('2025-11-13', '2025-11-14');
        $previousOp3 = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($op3);
        $dataOp3 = $productsDataOp3->firstWhere('product_id', $spaghetti->id);
        $orderedQtyOp3 = $dataOp3['ordered_quantity'] ?? 0;
        $maxPrevOp3 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct($spaghetti->id, $previousOp3, $op3);
        $newQtyOp3 = max(0, $orderedQtyOp3 - $maxPrevOp3);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op3->id,
            'product_id' => $spaghetti->id,
            'quantity' => $newQtyOp3,
            'ordered_quantity' => $orderedQtyOp3,
            'ordered_quantity_new' => $newQtyOp3,
        ]);

        $op3->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op3));

        // VALIDACIONES EXHAUSTIVAS OP #3
        $this->assertCount(1, $previousOp3, 'OP #3: Debe detectar 1 OP previa (OP #2, no OP #1 porque no hay overlap)');
        $this->assertEquals($op2->id, $previousOp3->first()->id, 'OP #3: OP previa debe ser OP #2 solamente');

        // ordered_quantity en [D-E]: 6+2+4+3 = 15
        $this->assertEquals(15, $orderedQtyOp3, 'OP #3: ordered_quantity = 6+2+4+3 = 15');

        // Verificar pedidos en overlap [D-E] manualmente
        $ordersInOverlapDE = Order::whereBetween('dispatch_date', ['2025-11-13', '2025-11-14'])
            ->where('status', 'PROCESSED')
            ->whereHas('orderLines', fn($q) => $q->where('product_id', $spaghetti->id))
            ->with(['orderLines' => fn($q) => $q->where('product_id', $spaghetti->id)])
            ->get()
            ->sum(fn($order) => $order->orderLines->sum('quantity'));

        $this->assertEquals(15, $ordersInOverlapDE, 'VERIFICACIÓN: Pedidos actuales en overlap [D-E] = 6+2+4+3 = 15');

        // OP #2 cubrió pedidos en [D-E] ANTES de que llegaran los tardíos (6+4=10)
        // effectiveQuantityInOverlap = min(15 actuales, 33 total de OP #2)
        // PERO el código debe comparar con lo que OP #2 tenía en [D-E] cuando se creó, que era 10
        // Por lo tanto: 15 - 10 = 5
        $this->assertEquals(10, $maxPrevOp3, 'OP #3: maxPrevious debe ser 10 (lo que OP #2 tenía en [D-E] cuando se creó)');
        $this->assertEquals(5, $newQtyOp3, 'OP #3: ordered_quantity_new = 15 - 10 = 5 (pedidos tardíos)');

        // ==================== MOMENTO 5: Llegan más pedidos + crear OP #4 [E-G] ====================

        // Fecha E (Vie 14): 4 spaghetti NUEVOS
        $orderE3 = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-14', 'status' => 'PROCESSED', 'total' => 20000]);
        OrderLine::create(['order_id' => $orderE3->id, 'product_id' => $spaghetti->id, 'quantity' => 4, 'unit_price' => 5000, 'total_price' => 20000]);

        // Fecha G (Dom 16): 8 spaghetti
        $orderG = Order::create(['user_id' => $this->user->id, 'dispatch_date' => '2025-11-16', 'status' => 'PROCESSED', 'total' => 40000]);
        OrderLine::create(['order_id' => $orderG->id, 'product_id' => $spaghetti->id, 'quantity' => 8, 'unit_price' => 5000, 'total_price' => 40000]);

        // Crear OP #4 [E-G]
        $op4 = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-13 08:00:00',
            'initial_dispatch_date' => '2025-11-14',  // E
            'final_dispatch_date' => '2025-11-16',    // G
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => false,
            'description' => 'OP #4 - Range [E-G]',
        ]);

        $productsDataOp4 = $this->orderRepository->getProductsFromOrdersInDateRange('2025-11-14', '2025-11-16');
        $previousOp4 = $this->advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($op4);
        $dataOp4 = $productsDataOp4->firstWhere('product_id', $spaghetti->id);
        $orderedQtyOp4 = $dataOp4['ordered_quantity'] ?? 0;
        $maxPrevOp4 = $this->advanceOrderRepository->getMaxOrderedQuantityForProduct($spaghetti->id, $previousOp4, $op4);
        $newQtyOp4 = max(0, $orderedQtyOp4 - $maxPrevOp4);

        AdvanceOrderProduct::create([
            'advance_order_id' => $op4->id,
            'product_id' => $spaghetti->id,
            'quantity' => $newQtyOp4,
            'ordered_quantity' => $orderedQtyOp4,
            'ordered_quantity_new' => $newQtyOp4,
        ]);

        // VALIDACIONES EXHAUSTIVAS OP #4
        $this->assertCount(2, $previousOp4, 'OP #4: Debe detectar 2 OPs previas (OP #2 y OP #3)');

        $previousIds = $previousOp4->pluck('id')->sort()->values();
        $expectedIds = collect([$op2->id, $op3->id])->sort()->values();
        $this->assertEquals($expectedIds, $previousIds, 'OP #4: OPs previas deben ser OP #2 y OP #3');

        // ordered_quantity en [E-G]: 4+3+4+7+8 = 26
        $this->assertEquals(26, $orderedQtyOp4, 'OP #4: ordered_quantity = 4+3+4+7+8 = 26');

        // CON PIVOTS - OP #4 tiene overlap con OP #2 y OP #3:
        //
        // Overlap OP #4 [E-G] con OP #2 [B-F] = [E-F]
        // - OP #2 pivots en [E-F]: orderE1 (4) + orderF1 (7) = 11
        //   (NO incluye orderE2, orderE3 porque llegaron después de OP #2)
        //
        // Overlap OP #4 [E-G] con OP #3 [D-E] = [E]
        // - OP #3 pivots en [E]: orderE1 (4) + orderE2 (3) = 7
        //   (NO incluye orderE3 porque llegó después de OP #3)
        //
        // maxPrevious = MAX(11 from OP#2, 7 from OP#3) = 11
        // ordered_quantity_new = 26 - 11 = 15
        //
        // Nota: Los 4 pedidos de orderE3 son NUEVOS y deben producirse

        $this->assertEquals(11, $maxPrevOp4, 'OP #4: maxPrevious = 11 (MAX entre OP #2 con 11 en [E-F] y OP #3 con 7 en [E], desde pivots)');
        $this->assertEquals(15, $newQtyOp4, 'OP #4: ordered_quantity_new = 26 - 11 = 15');
    }
}