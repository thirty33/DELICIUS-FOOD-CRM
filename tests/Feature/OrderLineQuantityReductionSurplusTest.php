<?php

namespace Tests\Feature;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderProductionStatus;
use App\Enums\OrderStatus;
use App\Enums\WarehouseTransactionStatus;
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
use App\Models\WarehouseTransaction;
use App\Repositories\OrderRepository;
use App\Repositories\WarehouseRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Order Line Quantity Reduction Surplus Test
 *
 * Tests that when an order_line quantity is reduced and the order/line
 * is already produced (fully or partially), a warehouse transaction
 * is created to add the surplus back to inventory.
 *
 * CASES:
 * 4.1) Order_line fully produced: qty=10, produced=10, new_qty=8 → surplus=2
 * 4.2) Order_line partially produced, no surplus: qty=5, produced=4, new_qty=4 → surplus=0
 * 4.3) Order_line partially produced, with surplus: qty=5, produced=4, new_qty=3 → surplus=1
 */
class OrderLineQuantityReductionSurplusTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $dispatchDate;
    private User $user;
    private Company $company;
    private Category $category;
    private ProductionArea $productionArea;
    private Warehouse $warehouse;
    private Product $product;
    private Order $order;
    private OrderRepository $orderRepository;
    private WarehouseRepository $warehouseRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatchDate = Carbon::parse('2025-11-20');
        Carbon::setTestNow('2025-11-18 10:00:00');

        $this->orderRepository = app(OrderRepository::class);
        $this->warehouseRepository = app(WarehouseRepository::class);

        $this->createTestEnvironment();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Case 4.1: Order_line fully produced
     *
     * - Order_line: qty=10, produced=10 (fully produced)
     * - Reduce qty to 8
     * - Expected: surplus=2, warehouse transaction created, stock +2
     */
    public function test_fully_produced_order_line_creates_surplus_transaction(): void
    {
        // ARRANGE: Create order with qty=10
        $this->createOrder(10);

        // Create and execute OP to fully produce the order
        $op = $this->createAndExecuteOp([$this->order->id]);

        // Verify order_line is fully produced
        $this->order->refresh();
        $orderLine = $this->order->orderLines->first();
        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $orderLine->production_status
        );

        // Get initial stock after OP execution
        $stockBefore = $this->getStock($this->product);

        // Count transactions before modification
        $transactionCountBefore = WarehouseTransaction::count();

        // ACT: Reduce order_line quantity from 10 to 8
        $orderLine->update(['quantity' => 8]);

        // ASSERT: Surplus transaction should be created
        $transactionCountAfter = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountBefore + 1,
            $transactionCountAfter,
            'A new warehouse transaction should be created for surplus'
        );

        // Verify the surplus transaction (use latest by ID for reliability)
        $surplusTransaction = WarehouseTransaction::latest('id')->first();
        $this->assertStringContains('Sobrante', $surplusTransaction->reason);

        // Verify transaction line has correct surplus (2)
        $transactionLine = $surplusTransaction->lines()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($transactionLine);
        $this->assertEquals(2, $transactionLine->difference, 'Surplus should be 2');

        // Verify stock increased by 2
        $stockAfter = $this->getStock($this->product);
        $this->assertEquals($stockBefore + 2, $stockAfter, 'Stock should increase by 2');
    }

    /**
     * Case 4.2: Order_line partially produced, reduction does NOT create surplus
     *
     * - Order_line: qty=5, produced=4 (partially produced)
     * - Reduce qty to 4 (removing 1 unproduced unit)
     * - Expected: surplus=0, NO warehouse transaction
     */
    public function test_partially_produced_order_line_no_surplus_when_reducing_unproduced(): void
    {
        // ARRANGE: Create order with qty=5
        $this->createOrder(5);

        // Create OP but only produce 4 units (partial) - originalQty=5, produced=4
        $op = $this->createOpWithPartialProduction([$this->order->id], 5, 4);
        $this->executeOp($op);

        // Verify order_line is partially produced
        $this->order->refresh();
        $orderLine = $this->order->orderLines->first();
        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $orderLine->production_status
        );

        // Verify produced quantity is 4
        $produced = $this->orderRepository->getTotalProducedForProduct($this->order->id, $this->product->id);
        $this->assertEquals(4, $produced, 'Should have 4 units produced');

        // Count transactions before modification
        $transactionCountBefore = WarehouseTransaction::count();
        $stockBefore = $this->getStock($this->product);

        // ACT: Reduce order_line quantity from 5 to 4 (removing 1 unproduced)
        $orderLine->update(['quantity' => 4]);

        // ASSERT: NO new transaction (surplus = max(0, 4-4) = 0)
        $transactionCountAfter = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountBefore,
            $transactionCountAfter,
            'No warehouse transaction should be created (no surplus)'
        );

        // Stock should remain the same
        $stockAfter = $this->getStock($this->product);
        $this->assertEquals($stockBefore, $stockAfter, 'Stock should not change');
    }

    /**
     * Case 4.3: Order_line partially produced, reduction creates surplus
     *
     * - Order_line: qty=5, produced=4 (partially produced)
     * - Reduce qty to 3 (removing 2, but only 1 was unproduced)
     * - Expected: surplus=1 (4 produced - 3 new qty), warehouse transaction created
     */
    public function test_partially_produced_order_line_creates_surplus_when_reducing_below_produced(): void
    {
        // ARRANGE: Create order with qty=5
        $this->createOrder(5);

        // Create OP but only produce 4 units (partial) - originalQty=5, produced=4
        $op = $this->createOpWithPartialProduction([$this->order->id], 5, 4);
        $this->executeOp($op);

        // Verify order_line is partially produced
        $this->order->refresh();
        $orderLine = $this->order->orderLines->first();
        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $orderLine->production_status
        );

        // Verify produced quantity is 4
        $produced = $this->orderRepository->getTotalProducedForProduct($this->order->id, $this->product->id);
        $this->assertEquals(4, $produced, 'Should have 4 units produced');

        // Count transactions before modification
        $transactionCountBefore = WarehouseTransaction::count();
        $stockBefore = $this->getStock($this->product);

        // ACT: Reduce order_line quantity from 5 to 3 (surplus = 4-3 = 1)
        $orderLine->update(['quantity' => 3]);

        // ASSERT: New transaction should be created for surplus of 1
        $transactionCountAfter = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountBefore + 1,
            $transactionCountAfter,
            'A new warehouse transaction should be created for surplus'
        );

        // Verify the surplus transaction (use latest by ID for reliability)
        $surplusTransaction = WarehouseTransaction::latest('id')->first();
        $this->assertStringContains('Sobrante', $surplusTransaction->reason);

        // Verify transaction line has correct surplus (1)
        $transactionLine = $surplusTransaction->lines()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($transactionLine);
        $this->assertEquals(1, $transactionLine->difference, 'Surplus should be 1');

        // Verify stock increased by 1
        $stockAfter = $this->getStock($this->product);
        $this->assertEquals($stockBefore + 1, $stockAfter, 'Stock should increase by 1');
    }

    /**
     * Test: No transaction when order is NOT produced
     */
    public function test_no_transaction_when_order_not_produced(): void
    {
        // ARRANGE: Create order with qty=10 but do NOT execute any OP
        $this->createOrder(10);

        // Run update command to set status
        $this->artisan('orders:update-production-status');

        // Verify order_line is NOT produced
        $this->order->refresh();
        $orderLine = $this->order->orderLines->first();
        $this->assertEquals(
            OrderProductionStatus::NOT_PRODUCED->value,
            $orderLine->production_status
        );

        $transactionCountBefore = WarehouseTransaction::count();

        // ACT: Reduce quantity
        $orderLine->update(['quantity' => 5]);

        // ASSERT: No transaction
        $transactionCountAfter = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountBefore,
            $transactionCountAfter,
            'No transaction when order is not produced'
        );
    }

    /**
     * Test: Complete flow - multiple OPs, quantity increase, then decrease with surplus
     *
     * Flow:
     * 1) Order arrives with qty=4 for a product
     * 2) OP created and executed (fully produced)
     * 3) Order modified: qty increased to 10 (+6 units)
     * 4) Verify NO surplus transaction created (qty increased, not decreased)
     * 5) Second OP created and executed for the additional 6 units
     * 6) Verify product is fully produced again
     * 7) Order modified: qty reduced to 7 (-3 units)
     * 8) Verify surplus transaction created with executed status
     * 9) Verify inventory reflects the surplus (3 units added)
     */
    public function test_complete_flow_multiple_ops_increase_then_decrease(): void
    {
        // STEP 1: Create order with qty=4
        $this->createOrder(4);
        $this->order->load('orderLines');
        $orderLine = $this->order->orderLines->first();
        $this->assertNotNull($orderLine, 'Order line should exist');
        $this->assertEquals(4, $orderLine->quantity, 'Initial quantity should be 4');

        // STEP 2: Create and execute first OP (produces 4 units)
        $op1 = $this->createAndExecuteOp([$this->order->id]);

        // Verify fully produced
        $this->order->refresh();
        $orderLine->refresh();
        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $orderLine->production_status,
            'Order line should be FULLY_PRODUCED after first OP'
        );

        $transactionCountAfterOp1 = WarehouseTransaction::count();
        $this->assertEquals(1, $transactionCountAfterOp1, 'Should have 1 transaction after first OP');

        // STEP 3: Increase order quantity from 4 to 10
        $orderLine->update(['quantity' => 10]);

        // STEP 4: Verify NO surplus transaction (qty increased, not decreased)
        $transactionCountAfterIncrease = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountAfterOp1,
            $transactionCountAfterIncrease,
            'No surplus transaction should be created when quantity INCREASES'
        );

        // Update production status after quantity change
        $this->artisan('orders:update-production-status');
        $orderLine->refresh();
        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $orderLine->production_status,
            'Order line should be PARTIALLY_PRODUCED after quantity increase'
        );

        // STEP 5: Create and execute second OP for the additional 6 units
        $op2 = $this->createAndExecuteOp([$this->order->id]);

        // STEP 6: Verify fully produced again
        $this->artisan('orders:update-production-status');
        $orderLine->refresh();

        $produced = $this->orderRepository->getTotalProducedForProduct($this->order->id, $this->product->id);
        $this->assertEquals(10, $produced, 'Should have 10 units produced after second OP');

        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $orderLine->production_status,
            'Order line should be FULLY_PRODUCED after second OP'
        );

        $transactionCountAfterOp2 = WarehouseTransaction::count();
        $this->assertEquals(2, $transactionCountAfterOp2, 'Should have 2 transactions after second OP');

        // Get stock before reduction
        $stockBeforeReduction = $this->getStock($this->product);

        // STEP 7: Reduce order quantity from 10 to 7 (-3 units)
        $orderLine->update(['quantity' => 7]);

        // STEP 8: Verify surplus transaction created with EXECUTED status
        $transactionCountAfterReduction = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountAfterOp2 + 1,
            $transactionCountAfterReduction,
            'Surplus transaction should be created when reducing below produced amount'
        );

        $surplusTransaction = WarehouseTransaction::latest('id')->first();
        $this->assertStringContains('Sobrante', $surplusTransaction->reason);
        $this->assertEquals(
            WarehouseTransactionStatus::EXECUTED->value,
            $surplusTransaction->status->value,
            'Surplus transaction should have EXECUTED status'
        );

        // Verify transaction line has correct surplus (3)
        $transactionLine = $surplusTransaction->lines()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($transactionLine, 'Transaction line should exist');
        $this->assertEquals(3, $transactionLine->difference, 'Surplus should be 3 units');

        // STEP 9: Verify inventory reflects the surplus
        $stockAfterReduction = $this->getStock($this->product);
        $this->assertEquals(
            $stockBeforeReduction + 3,
            $stockAfterReduction,
            'Stock should increase by 3 units (the surplus)'
        );

        // Final verification: production status should reflect the new quantity
        $this->artisan('orders:update-production-status');
        $orderLine->refresh();
        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $orderLine->production_status,
            'Order line should still be FULLY_PRODUCED (10 produced >= 7 needed)'
        );
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function createTestEnvironment(): void
    {
        $this->productionArea = ProductionArea::create([
            'name' => 'Test Production Area',
            'description' => 'Production area for testing',
        ]);

        $this->category = Category::create([
            'name' => 'Test Category',
            'description' => 'Category for testing',
        ]);

        $this->product = $this->createProduct('Test Product');

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

        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();

        $this->warehouseRepository->associateProductToWarehouse($this->product, $this->warehouse, 0, 'UND');
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

    private function createOrder(int $quantity): void
    {
        $this->order = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => $this->dispatchDate->toDateString(),
            'date' => $this->dispatchDate->toDateString(),
            'status' => OrderStatus::PROCESSED->value,
            'total' => 10000,
            'dispatch_cost' => 0,
            'production_status_needs_update' => true,
        ]);

        OrderLine::create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 1000,
        ]);
    }

    private function createAndExecuteOp(array $orderIds): AdvanceOrder
    {
        $preparationDatetime = $this->dispatchDate->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op = $this->orderRepository->createAdvanceOrderFromOrders(
            $orderIds,
            $preparationDatetime,
            [$this->productionArea->id]
        );

        $this->executeOp($op);

        return $op;
    }

    private function createOpWithPartialProduction(array $orderIds, int $originalQty, int $producedQuantity): AdvanceOrder
    {
        $preparationDatetime = $this->dispatchDate->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op = $this->orderRepository->createAdvanceOrderFromOrders(
            $orderIds,
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // Formula: actualProduced = quantity_covered * (ordered_quantity_new / ordered_quantity)
        // quantity_covered = originalQty (full order_line covered)
        // ordered_quantity_new = producedQuantity (partial production)
        // Result: actualProduced = originalQty * (producedQuantity / originalQty) = producedQuantity
        DB::table('advance_order_products')
            ->where('advance_order_id', $op->id)
            ->where('product_id', $this->product->id)
            ->update([
                'ordered_quantity' => $originalQty,
                'ordered_quantity_new' => $producedQuantity,
                'total_to_produce' => $producedQuantity,
            ]);

        DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity_covered' => $originalQty]);

        return $op;
    }

    private function executeOp(AdvanceOrder $op): void
    {
        $this->actingAs($this->user);
        $op->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op));

        // Mark orders for production status update (the Job runs sync in tests)
        $relatedOrderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op->id)
            ->pluck('order_id')
            ->toArray();

        if (!empty($relatedOrderIds)) {
            DB::table('orders')
                ->whereIn('id', $relatedOrderIds)
                ->update(['production_status_needs_update' => true]);
        }

        $this->artisan('orders:update-production-status');
    }

    private function getStock(Product $product): int
    {
        return $this->warehouseRepository->getProductStockInWarehouse(
            $product->id,
            $this->warehouse->id
        );
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '$haystack' contains '$needle'"
        );
    }
}
