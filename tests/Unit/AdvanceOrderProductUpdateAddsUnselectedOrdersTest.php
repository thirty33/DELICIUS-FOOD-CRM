<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Category;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\ProductionArea;
use App\Models\AdvanceOrder;
use App\Enums\OrderStatus;
use App\Repositories\OrderRepository;
use App\Repositories\AdvanceOrderRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Test to validate that editing AdvanceOrderProduct quantity does NOT add unselected orders
 *
 * BUG SCENARIO (from production):
 * 1. User creates an AdvanceOrder by selecting 10 specific orders
 * 2. AdvanceOrder is created correctly with 10 orders in pivot tables
 * 3. User edits the manual quantity ("Adelantar") field of a product from 13 to 15
 * 4. AdvanceOrderProductObserver fires 'updated' event
 * 5. SyncAdvanceOrderPivotsListener::handleAdvanceOrderProductChanged is triggered
 * 6. syncPivotsForProduct() uses getOrdersInDateRange() which finds ALL orders in date range
 * 7. New orders (not originally selected) are added to advance_order_orders
 * 8. Result: OP now has 18 orders instead of the original 10
 *
 * EXPECTED BEHAVIOR:
 * - When updating a product's manual quantity, the listener should ONLY sync existing orders
 * - It should NOT add new orders from the date range
 * - The number of associated orders should remain constant
 */
class AdvanceOrderProductUpdateAddsUnselectedOrdersTest extends TestCase
{
    use RefreshDatabase;

    private ProductionArea $productionArea;
    private PriceList $priceList;
    private Category $category;
    private Product $product;
    private Company $company;
    private OrderRepository $orderRepository;
    private AdvanceOrderRepository $advanceOrderRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Create production area
        $this->productionArea = ProductionArea::create([
            'name' => 'TEST AREA',
            'order' => 1,
        ]);

        // Create price list
        $this->priceList = PriceList::create([
            'name' => 'Lista Test',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
        ]);

        // Create category
        $this->category = Category::create([
            'name' => 'TEST CATEGORY',
            'active' => true,
            'order' => 1,
        ]);

        // Create product
        $this->product = Product::create([
            'name' => 'TEST PRODUCT',
            'code' => 'TEST001',
            'description' => 'Test product for update listener test',
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 100,
            'allow_sales_without_stock' => true,
            'category_id' => $this->category->id,
        ]);
        $this->product->productionAreas()->attach($this->productionArea->id);

        PriceListLine::create([
            'price_list_id' => $this->priceList->id,
            'product_id' => $this->product->id,
            'price' => 1000,
        ]);

        // Create company
        $this->company = Company::create([
            'tax_id' => '11111111-1',
            'name' => 'TEST COMPANY',
            'fantasy_name' => 'TEST COMPANY',
            'address' => 'Test Address',
            'email' => 'test@test.com',
            'phone' => '111111111',
            'price_list_id' => $this->priceList->id,
            'exclude_from_consolidated_report' => false,
        ]);

        $this->orderRepository = new OrderRepository();
        $this->advanceOrderRepository = app(\App\Repositories\AdvanceOrderRepository::class);
    }

    /**
     * Test that editing product quantity does NOT add unselected orders
     *
     * SCENARIO (replicating production bug):
     * - Create 10 orders with dispatch_date = today + 5 days
     * - Create 8 additional orders with same dispatch_date (18 total in range)
     * - Select ONLY the first 10 orders to create AdvanceOrder
     * - Verify OP has 10 orders
     * - Edit the manual quantity field of the product (change from 0 to 15)
     * - Verify OP STILL has 10 orders (NOT 18)
     *
     * EXPECTED:
     * - advance_order_orders should remain with 10 records (not increase to 18)
     * - advance_order_order_lines should remain with 10 records (not increase to 18)
     * - ordered_quantity should remain 10 (not change)
     * - No unselected orders should be added to the pivot
     */
    public function test_updating_product_quantity_does_not_add_unselected_orders(): void
    {
        $dispatchDate = now()->addDays(5)->format('Y-m-d');

        // Create 10 orders that will be SELECTED
        $selectedOrderIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $branch = Branch::create([
                'company_id' => $this->company->id,
                'address' => 'Branch ' . $i,
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'nickname' => 'SELECTED.USER.' . $i,
                'name' => 'Selected User ' . $i,
                'email' => 'selected' . $i . '@test.com',
                'password' => bcrypt('password'),
                'company_id' => $this->company->id,
                'branch_id' => $branch->id,
            ]);

            $order = Order::create([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'order_number' => 'SEL-' . $i,
                'dispatch_date' => $dispatchDate,
                'status' => OrderStatus::PROCESSED->value,
                'subtotal' => 1000,
                'total' => 1000,
            ]);

            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 1000,
            ]);

            $selectedOrderIds[] = $order->id;
        }

        // Create 8 additional orders with SAME dispatch_date that should NOT be included
        $unselectedOrderIds = [];
        for ($i = 11; $i <= 18; $i++) {
            $branch = Branch::create([
                'company_id' => $this->company->id,
                'address' => 'Branch ' . $i,
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'nickname' => 'UNSELECTED.USER.' . $i,
                'name' => 'Unselected User ' . $i,
                'email' => 'unselected' . $i . '@test.com',
                'password' => bcrypt('password'),
                'company_id' => $this->company->id,
                'branch_id' => $branch->id,
            ]);

            $order = Order::create([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'order_number' => 'UNSEL-' . $i,
                'dispatch_date' => $dispatchDate,
                'status' => OrderStatus::PROCESSED->value,
                'subtotal' => 1000,
                'total' => 1000,
            ]);

            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 1000,
            ]);

            $unselectedOrderIds[] = $order->id;
        }

        // Verify setup: 18 orders in the date range
        $totalOrdersInRange = Order::where('dispatch_date', $dispatchDate)
            ->whereIn('status', ['PROCESSED', 'PARTIALLY_SCHEDULED'])
            ->count();

        $this->assertEquals(18, $totalOrdersInRange,
            'Setup: Should have 18 total orders in the dispatch date range');

        // Create AdvanceOrder from ONLY the 10 selected orders
        $advanceOrder = $this->orderRepository->createAdvanceOrderFromOrders(
            $selectedOrderIds, // Only 10 orders
            now()->addDays(4)->format('Y-m-d H:i:s'),
            [$this->productionArea->id]
        );

        // Verify initial state: 10 orders in pivot
        $initialOrderCount = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->count();

        $this->assertEquals(10, $initialOrderCount,
            'Initial state: Should have 10 orders in advance_order_orders');

        $initialLineCount = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $advanceOrder->id)
            ->count();

        $this->assertEquals(10, $initialLineCount,
            'Initial state: Should have 10 order lines in advance_order_order_lines');

        // Get the AdvanceOrderProduct
        $aop = $advanceOrder->advanceOrderProducts()
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($aop, 'Product should be in advance_order_products');

        $initialOrderedQuantity = $aop->ordered_quantity;
        $this->assertEquals(10, $initialOrderedQuantity,
            'Initial ordered_quantity should be 10');

        // === THE CRITICAL ACTION: Edit the manual quantity field ===
        // This simulates what happens when user changes "Adelantar" field in Filament from 0 to 15
        $aop->quantity = 15;
        $aop->save(); // This triggers AdvanceOrderProductObserver::updated()

        // Wait for async operations to complete
        $advanceOrder->refresh();

        // === VALIDATION 1: Order count should REMAIN 10 (NOT increase to 18) ===
        $finalOrderCount = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->count();

        $this->assertEquals(10, $finalOrderCount,
            'CRITICAL BUG: After updating product quantity, advance_order_orders should STILL have 10 orders (not 18). ' .
            'Listener should NOT add unselected orders from date range.');

        // === VALIDATION 2: Order line count should REMAIN 10 (NOT increase to 18) ===
        $finalLineCount = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $advanceOrder->id)
            ->count();

        $this->assertEquals(10, $finalLineCount,
            'CRITICAL BUG: After updating product quantity, advance_order_order_lines should STILL have 10 lines (not 18)');

        // === VALIDATION 3: ordered_quantity should REMAIN 10 ===
        $aop->refresh();
        $this->assertEquals(10, $aop->ordered_quantity,
            'ordered_quantity should remain 10 (unchanged by the quantity update)');

        // === VALIDATION 4: NO unselected orders should be in pivot ===
        $unselectedOrdersInPivot = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->whereIn('order_id', $unselectedOrderIds)
            ->count();

        $this->assertEquals(0, $unselectedOrdersInPivot,
            'CRITICAL BUG: ZERO unselected orders should be added to pivot after updating product quantity. ' .
            'Found ' . $unselectedOrdersInPivot . ' unselected orders in pivot.');

        // === VALIDATION 5: Only selected orders should be in pivot ===
        $selectedOrdersInPivot = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->whereIn('order_id', $selectedOrderIds)
            ->count();

        $this->assertEquals(10, $selectedOrdersInPivot,
            'All 10 selected orders should still be in pivot (and only them)');

        // === VALIDATION 6: Manual quantity field should be updated ===
        $this->assertEquals(15, $aop->quantity,
            'Manual quantity field should be updated to 15');

        // === VALIDATION 7: total_to_produce should be recalculated ===
        // If quantity > 0, total_to_produce = MAX(0, quantity - inventory)
        // With 0 inventory, total_to_produce should be 15
        $this->assertEquals(15, $aop->total_to_produce,
            'total_to_produce should be recalculated to 15 (quantity - 0 inventory)');
    }
}
