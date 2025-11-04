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
use App\Repositories\OrderRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Test for AdvanceOrder pivot synchronization
 *
 * REQUIREMENTS:
 * When an AdvanceOrder is created from Orders with use_products_in_orders = false:
 * 1. advance_order_orders table should be populated with associated orders
 * 2. advance_order_order_lines table should be populated with order lines that match products in the OP
 * 3. AdvanceOrderProduct.ordered_quantity_new should equal sum of order_line quantities
 * 4. Only include PROCESSED and PARTIALLY_SCHEDULED orders
 * 5. For PARTIALLY_SCHEDULED orders, only include order_lines where partially_scheduled = true
 */
class AdvanceOrderPivotSyncTest extends TestCase
{
    use RefreshDatabase;

    private OrderRepository $repository;
    private ProductionArea $productionArea;
    private User $user;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new OrderRepository();

        // Create production area
        $this->productionArea = ProductionArea::create([
            'name' => 'Cocina Test',
            'description' => 'Área de prueba',
        ]);

        // Create user with role and permission
        $role = Role::firstOrCreate(['name' => RoleName::AGREEMENT->value]);
        $permission = Permission::firstOrCreate(['name' => PermissionName::CONSOLIDADO->value]);

        $priceList = PriceList::create([
            'name' => 'Test Price List',
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
            'name' => 'Test Category',
            'description' => 'Test category description',
        ]);
    }

    public function test_advance_order_from_orders_syncs_associated_orders(): void
    {
        // Create 2 PROCESSED orders
        $order1 = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $order2 = $this->createOrder('2025-11-06', OrderStatus::PROCESSED);

        // Create products
        $product1 = $this->createProduct('Product A', $this->productionArea);
        $product2 = $this->createProduct('Product B', $this->productionArea);

        // Create order lines
        $this->createOrderLine($order1, $product1, 10);
        $this->createOrderLine($order1, $product2, 5);
        $this->createOrderLine($order2, $product1, 15);

        // Create AdvanceOrder from orders
        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id],
            '2025-11-04 08:00:00',
            [$this->productionArea->id]
        );

        // Assert: Both orders should be in advance_order_orders table
        $associatedOrders = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->get();

        $this->assertCount(2, $associatedOrders);

        $orderIds = $associatedOrders->pluck('order_id')->toArray();
        $this->assertContains($order1->id, $orderIds);
        $this->assertContains($order2->id, $orderIds);
    }

    public function test_advance_order_from_orders_syncs_associated_order_lines(): void
    {
        // Create order
        $order = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);

        // Create products
        $product1 = $this->createProduct('Product A', $this->productionArea);
        $product2 = $this->createProduct('Product B', $this->productionArea);

        // Create order lines
        $line1 = $this->createOrderLine($order, $product1, 10);
        $line2 = $this->createOrderLine($order, $product2, 5);

        // Create AdvanceOrder from orders
        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-11-04 08:00:00',
            [$this->productionArea->id]
        );

        // Assert: Both order lines should be in advance_order_order_lines table
        $associatedOrderLines = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $advanceOrder->id)
            ->get();

        $this->assertCount(2, $associatedOrderLines);

        $orderLineIds = $associatedOrderLines->pluck('order_line_id')->toArray();
        $this->assertContains($line1->id, $orderLineIds);
        $this->assertContains($line2->id, $orderLineIds);
    }

    public function test_ordered_quantity_new_equals_sum_of_order_line_quantities(): void
    {
        // Create 2 orders
        $order1 = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $order2 = $this->createOrder('2025-11-06', OrderStatus::PROCESSED);

        // Create product
        $product = $this->createProduct('Product A', $this->productionArea);

        // Create order lines with different quantities
        $this->createOrderLine($order1, $product, 10);
        $this->createOrderLine($order2, $product, 15);

        // Create AdvanceOrder from orders
        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id],
            '2025-11-04 08:00:00',
            [$this->productionArea->id]
        );

        // Get the AdvanceOrderProduct
        $advanceOrderProduct = $advanceOrder->advanceOrderProducts()
            ->where('product_id', $product->id)
            ->first();

        // Assert: ordered_quantity_new should equal sum of order_line quantities (10 + 15 = 25)
        $this->assertEquals(25, $advanceOrderProduct->ordered_quantity_new);
    }

    public function test_only_includes_order_lines_for_products_in_op(): void
    {
        // Create order with 2 products
        $order = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);

        $product1 = $this->createProduct('Product A', $this->productionArea);
        $product2 = $this->createProduct('Product B', $this->productionArea);

        $line1 = $this->createOrderLine($order, $product1, 10);
        $line2 = $this->createOrderLine($order, $product2, 5);

        // Create AdvanceOrder with only product1 (manually to simulate partial selection)
        $advanceOrder = AdvanceOrder::create([
            'preparation_datetime' => '2025-11-04 08:00:00',
            'initial_dispatch_date' => '2025-11-05',
            'final_dispatch_date' => '2025-11-05',
            'use_products_in_orders' => false,
            'status' => \App\Enums\AdvanceOrderStatus::PENDING,
        ]);

        // Attach only product1
        $advanceOrder->products()->attach($product1->id, [
            'ordered_quantity' => 10,
        ]);

        $advanceOrder->productionAreas()->attach($this->productionArea->id);

        // Manually fire the event (since we used saveQuietly)
        event(new \App\Events\AdvanceOrderCreated($advanceOrder));

        // Assert: Only product1's order line should be in pivot
        $associatedOrderLines = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $advanceOrder->id)
            ->get();

        $this->assertCount(1, $associatedOrderLines);
        $this->assertEquals($line1->id, $associatedOrderLines->first()->order_line_id);
        $this->assertEquals($product1->id, $associatedOrderLines->first()->product_id);
    }

    public function test_partially_scheduled_orders_only_include_partially_scheduled_lines(): void
    {
        // Create PARTIALLY_SCHEDULED order
        $order = $this->createOrder('2025-11-05', OrderStatus::PARTIALLY_SCHEDULED);

        $product = $this->createProduct('Product A', $this->productionArea);

        // Create order lines with different partially_scheduled values
        $line1 = $this->createOrderLine($order, $product, 10);
        $line1->update(['partially_scheduled' => true]);

        $line2 = $this->createOrderLine($order, $product, 5);
        $line2->update(['partially_scheduled' => false]);

        // Create AdvanceOrder from orders
        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-11-04 08:00:00',
            [$this->productionArea->id]
        );

        // Assert: Only line1 (partially_scheduled=true) should be in pivot
        $associatedOrderLines = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $advanceOrder->id)
            ->get();

        $this->assertCount(1, $associatedOrderLines);
        $this->assertEquals($line1->id, $associatedOrderLines->first()->order_line_id);

        // Assert: ordered_quantity_new should only include line1's quantity
        $advanceOrderProduct = $advanceOrder->advanceOrderProducts()
            ->where('product_id', $product->id)
            ->first();

        $this->assertEquals(10, $advanceOrderProduct->ordered_quantity_new);
    }

    public function test_pending_orders_are_not_included(): void
    {
        // Create PENDING order
        $order = $this->createOrder('2025-11-05', OrderStatus::PENDING);

        $product = $this->createProduct('Product A', $this->productionArea);
        $this->createOrderLine($order, $product, 10);

        // Create AdvanceOrder from orders
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No se encontraron pedidos con estado PROCESSED o PARTIALLY_SCHEDULED.');

        $this->repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-11-04 08:00:00',
            [$this->productionArea->id]
        );
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

    private function createProduct(string $name, ProductionArea $productionArea): Product
    {
        $product = Product::create([
            'name' => $name,
            'description' => "Description for {$name}",
            'code' => strtoupper(str_replace(' ', '_', $name)),
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        // Attach product to production area
        $product->productionAreas()->attach($productionArea->id);

        return $product;
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
