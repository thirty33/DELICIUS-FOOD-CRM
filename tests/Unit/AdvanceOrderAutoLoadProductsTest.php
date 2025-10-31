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
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TDD Test - Auto Load Products When Creating Production Order
 *
 * EXPECTED BEHAVIOR:
 * When creating a production order with use_products_in_orders = true,
 * the system should automatically load products from orders in the date range,
 * BUT only from:
 * 1. Orders with status PROCESSED (all products)
 * 2. Orders with status PARTIALLY_SCHEDULED (only order lines with partially_scheduled = true)
 *
 * Should NOT include:
 * - Products from PENDING orders
 * - Products from CANCELED orders
 * - Order lines with partially_scheduled = false from PARTIALLY_SCHEDULED orders
 *
 * CURRENT BUG (why test will FAIL initially):
 * The method getProductsFromOrdersInDateRange() does NOT filter by order status
 * nor by partially_scheduled field, so it loads ALL products from ALL orders.
 *
 * TEST SCENARIO:
 * - Order 1 (PENDING): 2 products → Should NOT be loaded (0 products)
 * - Order 2 (PROCESSED): 2 products → Should be loaded (2 products)
 * - Order 3 (PARTIALLY_SCHEDULED):
 *   - Product A: partially_scheduled = true → Should be loaded
 *   - Product B: partially_scheduled = true → Should be loaded
 *   - Product C: partially_scheduled = false → Should NOT be loaded
 *
 * Expected total: 4 products
 * Current bug: 7 products (all)
 */
class AdvanceOrderAutoLoadProductsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Category $category;

    protected Product $productPending1;
    protected Product $productPending2;
    protected Product $productProcessed1;
    protected Product $productProcessed2;
    protected Product $productPartialA;
    protected Product $productPartialB;
    protected Product $productPartialC;

    protected function setUp(): void
    {
        parent::setUp();

        // Set test date
        Carbon::setTestNow('2025-11-01 10:00:00');

        // Create user using factory
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Create category
        $this->category = Category::create([
            'name' => 'TEST AUTO LOAD CATEGORY',
            'description' => 'Test category for auto load products test',
            'active' => true,
        ]);

        // Create 7 products
        $this->productPending1 = $this->createProduct('PENDING-PRODUCT-1', 'Product from PENDING order 1');
        $this->productPending2 = $this->createProduct('PENDING-PRODUCT-2', 'Product from PENDING order 2');
        $this->productProcessed1 = $this->createProduct('PROCESSED-PRODUCT-1', 'Product from PROCESSED order 1');
        $this->productProcessed2 = $this->createProduct('PROCESSED-PRODUCT-2', 'Product from PROCESSED order 2');
        $this->productPartialA = $this->createProduct('PARTIAL-PRODUCT-A', 'Product A from PARTIALLY_SCHEDULED (scheduled=true)');
        $this->productPartialB = $this->createProduct('PARTIAL-PRODUCT-B', 'Product B from PARTIALLY_SCHEDULED (scheduled=true)');
        $this->productPartialC = $this->createProduct('PARTIAL-PRODUCT-C', 'Product C from PARTIALLY_SCHEDULED (scheduled=false)');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createProduct(string $code, string $description): Product
    {
        return Product::create([
            'name' => $code,
            'description' => $description,
            'code' => $code,
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);
    }

    protected function createOrderWithProducts(
        OrderStatus $status,
        string $dispatchDate,
        array $productsWithPartialScheduled
    ): Order {
        $order = Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => $dispatchDate,
            'status' => $status,
            'total' => count($productsWithPartialScheduled) * 5000,
        ]);

        foreach ($productsWithPartialScheduled as $productData) {
            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $productData['product_id'],
                'quantity' => $productData['quantity'],
                'unit_price' => 5000,
                'total_price' => $productData['quantity'] * 5000,
                'partially_scheduled' => $productData['partially_scheduled'],
            ]);
        }

        return $order;
    }

    public function test_auto_load_products_filters_by_order_status_and_partially_scheduled(): void
    {
        // ==================== CREATE ORDERS ====================

        // Order 1: PENDING with 2 products (should NOT be loaded)
        $this->createOrderWithProducts(
            OrderStatus::PENDING,
            '2025-11-03',
            [
                ['product_id' => $this->productPending1->id, 'quantity' => 5, 'partially_scheduled' => false],
                ['product_id' => $this->productPending2->id, 'quantity' => 3, 'partially_scheduled' => false],
            ]
        );

        // Order 2: PROCESSED with 2 products (should ALL be loaded)
        $this->createOrderWithProducts(
            OrderStatus::PROCESSED,
            '2025-11-04',
            [
                ['product_id' => $this->productProcessed1->id, 'quantity' => 10, 'partially_scheduled' => false],
                ['product_id' => $this->productProcessed2->id, 'quantity' => 7, 'partially_scheduled' => false],
            ]
        );

        // Order 3: PARTIALLY_SCHEDULED with 3 products (only 2 with partially_scheduled=true should be loaded)
        $this->createOrderWithProducts(
            OrderStatus::PARTIALLY_SCHEDULED,
            '2025-11-05',
            [
                ['product_id' => $this->productPartialA->id, 'quantity' => 8, 'partially_scheduled' => true],
                ['product_id' => $this->productPartialB->id, 'quantity' => 6, 'partially_scheduled' => true],
                ['product_id' => $this->productPartialC->id, 'quantity' => 4, 'partially_scheduled' => false],
            ]
        );

        // ==================== CREATE PRODUCTION ORDER ====================

        // Create advance order with use_products_in_orders = true
        $advanceOrder = AdvanceOrder::create([
            'initial_dispatch_date' => '2025-11-03',
            'final_dispatch_date' => '2025-11-05',
            'preparation_datetime' => '2025-11-02 08:00:00',
            'description' => 'Test production order with auto load',
            'status' => AdvanceOrderStatus::PENDING,
            'use_products_in_orders' => true,
        ]);

        // Manually trigger the product loading logic (simulates Filament's afterCreate hook)
        $orderRepository = new \App\Repositories\OrderRepository();
        $advanceOrderProductRepository = new \App\Repositories\AdvanceOrderProductRepository();
        $advanceOrderRepository = new \App\Repositories\AdvanceOrderRepository();

        $productsData = $orderRepository->getProductsFromOrdersInDateRange(
            $advanceOrder->initial_dispatch_date->format('Y-m-d'),
            $advanceOrder->final_dispatch_date->format('Y-m-d')
        );

        $advanceOrderProductRepository->associateProductsWithDefaultQuantity(
            $advanceOrder,
            $productsData,
            $advanceOrderRepository
        );

        // Refresh to get associated products
        $advanceOrder->refresh();

        // ==================== ASSERTIONS ====================

        // EXPECTED: Only 4 products should be loaded
        // - 2 from PROCESSED order
        // - 2 from PARTIALLY_SCHEDULED order (only those with partially_scheduled=true)
        $loadedProducts = $advanceOrder->advanceOrderProducts;

        $this->assertEquals(
            4,
            $loadedProducts->count(),
            'Should load exactly 4 products: 2 from PROCESSED order + 2 from PARTIALLY_SCHEDULED (with partially_scheduled=true)'
        );

        // Verify specific products are loaded
        $loadedProductIds = $loadedProducts->pluck('product_id')->toArray();

        // Products from PROCESSED order SHOULD be loaded
        $this->assertContains(
            $this->productProcessed1->id,
            $loadedProductIds,
            'Product from PROCESSED order should be loaded'
        );
        $this->assertContains(
            $this->productProcessed2->id,
            $loadedProductIds,
            'Product from PROCESSED order should be loaded'
        );

        // Products from PARTIALLY_SCHEDULED with partially_scheduled=true SHOULD be loaded
        $this->assertContains(
            $this->productPartialA->id,
            $loadedProductIds,
            'Product A from PARTIALLY_SCHEDULED order (partially_scheduled=true) should be loaded'
        );
        $this->assertContains(
            $this->productPartialB->id,
            $loadedProductIds,
            'Product B from PARTIALLY_SCHEDULED order (partially_scheduled=true) should be loaded'
        );

        // Products from PENDING order should NOT be loaded
        $this->assertNotContains(
            $this->productPending1->id,
            $loadedProductIds,
            'Product from PENDING order should NOT be loaded'
        );
        $this->assertNotContains(
            $this->productPending2->id,
            $loadedProductIds,
            'Product from PENDING order should NOT be loaded'
        );

        // Product from PARTIALLY_SCHEDULED with partially_scheduled=false should NOT be loaded
        $this->assertNotContains(
            $this->productPartialC->id,
            $loadedProductIds,
            'Product C from PARTIALLY_SCHEDULED order (partially_scheduled=false) should NOT be loaded'
        );

        // Verify quantities are correct for loaded products
        $productProcessed1Data = $loadedProducts->firstWhere('product_id', $this->productProcessed1->id);
        $this->assertEquals(
            10,
            $productProcessed1Data->ordered_quantity,
            'Quantity for PROCESSED product 1 should be 10'
        );

        $productPartialAData = $loadedProducts->firstWhere('product_id', $this->productPartialA->id);
        $this->assertEquals(
            8,
            $productPartialAData->ordered_quantity,
            'Quantity for PARTIALLY_SCHEDULED product A should be 8'
        );
    }
}
