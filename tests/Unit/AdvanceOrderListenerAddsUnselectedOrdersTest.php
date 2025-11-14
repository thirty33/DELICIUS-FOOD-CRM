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
 * Test to validate that SyncAdvanceOrderPivotsListener does NOT add unselected orders
 *
 * BUG SCENARIO:
 * - User selects 10 orders to create an AdvanceOrder
 * - createAdvanceOrderFromOrders() correctly calculates ordered_quantity based on those 10 orders
 * - SyncAdvanceOrderPivotsListener is triggered
 * - Listener uses getOrdersInDateRange() which finds ALL orders in the date range (not just the selected 10)
 * - Listener syncs 24 orders instead of the 10 selected
 * - ordered_quantity remains based on 10 orders, but pivot has 24 orders
 * - Report shows inconsistent data: TOTAL PEDIDOS (from 10 orders) != sum of companies (from 24 orders)
 *
 * EXPECTED BEHAVIOR:
 * - Listener should only sync the orders that were originally selected
 * - OR if it syncs additional orders, it must recalculate ordered_quantity
 */
class AdvanceOrderListenerAddsUnselectedOrdersTest extends TestCase
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
            'description' => 'Test product for listener test',
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
     * Test that listener does NOT add unselected orders
     *
     * SCENARIO:
     * - Create 10 orders with dispatch_date = today + 5 days, each with 1 product (total: 10)
     * - Create 14 additional orders with same dispatch_date (total in range: 24)
     * - Select ONLY the first 10 orders to create AdvanceOrder
     * - Listener should sync ONLY those 10 orders, NOT the 24 orders in the date range
     *
     * EXPECTED:
     * - advance_order_orders should have 10 records (not 24)
     * - advance_order_order_lines should have 10 records (not 24)
     * - ordered_quantity should be 10 (not 2 or any other incorrect value)
     * - Report should show consistent data
     */
    public function test_listener_does_not_add_unselected_orders(): void
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

        // Create 14 additional orders with SAME dispatch_date that should NOT be included
        for ($i = 11; $i <= 24; $i++) {
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
        }

        // Verify that there are 24 orders in the date range
        $totalOrdersInRange = Order::where('dispatch_date', $dispatchDate)
            ->whereIn('status', ['PROCESSED', 'PARTIALLY_SCHEDULED'])
            ->count();

        $this->assertEquals(24, $totalOrdersInRange,
            'Setup: Should have 24 total orders in the dispatch date range');

        // Create AdvanceOrder from ONLY the 10 selected orders
        $advanceOrder = $this->orderRepository->createAdvanceOrderFromOrders(
            $selectedOrderIds, // Only 10 orders
            now()->addDays(4)->format('Y-m-d H:i:s'),
            [$this->productionArea->id]
        );

        // === VALIDATION 1: Only 10 orders should be in advance_order_orders ===
        $ordersInPivot = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->count();

        $this->assertEquals(10, $ordersInPivot,
            'CRITICAL: advance_order_orders should have ONLY 10 records (the selected orders), not 24');

        // === VALIDATION 2: Only 10 order lines should be in advance_order_order_lines ===
        $linesInPivot = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $advanceOrder->id)
            ->count();

        $this->assertEquals(10, $linesInPivot,
            'CRITICAL: advance_order_order_lines should have ONLY 10 records (from selected orders), not 24');

        // === VALIDATION 3: ordered_quantity should be 10 (from 10 selected orders) ===
        $aop = $advanceOrder->advanceOrderProducts()
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($aop, 'Product should be in advance_order_products');
        $this->assertEquals(10, $aop->ordered_quantity,
            'CRITICAL: ordered_quantity should be 10 (sum of 10 selected orders)');

        // === VALIDATION 4: Sum of quantities in pivot should equal ordered_quantity ===
        $sumFromPivot = DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->where('advance_order_order_lines.advance_order_id', $advanceOrder->id)
            ->where('order_lines.product_id', $this->product->id)
            ->sum('order_lines.quantity');

        $this->assertEquals($aop->ordered_quantity, $sumFromPivot,
            'CRITICAL: ordered_quantity must match the sum of quantities in advance_order_order_lines');

        $this->assertEquals(10, $sumFromPivot,
            'Sum from pivot should be 10 (from 10 selected orders)');

        // === VALIDATION 5: Verify NO unselected orders are in the pivot ===
        $selectedOrdersInPivot = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->whereIn('order_id', $selectedOrderIds)
            ->count();

        $this->assertEquals(10, $selectedOrdersInPivot,
            'All 10 selected orders should be in pivot');

        $unselectedOrdersInPivot = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->whereNotIn('order_id', $selectedOrderIds)
            ->count();

        $this->assertEquals(0, $unselectedOrdersInPivot,
            'CRITICAL: ZERO unselected orders should be in pivot (listener should not add them)');

        // === VALIDATION 6: Report data consistency ===
        $reportData = $this->advanceOrderRepository->getAdvanceOrderProductsGroupedByProductionArea([$advanceOrder->id]);

        $this->assertCount(1, $reportData, 'Should have 1 production area');

        $areaData = $reportData->first();
        $products = collect($areaData['products'])->where('product_id', $this->product->id);

        $this->assertCount(1, $products, 'Should have 1 product entry');

        $reportProduct = $products->first();

        $this->assertEquals(10, $reportProduct['total_ordered_quantity'],
            'Report: TOTAL PEDIDOS should be 10 (consistent with ordered_quantity)');

        // === VALIDATION 7: No unselected orders in order lines ===
        $unselectedOrderLinesInPivot = DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->where('advance_order_order_lines.advance_order_id', $advanceOrder->id)
            ->whereNotIn('order_lines.order_id', $selectedOrderIds)
            ->count();

        $this->assertEquals(0, $unselectedOrderLinesInPivot,
            'CRITICAL: ZERO order lines from unselected orders should be in pivot');
    }
}
