<?php

namespace Tests\Feature;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderProductionStatus;
use App\Enums\OrderStatus;
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
 * TDD Red Phase Test - Production Status Bug with Multiple OPs Overlap
 *
 * This test replicates a production bug where orders show as "Parcialmente Producido"
 * when they should be "Completamente Producido".
 *
 * PRODUCTION SCENARIO (December 30, 2025):
 * - Order A (54 units of GALLETA NIK) - covered by OP #1 and OP #2
 * - Order B (16 units of GALLETA NIK) - covered by OP #2 only
 * - OP #1 created first with Order A only (ordered_quantity_new = 54)
 * - OP #2 created second with Order A + Order B (ordered_quantity_new = 16 due to overlap)
 *
 * BUG: Order B calculates production as 16 * (16/70) = 4, instead of 16
 *
 * EXPECTED: Both orders should be FULLY_PRODUCED
 * ACTUAL: Order B shows as PARTIALLY_PRODUCED
 */
class ProductionStatusMultipleOpsOverlapBugTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $dispatchDate;
    private User $userA;
    private User $userB;
    private Company $companyA;
    private Company $companyB;
    private Category $category;
    private ProductionArea $productionArea;
    private Warehouse $warehouse;
    private Product $product;
    private Order $orderA;
    private Order $orderB;
    private OrderRepository $orderRepository;
    private WarehouseRepository $warehouseRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatchDate = Carbon::parse('2025-12-30');
        Carbon::setTestNow('2025-12-29 10:00:00');

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
     * Test that replicates the production bug.
     *
     * FLOW:
     * 1. Create Order A (54 units) and Order B (16 units) for same product
     * 2. Create OP #1 with Order A only → executed
     * 3. Create OP #2 with Order A + Order B → overlap detected → ordered_quantity_new = 16
     * 4. Execute OP #2
     * 5. Verify Order B's production status
     *
     * EXPECTED: Order B should be FULLY_PRODUCED (16/16 = 100%)
     * ACTUAL BUG: Order B shows PARTIALLY_PRODUCED (calculated as 4/16 = 25%)
     */
    public function test_order_in_single_op_with_overlap_should_be_fully_produced(): void
    {
        // =====================================================
        // STEP 1: Create two orders for the same product
        // =====================================================
        $this->createOrderA(54);  // Order A: 54 units
        $this->createOrderB(16);  // Order B: 16 units

        // =====================================================
        // STEP 2: Create and execute OP #1 with Order A ONLY
        // =====================================================
        Carbon::setTestNow('2025-12-29 11:50:00');

        $op1 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id],  // Only Order A
            $this->dispatchDate->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString(),
            [$this->productionArea->id]
        );

        // Verify OP #1 values
        $aop1 = DB::table('advance_order_products')
            ->where('advance_order_id', $op1->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertEquals(54, $aop1->ordered_quantity, 'OP #1 ordered_quantity should be 54');
        $this->assertEquals(54, $aop1->ordered_quantity_new, 'OP #1 ordered_quantity_new should be 54 (no overlap)');

        // Execute OP #1
        Carbon::setTestNow('2025-12-29 11:51:00');
        $this->executeOp($op1);

        // =====================================================
        // STEP 3: Create OP #2 with Order A + Order B
        // =====================================================
        Carbon::setTestNow('2025-12-29 15:52:00');

        $op2 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id, $this->orderB->id],  // Both orders
            $this->dispatchDate->copy()->subDay()->setTime(20, 0, 0)->toDateTimeString(),
            [$this->productionArea->id]
        );

        // Verify OP #2 values - should have overlap reduction
        $aop2 = DB::table('advance_order_products')
            ->where('advance_order_id', $op2->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertEquals(70, $aop2->ordered_quantity, 'OP #2 ordered_quantity should be 70 (54 + 16)');
        $this->assertEquals(16, $aop2->ordered_quantity_new, 'OP #2 ordered_quantity_new should be 16 (70 - 54 overlap)');

        // Verify pivot table for OP #2
        $pivotOrderA = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op2->id)
            ->where('order_id', $this->orderA->id)
            ->where('product_id', $this->product->id)
            ->first();

        $pivotOrderB = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op2->id)
            ->where('order_id', $this->orderB->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertEquals(54, $pivotOrderA->quantity_covered, 'Order A quantity_covered in OP #2 should be 54');
        $this->assertEquals(16, $pivotOrderB->quantity_covered, 'Order B quantity_covered in OP #2 should be 16');

        // =====================================================
        // STEP 4: Execute OP #2
        // =====================================================
        Carbon::setTestNow('2025-12-29 15:53:00');
        $this->executeOp($op2);

        // =====================================================
        // STEP 5: Verify production status calculations
        // =====================================================

        // Order A: Should be FULLY_PRODUCED (covered by OP #1)
        $producedA = $this->orderRepository->getTotalProducedForProduct(
            $this->orderA->id,
            $this->product->id
        );

        $this->assertGreaterThanOrEqual(
            54,
            $producedA,
            'Order A should have at least 54 units produced'
        );

        // Order B: THIS IS THE BUG - Should be 16, but calculates as 4
        $producedB = $this->orderRepository->getTotalProducedForProduct(
            $this->orderB->id,
            $this->product->id
        );

        // RED PHASE: This assertion will FAIL with the current bug
        // Current calculation: 16 * (16/70) = 3.66 ≈ 4
        // Expected: 16 (Order B's full quantity is covered by OP #2's ordered_quantity_new)
        $this->assertEquals(
            16,
            $producedB,
            "BUG: Order B should have 16 units produced, but got {$producedB}. " .
            "The proportional formula incorrectly calculates 16 * (16/70) = 4"
        );

        // =====================================================
        // STEP 6: Verify production status via command
        // =====================================================
        $this->artisan('orders:update-production-status');

        $this->orderB->refresh();

        // RED PHASE: This assertion will FAIL with the current bug
        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $this->orderB->production_status,
            "BUG: Order B should be FULLY_PRODUCED but is {$this->orderB->production_status}"
        );
    }

    /**
     * Additional test: Verify the sum-based calculation would be correct.
     *
     * This test documents what the CORRECT calculation should be.
     */
    public function test_sum_based_calculation_gives_correct_result(): void
    {
        // Setup same as above
        $this->createOrderA(54);
        $this->createOrderB(16);

        Carbon::setTestNow('2025-12-29 11:50:00');
        $op1 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id],
            $this->dispatchDate->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString(),
            [$this->productionArea->id]
        );
        $this->executeOp($op1);

        Carbon::setTestNow('2025-12-29 15:52:00');
        $op2 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id, $this->orderB->id],
            $this->dispatchDate->copy()->subDay()->setTime(20, 0, 0)->toDateTimeString(),
            [$this->productionArea->id]
        );
        $this->executeOp($op2);

        // Calculate using the CORRECT method: sum of ordered_quantity_new
        $sumOrderedQuantityNew = DB::table('advance_order_order_lines as aool')
            ->join('advance_orders as ao', 'aool.advance_order_id', '=', 'ao.id')
            ->join('advance_order_products as aop', function ($join) {
                $join->on('aop.advance_order_id', '=', 'ao.id')
                     ->whereColumn('aop.product_id', 'aool.product_id');
            })
            ->where('aool.order_id', $this->orderB->id)
            ->where('aool.product_id', $this->product->id)
            ->where('ao.status', AdvanceOrderStatus::EXECUTED->value)
            ->sum('aop.ordered_quantity_new');

        $this->orderB->refresh();
        $orderBQuantity = $this->orderB->orderLines->first()->quantity;
        $correctProduced = min($orderBQuantity, $sumOrderedQuantityNew);

        $this->assertEquals(
            16,
            $correctProduced,
            "Using sum-based calculation: min(16, {$sumOrderedQuantityNew}) = {$correctProduced}"
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

        $this->product = $this->createProduct('GALLETA NIK BOCADO 71 GR');

        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'is_default' => true,
        ]);

        // Company A
        $this->companyA = Company::create([
            'name' => 'TEST COMPANY A S.A.',
            'tax_id' => '11.111.111-1',
            'company_code' => 'TCA01',
            'fantasy_name' => 'Test Company A',
            'email' => 'test.companya@test.com',
            'price_list_id' => $priceList->id,
        ]);

        $branchA = Branch::create([
            'company_id' => $this->companyA->id,
            'shipping_address' => 'Test Address A',
            'fantasy_name' => 'Test Branch A',
            'min_price_order' => 0,
        ]);

        $this->userA = User::create([
            'name' => 'Test User A',
            'nickname' => 'TEST.USER.A',
            'email' => 'test.usera@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->companyA->id,
            'branch_id' => $branchA->id,
        ]);

        // Company B
        $this->companyB = Company::create([
            'name' => 'TEST COMPANY B S.A.',
            'tax_id' => '22.222.222-2',
            'company_code' => 'TCB01',
            'fantasy_name' => 'Test Company B',
            'email' => 'test.companyb@test.com',
            'price_list_id' => $priceList->id,
        ]);

        $branchB = Branch::create([
            'company_id' => $this->companyB->id,
            'shipping_address' => 'Test Address B',
            'fantasy_name' => 'Test Branch B',
            'min_price_order' => 0,
        ]);

        $this->userB = User::create([
            'name' => 'Test User B',
            'nickname' => 'TEST.USER.B',
            'email' => 'test.userb@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->companyB->id,
            'branch_id' => $branchB->id,
        ]);

        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $this->warehouseRepository->associateProductToWarehouse($this->product, $this->warehouse, 0, 'UND');
    }

    private function createProduct(string $name): Product
    {
        $code = 'CLA-' . strtoupper(substr(md5($name), 0, 8));

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

    private function createOrderA(int $quantity): void
    {
        $this->orderA = Order::create([
            'user_id' => $this->userA->id,
            'dispatch_date' => $this->dispatchDate->toDateString(),
            'status' => OrderStatus::PROCESSED->value,
            'total' => $quantity * 1000,
            'dispatch_cost' => 0,
            'production_status_needs_update' => true,
        ]);

        OrderLine::create([
            'order_id' => $this->orderA->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 1000,
        ]);
    }

    private function createOrderB(int $quantity): void
    {
        $this->orderB = Order::create([
            'user_id' => $this->userB->id,
            'dispatch_date' => $this->dispatchDate->toDateString(),
            'status' => OrderStatus::PROCESSED->value,
            'total' => $quantity * 1000,
            'dispatch_cost' => 0,
            'production_status_needs_update' => true,
        ]);

        OrderLine::create([
            'order_id' => $this->orderB->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 1000,
        ]);
    }

    private function executeOp(AdvanceOrder $op): void
    {
        $this->actingAs($this->userA);
        $op->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new \App\Events\AdvanceOrderExecuted($op));

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
