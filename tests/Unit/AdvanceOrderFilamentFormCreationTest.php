<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Category;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\Permission;
use App\Models\AdvanceOrder;
use App\Models\ProductionArea;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\AdvanceOrderStatus;
use App\Repositories\AdvanceOrderRepository;
use App\Repositories\OrderRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Test that simulates creating an AdvanceOrder from Filament form
 *
 * SCENARIO:
 * 1. Create AdvanceOrder with use_products_in_orders = false (manually created from form)
 * 2. Multiple orders exist in different states (PENDING, PROCESSED, PARTIALLY_SCHEDULED)
 * 3. PARTIALLY_SCHEDULED orders have some lines with partially_scheduled = true/false
 * 4. Manually assign a product to the OP (simulating adding product via RelationManager)
 *    - This triggers AdvanceOrderProductObserver which fires AdvanceOrderProductChanged event
 * 5. Validate:
 *    - ordered_quantity (total from all relevant order lines)
 *    - ordered_quantity_new (considering previous OPs)
 *    - total_to_produce calculation
 *    - Pivot tables (advance_order_orders and advance_order_order_lines) are populated correctly
 *    - Only PROCESSED and PARTIALLY_SCHEDULED orders are included
 *    - Only partially_scheduled=true lines from PARTIALLY_SCHEDULED orders
 */
class AdvanceOrderFilamentFormCreationTest extends TestCase
{
    use RefreshDatabase;

    private ProductionArea $productionArea;
    private User $user;
    private Category $category;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Create production area
        $this->productionArea = ProductionArea::create([
            'name' => 'Cocina Principal',
            'description' => 'Área principal de producción',
        ]);

        // Create user with role and permission
        $role = Role::firstOrCreate(['name' => RoleName::AGREEMENT->value]);
        $permission = Permission::firstOrCreate(['name' => PermissionName::CONSOLIDADO->value]);

        $priceList = PriceList::create([
            'name' => 'Lista de Precios Test',
            'is_default' => true,
        ]);

        $company = Company::create([
            'name' => 'Test Company',
            'email' => 'test@company.com',
            'tax_id' => '12345678-9',
            'company_code' => 'TEST001',
            'fantasy_name' => 'Test Company S.A.',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'shipping_address' => 'Test Address 123',
            'fantasy_name' => 'Test Branch',
            'min_price_order' => 0,
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'nickname' => 'TEST.USER',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);

        $this->user->roles()->attach($role->id);
        $this->user->permissions()->attach($permission->id);

        // Create category
        $this->category = Category::create([
            'name' => 'Platos',
            'description' => 'Categoría de platos',
        ]);

        // Create product
        $this->product = Product::create([
            'name' => 'Lasagna Bolognesa',
            'description' => 'Lasagna con carne molida y salsa bolognesa',
            'code' => 'LASAGNA_BOL',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        // Attach product to production area
        $this->product->productionAreas()->attach($this->productionArea->id);
    }

    public function test_filament_form_creation_with_manual_product_assignment(): void
    {
        // STEP 1: Create orders in different states BEFORE creating the OP

        // Order 1: PENDING (should NOT be included in pivot)
        $orderPending = $this->createOrder('2025-11-10', OrderStatus::PENDING);
        $this->createOrderLine($orderPending, $this->product, 5);

        // Order 2: PROCESSED (should be included, all lines)
        $orderProcessed = $this->createOrder('2025-11-11', OrderStatus::PROCESSED);
        $this->createOrderLine($orderProcessed, $this->product, 10);

        // Order 3: PARTIALLY_SCHEDULED (only partially_scheduled=true lines)
        $orderPartiallyScheduled = $this->createOrder('2025-11-12', OrderStatus::PARTIALLY_SCHEDULED);
        $linePartialTrue = $this->createOrderLine($orderPartiallyScheduled, $this->product, 8);
        $linePartialTrue->update(['partially_scheduled' => true]);

        $linePartialFalse = $this->createOrderLine($orderPartiallyScheduled, $this->product, 3);
        $linePartialFalse->update(['partially_scheduled' => false]);

        // Order 4: Another PROCESSED order
        $orderProcessed2 = $this->createOrder('2025-11-13', OrderStatus::PROCESSED);
        $this->createOrderLine($orderProcessed2, $this->product, 12);

        // STEP 2: Create AdvanceOrder manually (simulating Filament form creation)
        // use_products_in_orders = false (products will be added manually via RelationManager)
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-09 08:00:00',
            'initial_dispatch_date' => '2025-11-10',
            'final_dispatch_date' => '2025-11-13',
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::PENDING,
            'description' => 'OP creada desde formulario de prueba',
        ]);

        // Attach production area
        $advanceOrder->productionAreas()->attach($this->productionArea->id);

        // STEP 3: Manually assign product (simulating adding product via RelationManager)
        // This is what happens when you click "Agregar producto" in the Filament RelationManager

        // Calculate ordered_quantity from orders in date range (this is what RelationManager does)
        $orderRepository = new OrderRepository();
        $productsData = $orderRepository->getProductsFromOrdersInDateRange(
            $advanceOrder->initial_dispatch_date->format('Y-m-d'),
            $advanceOrder->final_dispatch_date->format('Y-m-d')
        );

        $productData = $productsData->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($productData, 'Product should exist in orders data');

        $currentOrderedQuantity = $productData['ordered_quantity'];

        // Calculate ordered_quantity_new (considering previous OPs)
        $advanceOrderRepository = new AdvanceOrderRepository();
        $previousAdvanceOrders = $advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($advanceOrder);
        $maxPreviousQuantity = $advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $this->product->id,
            $previousAdvanceOrders,
            $advanceOrder
        );

        $orderedQuantityNew = max(0, $currentOrderedQuantity - $maxPreviousQuantity);

        // Create AdvanceOrderProduct record (this is what Filament RelationManager does)
        // This triggers AdvanceOrderProductObserver->created() which fires AdvanceOrderProductChanged event
        \App\Models\AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product->id,
            'ordered_quantity' => $currentOrderedQuantity,
            'ordered_quantity_new' => $orderedQuantityNew,
            'quantity' => 0,
            // total_to_produce is calculated automatically by the Observer in creating()
        ]);

        // Note: Observer automatically fires AdvanceOrderProductChanged event

        // STEP 4: VALIDATE CALCULATIONS

        // Refresh the advance order
        $advanceOrder->refresh();

        // Get the AdvanceOrderProduct pivot
        $advanceOrderProduct = $advanceOrder->advanceOrderProducts()
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($advanceOrderProduct);

        // Validate ordered_quantity
        // Should be: 10 (PROCESSED) + 8 (PARTIALLY_SCHEDULED with flag) + 12 (PROCESSED) = 30
        // NOT including: 5 (PENDING) + 3 (PARTIALLY_SCHEDULED without flag)
        $this->assertEquals(30, $advanceOrderProduct->ordered_quantity,
            'ordered_quantity should be sum of PROCESSED lines + partially_scheduled=true lines from PARTIALLY_SCHEDULED orders');

        // Validate ordered_quantity_new (no previous OPs, so should equal ordered_quantity)
        $this->assertEquals(30, $advanceOrderProduct->ordered_quantity_new,
            'ordered_quantity_new should equal ordered_quantity when no previous OPs exist');

        // Validate total_to_produce is calculated correctly by Observer
        // Formula: When quantity = 0: max(0, ordered_quantity_new - initialInventory)
        // In this case: max(0, 30 - 0) = 30
        $this->assertEquals(0, $advanceOrderProduct->quantity,
            'quantity should be 0 when not manually set');
        $this->assertEquals(30, $advanceOrderProduct->total_to_produce,
            'total_to_produce should be calculated by Observer: max(0, ordered_quantity_new - initialInventory) = max(0, 30 - 0) = 30');

        // CRITICAL VALIDATION: Ensure Observer actually calculated this value
        // This validates the fix for the bug where total_to_produce was 0
        $this->assertGreaterThan(0, $advanceOrderProduct->total_to_produce,
            'CRITICAL: total_to_produce must be > 0 when there are orders to fulfill and no inventory');

        // STEP 5: VALIDATE PIVOT TABLE ASSOCIATIONS

        // Validate advance_order_orders table
        $associatedOrders = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->get();

        // Should have 3 orders: orderProcessed, orderPartiallyScheduled, orderProcessed2
        // NOT including: orderPending
        $this->assertCount(3, $associatedOrders,
            'Should have 3 orders in pivot (2 PROCESSED + 1 PARTIALLY_SCHEDULED)');

        $orderIds = $associatedOrders->pluck('order_id')->toArray();
        $this->assertContains($orderProcessed->id, $orderIds);
        $this->assertContains($orderPartiallyScheduled->id, $orderIds);
        $this->assertContains($orderProcessed2->id, $orderIds);
        $this->assertNotContains($orderPending->id, $orderIds, 'PENDING orders should not be included');

        // Validate advance_order_order_lines table
        $associatedOrderLines = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $advanceOrder->id)
            ->where('product_id', $this->product->id)
            ->get();

        // Should have 3 order lines:
        // - 1 from orderProcessed (10 units)
        // - 1 from orderPartiallyScheduled with partially_scheduled=true (8 units)
        // - 1 from orderProcessed2 (12 units)
        // NOT including:
        // - Line from orderPending
        // - Line from orderPartiallyScheduled with partially_scheduled=false
        $this->assertCount(3, $associatedOrderLines,
            'Should have 3 order lines in pivot');

        // Validate that the correct line from PARTIALLY_SCHEDULED is included
        $partialLineInPivot = $associatedOrderLines->firstWhere('order_line_id', $linePartialTrue->id);
        $this->assertNotNull($partialLineInPivot,
            'Order line with partially_scheduled=true should be in pivot');
        $this->assertEquals(8, $partialLineInPivot->quantity_covered);

        // Validate that the line with partially_scheduled=false is NOT included
        $excludedLine = $associatedOrderLines->firstWhere('order_line_id', $linePartialFalse->id);
        $this->assertNull($excludedLine,
            'Order line with partially_scheduled=false should NOT be in pivot');

        // Validate sum of quantity_covered equals ordered_quantity
        $totalQuantityCovered = $associatedOrderLines->sum('quantity_covered');
        $this->assertEquals(30, $totalQuantityCovered,
            'Sum of quantity_covered should equal ordered_quantity');

        // Validate order line details are correctly stored
        foreach ($associatedOrderLines as $orderLine) {
            $this->assertEquals($advanceOrder->id, $orderLine->advance_order_id);
            $this->assertEquals($this->product->id, $orderLine->product_id);
            $this->assertNotNull($orderLine->order_dispatch_date);
            $this->assertNotNull($orderLine->order_number);
            $this->assertNotNull($orderLine->product_name);
            $this->assertNotNull($orderLine->product_code);
        }
    }

    public function test_filament_form_creation_with_previous_advance_order(): void
    {
        // STEP 1: Create orders
        $order1 = $this->createOrder('2025-11-15', OrderStatus::PROCESSED);
        $this->createOrderLine($order1, $this->product, 20);

        $order2 = $this->createOrder('2025-11-16', OrderStatus::PROCESSED);
        $this->createOrderLine($order2, $this->product, 15);

        // STEP 2: Create FIRST AdvanceOrder with same date range
        $previousAdvanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-14 08:00:00',
            'initial_dispatch_date' => '2025-11-15',
            'final_dispatch_date' => '2025-11-16',
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::PENDING,
        ]);

        $previousAdvanceOrder->productionAreas()->attach($this->productionArea->id);

        // Create AdvanceOrderProduct for previous OP
        // The Observer will fire AdvanceOrderProductChanged event
        \App\Models\AdvanceOrderProduct::create([
            'advance_order_id' => $previousAdvanceOrder->id,
            'product_id' => $this->product->id,
            'ordered_quantity' => 35,
            'ordered_quantity_new' => 35,
            'quantity' => 0,
        ]);

        // STEP 3: Create SECOND AdvanceOrder with SAME date range
        $newAdvanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-14 10:00:00',
            'initial_dispatch_date' => '2025-11-15',
            'final_dispatch_date' => '2025-11-16',
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::PENDING,
        ]);

        $newAdvanceOrder->productionAreas()->attach($this->productionArea->id);

        // Calculate ordered_quantity_new for the new OP (considering previous OP)
        $orderRepository = new OrderRepository();
        $productsData = $orderRepository->getProductsFromOrdersInDateRange(
            $newAdvanceOrder->initial_dispatch_date->format('Y-m-d'),
            $newAdvanceOrder->final_dispatch_date->format('Y-m-d')
        );

        $productData = $productsData->firstWhere('product_id', $this->product->id);
        $currentOrderedQuantity = $productData['ordered_quantity'];

        $advanceOrderRepository = new AdvanceOrderRepository();
        $previousAdvanceOrders = $advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($newAdvanceOrder);
        $maxPreviousQuantity = $advanceOrderRepository->getMaxOrderedQuantityForProduct(
            $this->product->id,
            $previousAdvanceOrders,
            $newAdvanceOrder
        );

        $orderedQuantityNew = max(0, $currentOrderedQuantity - $maxPreviousQuantity);

        // Create AdvanceOrderProduct for new OP (Observer fires AdvanceOrderProductChanged)
        \App\Models\AdvanceOrderProduct::create([
            'advance_order_id' => $newAdvanceOrder->id,
            'product_id' => $this->product->id,
            'ordered_quantity' => $currentOrderedQuantity,
            'ordered_quantity_new' => $orderedQuantityNew,
            'quantity' => 0,
        ]);

        // STEP 4: VALIDATE
        $newAdvanceOrder->refresh();
        $newAdvanceOrderProduct = $newAdvanceOrder->advanceOrderProducts()
            ->where('product_id', $this->product->id)
            ->first();

        // ordered_quantity should still be 35 (total from all orders)
        $this->assertEquals(35, $newAdvanceOrderProduct->ordered_quantity,
            'ordered_quantity should be total from all orders');

        // ordered_quantity_new should be 0 (35 - 35 from previous OP)
        $this->assertEquals(0, $newAdvanceOrderProduct->ordered_quantity_new,
            'ordered_quantity_new should be 0 when previous OP already covers all orders');

        // Validate total_to_produce is calculated correctly even when ordered_quantity_new = 0
        // Formula: When quantity = 0: max(0, ordered_quantity_new - initialInventory)
        // In this case: max(0, 0 - 0) = 0
        $this->assertEquals(0, $newAdvanceOrderProduct->quantity,
            'quantity should be 0 when not manually set');
        $this->assertEquals(0, $newAdvanceOrderProduct->total_to_produce,
            'total_to_produce should be 0 when ordered_quantity_new is 0: max(0, 0 - 0) = 0');

        // CRITICAL VALIDATION: Observer must have run even if result is 0
        // This ensures the Observer is ALWAYS triggered when creating AdvanceOrderProduct
        $this->assertNotNull($newAdvanceOrderProduct->total_to_produce,
            'CRITICAL: total_to_produce must be set by Observer, even if value is 0');

        // Pivot tables should still be populated (to show which orders are covered)
        $associatedOrders = DB::table('advance_order_orders')
            ->where('advance_order_id', $newAdvanceOrder->id)
            ->get();
        $this->assertCount(2, $associatedOrders, 'Should still have 2 orders in pivot');

        $associatedOrderLines = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $newAdvanceOrder->id)
            ->where('product_id', $this->product->id)
            ->get();
        $this->assertCount(2, $associatedOrderLines, 'Should still have 2 order lines in pivot');
    }

    public function test_filament_form_creation_with_manual_quantity_adjustment(): void
    {
        // STEP 1: Create orders
        $order = $this->createOrder('2025-11-20', OrderStatus::PROCESSED);
        $this->createOrderLine($order, $this->product, 50);

        // STEP 2: Create AdvanceOrder
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-19 08:00:00',
            'initial_dispatch_date' => '2025-11-20',
            'final_dispatch_date' => '2025-11-20',
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::PENDING,
        ]);

        $advanceOrder->productionAreas()->attach($this->productionArea->id);

        // STEP 3: Create AdvanceOrderProduct with custom quantity (user adjusts manually)
        // ordered_quantity = 50 (from orders)
        // ordered_quantity_new = 50 (no previous OPs)
        // quantity = 10 (user sets this manually, maybe has stock)
        // total_to_produce is calculated by Observer: ordered_quantity_new - quantity = 50 - 10 = 40
        \App\Models\AdvanceOrderProduct::create([
            'advance_order_id' => $advanceOrder->id,
            'product_id' => $this->product->id,
            'ordered_quantity' => 50,
            'ordered_quantity_new' => 50,
            'quantity' => 10,
        ]);

        // STEP 4: VALIDATE
        $advanceOrder->refresh();
        $advanceOrderProduct = $advanceOrder->advanceOrderProducts()
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertEquals(50, $advanceOrderProduct->ordered_quantity,
            'ordered_quantity should be sum from all orders');
        $this->assertEquals(50, $advanceOrderProduct->ordered_quantity_new,
            'ordered_quantity_new should equal ordered_quantity when no previous OPs');
        $this->assertEquals(10, $advanceOrderProduct->quantity,
            'Manually set quantity should be preserved');

        // When quantity > 0: total_to_produce = max(0, quantity - initialInventory)
        // In this case: max(0, 10 - 0) = 10
        // This means: user wants 10 units final, has 0 in inventory, needs to produce 10
        $this->assertEquals(10, $advanceOrderProduct->total_to_produce,
            'total_to_produce should be max(0, quantity - initialInventory) when quantity > 0');
    }

    // Helper methods

    private function createOrder(string $dispatchDate, OrderStatus $status): Order
    {
        return Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => $dispatchDate,
            'date' => $dispatchDate,
            'status' => $status->value,
            'total' => 10000,
            'total_with_tax' => 11900,
            'tax_amount' => 1900,
            'grand_total' => 11900,
            'dispatch_cost' => 0,
        ]);
    }

    private function createOrderLine(Order $order, Product $product, int $quantity): OrderLine
    {
        return OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'subtotal' => $quantity * 1000,
        ]);
    }
}
