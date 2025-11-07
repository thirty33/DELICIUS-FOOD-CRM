<?php

namespace Tests\Unit;

use App\Enums\AdvanceOrderStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceList;
use App\Models\PriceListLine;
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
 * Test that validates correct total_to_produce calculation when warehouse stock exists
 *
 * SCENARIO:
 * - Create 44 orders for specific dispatch date
 * - OP #1: Select 4 orders (13 total units), manually set "Adelantar" to 15
 *   Expected: total_to_produce = 15, stock_after = 2 (15 - 13)
 * - Execute OP #1 (creates 2 excess units in warehouse)
 * - OP #2: Select 44 orders (34 total units, 21 new units)
 *   Expected: total_to_produce = 19 (21 - 2 from stock)
 *
 * BUG: Before fix, OP #2 showed total_to_produce = 21 (ignored warehouse stock)
 */
class AdvanceOrderWithWarehouseStockTest extends TestCase
{
    use RefreshDatabase;

    protected Carbon $dispatchDate;
    protected Product $product;
    protected Company $company;
    protected User $user;
    protected ProductionArea $productionArea;
    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        // Set fixed test date
        $this->dispatchDate = Carbon::parse('2025-11-20');
        Carbon::setTestNow('2025-11-18 10:00:00');

        // Create production area
        $this->productionArea = ProductionArea::create([
            'name' => 'Ensaladas',
            'description' => 'Área de ensaladas',
        ]);

        // Create category
        $category = Category::create([
            'name' => 'Ensaladas',
            'description' => 'Ensaladas frescas',
        ]);

        // Create product
        $this->product = Product::create([
            'name' => 'Ensalada Premium',
            'description' => 'Ensalada premium con vegetales frescos',
            'code' => 'ENS-PREM-001',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 300,
            'allow_sales_without_stock' => true,
        ]);

        // Associate product with production area
        $this->product->productionAreas()->attach($this->productionArea->id);

        // Create price list
        $priceList = PriceList::create([
            'name' => 'Lista General',
            'description' => 'Lista de precios general',
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $this->product->id,
            'price' => 5000,
        ]);

        // Create company and branch
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
            'address' => 'Test Address 123',
            'min_price_order' => 0,
        ]);

        // Create user
        $this->user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.USER',
            'email' => 'test.user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'branch_id' => $branch->id,
        ]);

        // Use existing default warehouse from migration
        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();

        // Associate product with warehouse using repository
        $warehouseRepo = app(WarehouseRepository::class);
        $warehouseRepo->associateProductToWarehouse(
            $this->product,
            $this->warehouse,
            0, // Initial stock
            'UND'
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Helper: Create order with order lines
     */
    protected function createOrder(int $quantity, string $status = 'PROCESSED'): Order
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'date' => $this->dispatchDate->copy()->subDays(2),
            'dispatch_date' => $this->dispatchDate,
            'status' => $status,
            'billing_address' => 'Test Address',
            'billing_commune' => 'Test Commune',
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 5000,
        ]);

        // Load the orderLines relationship
        $order->load('orderLines');

        return $order;
    }

    public function test_warehouse_stock_is_used_when_creating_second_production_order(): void
    {
        // ============================================================================
        // STEP 1: Create 44 orders with different quantities totaling 34 units
        // ============================================================================

        // First 4 orders will be selected for OP #1 (total: 13 units)
        $ordersForOp1 = [
            $this->createOrder(4), // Order 1: 4 units
            $this->createOrder(3), // Order 2: 3 units
            $this->createOrder(4), // Order 3: 4 units
            $this->createOrder(2), // Order 4: 2 units
            // Total: 13 units
        ];

        // Remaining 40 orders for OP #2 (total new: 21 units)
        $ordersForOp2 = [];
        for ($i = 0; $i < 40; $i++) {
            // Distribute 21 units across 40 orders: some with 1, others with 0
            $quantity = $i < 21 ? 1 : 0;
            $ordersForOp2[] = $this->createOrder($quantity);
        }

        // Verify we have 44 total orders
        $this->assertEquals(44, Order::count(), 'Should have 44 total orders');

        // Verify total quantities
        $totalQuantityOp1 = collect($ordersForOp1)->sum(fn($order) => $order->orderLines->sum('quantity'));
        $totalQuantityAll = OrderLine::sum('quantity');

        $this->assertEquals(13, $totalQuantityOp1, 'First 4 orders should total 13 units');
        $this->assertEquals(34, $totalQuantityAll, 'All 44 orders should total 34 units');

        // ============================================================================
        // STEP 2: Create OP #1 from selected 4 orders (13 units total)
        // ============================================================================

        $orderRepository = app(OrderRepository::class);
        $preparationDatetime = $this->dispatchDate->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op1 = $orderRepository->createAdvanceOrderFromOrders(
            collect($ordersForOp1)->pluck('id')->toArray(),
            $preparationDatetime,
            [$this->productionArea->id]
        );

        $this->assertNotNull($op1, 'OP #1 should be created');
        $this->assertEquals(AdvanceOrderStatus::PENDING, $op1->status);

        // Get product from OP #1
        $op1Product = $op1->advanceOrderProducts()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($op1Product, 'OP #1 should have the product');

        // Verify initial values
        $this->assertEquals(13, $op1Product->ordered_quantity, 'OP #1: ordered_quantity should be 13');
        $this->assertEquals(13, $op1Product->ordered_quantity_new, 'OP #1: ordered_quantity_new should be 13 (no previous OPs)');
        $this->assertEquals(0, $op1Product->quantity, 'OP #1: manual quantity should be 0 initially');
        $this->assertEquals(13, $op1Product->total_to_produce, 'OP #1: total_to_produce should be 13 initially (13 - 0 stock)');

        // ============================================================================
        // STEP 3: Manually adjust "Adelantar" field to 15 (simulating Filament input)
        // ============================================================================

        // Simulate what happens when user changes "quantity" field in Filament
        // The Observer will recalculate total_to_produce
        $op1Product->quantity = 15;
        $op1Product->save(); // This triggers Observer which calls calculateTotalToProduce()

        // Reload to get updated values
        $op1Product->refresh();

        $this->assertEquals(15, $op1Product->quantity, 'OP #1: manual quantity should be 15 after update');
        $this->assertEquals(15, $op1Product->total_to_produce, 'OP #1: total_to_produce should be 15 (MAX(0, 15 - 0))');

        // ============================================================================
        // STEP 4: Execute OP #1 (creates warehouse transaction and updates stock)
        // ============================================================================

        // Authenticate the user for the transaction
        $this->actingAs($this->user);

        $op1->update(['status' => AdvanceOrderStatus::EXECUTED]);

        // Fire the AdvanceOrderExecuted event (simulating what Filament does)
        event(new \App\Events\AdvanceOrderExecuted($op1));

        // Get warehouse transaction for OP #1
        $transaction1 = \App\Models\WarehouseTransaction::where('advance_order_id', $op1->id)->first();
        $this->assertNotNull($transaction1, 'Warehouse transaction should exist for OP #1');

        $transactionLine1 = $transaction1->lines()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($transactionLine1, 'Transaction line should exist for product in OP #1');

        // Verify transaction calculations
        // Formula: stock_after = stock_before + total_to_produce - ordered_quantity_new
        // stock_after = 0 + 15 - 13 = 2
        $this->assertEquals(0, $transactionLine1->stock_before, 'OP #1: stock_before should be 0');
        $this->assertEquals(2, $transactionLine1->stock_after, 'OP #1: stock_after should be 2 (0 + 15 - 13)');
        $this->assertEquals(2, $transactionLine1->difference, 'OP #1: difference should be 2');

        // Verify actual warehouse stock was updated
        $warehouseRepo = app(WarehouseRepository::class);

        // Debug: Check if warehouse product relation exists
        $pivotExists = DB::table('warehouse_product')
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->exists();

        $this->assertTrue($pivotExists, 'Warehouse product pivot should exist');

        $currentStock = $warehouseRepo->getProductStockInWarehouse($this->product->id, $this->warehouse->id);
        $this->assertEquals(2, $currentStock, 'Warehouse should have 2 units in stock after OP #1 execution');

        // ============================================================================
        // STEP 5: Create OP #2 from all 44 orders (34 total units, 21 new units)
        // ============================================================================

        // Advance time to create second OP
        Carbon::setTestNow('2025-11-18 12:00:00');

        $allOrderIds = Order::pluck('id')->toArray();

        $op2 = $orderRepository->createAdvanceOrderFromOrders(
            $allOrderIds,
            $preparationDatetime,
            [$this->productionArea->id]
        );

        $this->assertNotNull($op2, 'OP #2 should be created');

        // Get product from OP #2
        $op2Product = $op2->advanceOrderProducts()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($op2Product, 'OP #2 should have the product');

        // ============================================================================
        // STEP 6: Verify OP #2 calculations (THE CRITICAL TEST)
        // ============================================================================

        $this->assertEquals(34, $op2Product->ordered_quantity, 'OP #2: ordered_quantity should be 34 (total from all orders)');
        $this->assertEquals(21, $op2Product->ordered_quantity_new, 'OP #2: ordered_quantity_new should be 21 (34 - 13 from OP #1)');
        $this->assertEquals(0, $op2Product->quantity, 'OP #2: manual quantity should be 0');

        // THE KEY ASSERTION: total_to_produce should consider warehouse stock
        // Formula: MAX(0, ordered_quantity_new - initial_inventory)
        // total_to_produce = MAX(0, 21 - 2) = 19
        $this->assertEquals(
            19,
            $op2Product->total_to_produce,
            'OP #2: total_to_produce should be 19 (21 new orders - 2 units in stock)'
        );

        // ============================================================================
        // STEP 7: Execute OP #2 and verify final warehouse state
        // ============================================================================

        $op2->update(['status' => AdvanceOrderStatus::EXECUTED]);

        // Fire the AdvanceOrderExecuted event (simulating what Filament does)
        event(new \App\Events\AdvanceOrderExecuted($op2));

        // Get warehouse transaction for OP #2
        $transaction2 = \App\Models\WarehouseTransaction::where('advance_order_id', $op2->id)->first();
        $this->assertNotNull($transaction2, 'OP #2 should have warehouse transaction');

        $transactionLine2 = $transaction2->lines()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($transactionLine2, 'OP #2 should have transaction line for product');

        // Verify transaction calculations for OP #2
        // Formula: stock_after = stock_before + total_to_produce - ordered_quantity_new
        // stock_after = 2 + 19 - 21 = 0
        $this->assertEquals(2, $transactionLine2->stock_before, 'OP #2: stock_before should be 2 (from OP #1)');
        $this->assertEquals(0, $transactionLine2->stock_after, 'OP #2: stock_after should be 0 (2 + 19 - 21)');
        $this->assertEquals(-2, $transactionLine2->difference, 'OP #2: difference should be -2 (used the 2 from stock)');

        // Verify final warehouse stock
        $finalStock = $warehouseRepo->getProductStockInWarehouse($this->product->id, $this->warehouse->id);
        $this->assertEquals(0, $finalStock, 'Final warehouse stock should be 0 (all excess used)');

        // ============================================================================
        // VERIFICATION SUMMARY
        // ============================================================================

        // Total produced: 15 (OP #1) + 19 (OP #2) = 34 units
        // Total ordered: 34 units
        // Stock flow: 0 → +2 (after OP #1) → 0 (after OP #2)

        $totalProduced = $op1Product->total_to_produce + $op2Product->total_to_produce;
        $this->assertEquals(34, $totalProduced, 'Total produced should equal total ordered (34 units)');
    }
}
