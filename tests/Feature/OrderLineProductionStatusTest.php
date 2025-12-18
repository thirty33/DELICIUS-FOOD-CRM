<?php

namespace Tests\Feature;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderProductionStatus;
use App\Enums\OrderStatus;
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
use App\Repositories\OrderRepository;
use App\Repositories\WarehouseRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Order Line Production Status Test
 *
 * Validates that each order_line has its own production_status field
 * that is updated when the orders:update-production-status command runs.
 *
 * Based on PartiallyScheduledOrderFlowTest patterns.
 */
class OrderLineProductionStatusTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $dispatchDate;
    private User $user;
    private Company $company;
    private Category $category;
    private ProductionArea $productionArea;
    private Warehouse $warehouse;
    private Product $productA;
    private Product $productB;
    private Product $productC;
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
     * Test that order_lines have production_status updated when OP is executed.
     *
     * Flow:
     * 1. Create order with 3 products - all should be NOT_PRODUCED
     * 2. Create and execute OP - all should be FULLY_PRODUCED
     */
    public function test_order_lines_have_production_status_field_updated(): void
    {
        // ARRANGE: Create order with 3 products
        $this->createOrderWithThreeProducts();

        // ASSERT MOMENT 1: All order_lines should be NOT_PRODUCED initially
        $this->order->refresh();
        $this->order->load('orderLines');

        foreach ($this->order->orderLines as $line) {
            $this->assertEquals(
                OrderProductionStatus::NOT_PRODUCED->value,
                $line->production_status,
                "Order line for product {$line->product_id} should be NOT_PRODUCED initially"
            );
        }

        // ACT: Create and execute OP covering all products
        $op = $this->createAndExecuteOp([$this->order->id]);

        // ASSERT MOMENT 2: All products should be FULLY_PRODUCED
        $this->order->refresh();
        $this->order->load('orderLines');

        foreach ($this->order->orderLines as $line) {
            $this->assertEquals(
                OrderProductionStatus::FULLY_PRODUCED->value,
                $line->production_status,
                "Order line for product {$line->product_id} should be FULLY_PRODUCED after OP execution"
            );
        }
    }

    /**
     * Test that production_status is null for new order_lines before calculation.
     */
    public function test_new_order_line_has_null_production_status_before_calculation(): void
    {
        // Create order without triggering update command
        $order = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => $this->dispatchDate->toDateString(),
            'date' => $this->dispatchDate->toDateString(),
            'status' => OrderStatus::PROCESSED->value,
            'total' => 10000,
            'dispatch_cost' => 0,
            'production_status_needs_update' => false,
        ]);

        $orderLine = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->productA->id,
            'quantity' => 10,
            'unit_price' => 1000,
        ]);

        // ASSERT: New line should have null production_status
        $this->assertNull(
            $orderLine->production_status,
            'New order line should have null production_status before calculation'
        );
    }

    // =========================================================================
    // HELPER METHODS (from PartiallyScheduledOrderFlowTest)
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

        $this->productA = $this->createProduct('Product A');
        $this->productB = $this->createProduct('Product B');
        $this->productC = $this->createProduct('Product C');

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
            'exclude_from_consolidated_report' => false,
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

        foreach ([$this->productA, $this->productB, $this->productC] as $product) {
            $this->warehouseRepository->associateProductToWarehouse($product, $this->warehouse, 0, 'UND');
        }
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

    private function createOrderWithThreeProducts(): void
    {
        $this->order = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => $this->dispatchDate->toDateString(),
            'date' => $this->dispatchDate->toDateString(),
            'status' => OrderStatus::PROCESSED->value,
            'total' => 10000,
            'dispatch_cost' => 0,
            'production_status' => OrderProductionStatus::NOT_PRODUCED->value,
            'production_status_needs_update' => true,
        ]);

        OrderLine::create([
            'order_id' => $this->order->id,
            'product_id' => $this->productA->id,
            'quantity' => 10,
            'unit_price' => 1000,
        ]);

        OrderLine::create([
            'order_id' => $this->order->id,
            'product_id' => $this->productB->id,
            'quantity' => 20,
            'unit_price' => 1500,
        ]);

        OrderLine::create([
            'order_id' => $this->order->id,
            'product_id' => $this->productC->id,
            'quantity' => 15,
            'unit_price' => 2000,
        ]);

        // Run update command to set initial production_status
        $this->artisan('orders:update-production-status');
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

    private function executeOp(AdvanceOrder $op): void
    {
        $this->actingAs($this->user);
        $op->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op));

        \Illuminate\Support\Facades\Queue::fake();

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
}
