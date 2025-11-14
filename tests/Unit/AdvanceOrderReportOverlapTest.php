<?php

namespace Tests\Unit;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderStatus;
use App\Models\AdvanceOrder;
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
use App\Repositories\AdvanceOrderRepository;
use App\Repositories\OrderRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test Report Calculation with Overlapping OPs
 *
 * SCENARIO (replicates production bug):
 * 1. Create 2 orders for COCA COLA ZERO on 2025-11-05
 * 2. Create OP #1 with order #1 (ordered_quantity = 1, ordered_quantity_new = 1)
 * 3. Create OP #2 with orders #1 and #2 (ordered_quantity = 2, ordered_quantity_new = 1)
 *
 * CURRENT BEHAVIOR (WRONG):
 * - Report shows "Total Pedidos" = 1 + 2 = 3 (sum of ordered_quantity)
 *
 * EXPECTED BEHAVIOR (CORRECT):
 * - Report should show "Total Pedidos" = 1 + 1 = 2 (sum of ordered_quantity_new)
 * - This represents the REAL unique orders without duplicates
 */
class AdvanceOrderReportOverlapTest extends TestCase
{
    use RefreshDatabase;

    private OrderRepository $orderRepository;
    private AdvanceOrderRepository $advanceOrderRepository;
    private ProductionArea $productionArea;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-11-01 00:00:00');

        // Setup basic data
        $this->productionArea = ProductionArea::create([
            'name' => 'TEST AREA',
            'order' => 1,
        ]);

        $priceList = PriceList::create([
            'name' => 'Lista Test',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
        ]);

        $category = Category::create([
            'name' => 'BEBESTIBLES',
            'active' => true,
            'order' => 1,
        ]);

        $company = Company::create([
            'tax_id' => '11111111-1',
            'name' => 'TEST COMPANY',
            'fantasy_name' => 'TEST COMPANY',
            'address' => 'Test Address',
            'email' => 'test@test.com',
            'phone' => '111111111',
            'price_list_id' => $priceList->id,
            'exclude_from_consolidated_report' => false,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'address' => 'Test Address',
            'min_price_order' => 0,
        ]);

        $user = User::create([
            'nickname' => 'TEST.USER',
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'validate_subcategory_rules' => false,
        ]);

        $this->product = Product::create([
            'name' => 'COCA COLA ZERO 350 ML',
            'description' => 'Bebida',
            'code' => 'BEB-COCA-ZERO',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 350,
            'allow_sales_without_stock' => true,
        ]);

        $this->product->productionAreas()->attach($this->productionArea->id);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $this->product->id,
            'amount' => 1000,
        ]);

        // Create 2 orders
        $dispatchDate = Carbon::parse('2025-11-05');

        $order1 = Order::create([
            'user_id' => $user->id,
            'date' => $dispatchDate->format('Y-m-d'),
            'dispatch_date' => $dispatchDate->format('Y-m-d'),
            'status' => OrderStatus::PROCESSED,
            'notes' => 'Order 1',
        ]);

        OrderLine::create([
            'order_id' => $order1->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
        ]);

        $order2 = Order::create([
            'user_id' => $user->id,
            'date' => $dispatchDate->format('Y-m-d'),
            'dispatch_date' => $dispatchDate->format('Y-m-d'),
            'status' => OrderStatus::PROCESSED,
            'notes' => 'Order 2',
        ]);

        OrderLine::create([
            'order_id' => $order2->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'price' => 1000,
        ]);

        $this->orderRepository = new OrderRepository();
        $this->advanceOrderRepository = app(\App\Repositories\AdvanceOrderRepository::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_report_shows_correct_total_orders_with_overlap(): void
    {
        $dispatchDate = Carbon::parse('2025-11-05');
        $preparationDate = $dispatchDate->copy()->subDays(3);

        // Get all orders
        $allOrders = Order::where('dispatch_date', $dispatchDate->format('Y-m-d'))
            ->orderBy('id')
            ->get();

        $this->assertEquals(2, $allOrders->count(), 'Should have 2 orders created');

        // Create OP #1 with only order #1
        $op1 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$allOrders[0]->id], // Only first order
            $preparationDate->format('Y-m-d H:i:s'),
            [$this->productionArea->id]
        );

        $op1->update(['status' => AdvanceOrderStatus::EXECUTED]);

        // Verify OP #1 data
        $op1Product = $op1->advanceOrderProducts()->where('product_id', $this->product->id)->first();
        $this->assertEquals(1, $op1Product->ordered_quantity, 'OP #1 ordered_quantity should be 1');
        $this->assertEquals(1, $op1Product->ordered_quantity_new, 'OP #1 ordered_quantity_new should be 1');

        // Create OP #2 with both orders (overlap with OP #1)
        $op2 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$allOrders[0]->id, $allOrders[1]->id], // Both orders
            $preparationDate->format('Y-m-d H:i:s'),
            [$this->productionArea->id]
        );

        $op2->update(['status' => AdvanceOrderStatus::EXECUTED]);

        // Verify OP #2 data
        $op2Product = $op2->advanceOrderProducts()->where('product_id', $this->product->id)->first();
        $this->assertEquals(2, $op2Product->ordered_quantity, 'OP #2 ordered_quantity should be 2 (both orders)');
        $this->assertEquals(1, $op2Product->ordered_quantity_new, 'OP #2 ordered_quantity_new should be 1 (only order #2 is new)');

        // Get report data
        $reportData = $this->advanceOrderRepository->getAdvanceOrderProductsGroupedByProductionArea([
            $op1->id,
            $op2->id,
        ]);

        // Find our product in the report
        $productData = null;
        foreach ($reportData as $area) {
            foreach ($area['products'] as $prod) {
                if ($prod['product_id'] === $this->product->id) {
                    $productData = $prod;
                    break 2;
                }
            }
        }

        $this->assertNotNull($productData, 'Product should be in report');

        // CRITICAL ASSERTION: total_ordered_quantity should be 2 (sum of ordered_quantity_new)
        // NOT 3 (sum of ordered_quantity which includes duplicates)
        $this->assertEquals(2, $productData['total_ordered_quantity'],
            'TOTAL PEDIDOS should be 2 (sum of ordered_quantity_new: 1 + 1), NOT 3 (sum of ordered_quantity: 1 + 2)');

        // Verify individual OP data in report
        $this->assertArrayHasKey($op1->id, $productData['ops'], 'Report should include OP #1');
        $this->assertArrayHasKey($op2->id, $productData['ops'], 'Report should include OP #2');

        // Verify total_to_produce is also correct
        $totalElaborado = $productData['ops'][$op1->id]['total_to_produce'] +
                         $productData['ops'][$op2->id]['total_to_produce'];

        $this->assertEquals(2, $totalElaborado, 'Total Elaborado should be 2 (1 + 1)');
    }
}
