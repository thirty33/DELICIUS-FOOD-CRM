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

/**
 * Test for creating AdvanceOrder from Orders
 *
 * REQUIREMENTS:
 * 1. Create AdvanceOrder with preparation_datetime from form
 * 2. Date range covers all selected orders (initial_dispatch_date to final_dispatch_date)
 * 3. Only include PROCESSED and PARTIALLY_SCHEDULED orders
 * 4. For PARTIALLY_SCHEDULED orders, only include order_lines where partially_scheduled = true
 * 5. Only include order_lines belonging to selected production areas
 * 6. use_products_in_orders must be FALSE
 * 7. Group products by product_id and sum quantities
 */
class CreateAdvanceOrderFromOrdersTest extends TestCase
{
    use RefreshDatabase;

    private OrderRepository $repository;
    private ProductionArea $productionArea1;
    private ProductionArea $productionArea2;
    private User $user;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new OrderRepository();

        // Create production areas
        $this->productionArea1 = ProductionArea::create([
            'name' => 'Cocina Caliente',
            'description' => 'Área de cocina caliente',
        ]);

        $this->productionArea2 = ProductionArea::create([
            'name' => 'Cocina Fría',
            'description' => 'Área de cocina fría',
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

    public function test_creates_advance_order_with_correct_date_range(): void
    {
        // Create 3 orders with different dispatch dates
        $order1 = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $order2 = $this->createOrder('2025-11-07', OrderStatus::PROCESSED);
        $order3 = $this->createOrder('2025-11-10', OrderStatus::PROCESSED);

        // Create products in production area 1
        $product = $this->createProduct('Product A', $this->productionArea1);

        // Create order lines
        $this->createOrderLine($order1, $product, 10);
        $this->createOrderLine($order2, $product, 15);
        $this->createOrderLine($order3, $product, 20);

        // Execute
        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id, $order3->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id]
        );

        // Assert date range
        $this->assertEquals('2025-11-05', $advanceOrder->initial_dispatch_date->format('Y-m-d'));
        $this->assertEquals('2025-11-10', $advanceOrder->final_dispatch_date->format('Y-m-d'));
        $this->assertEquals('2025-11-04 08:00:00', $advanceOrder->preparation_datetime->format('Y-m-d H:i:s'));
    }

    public function test_use_products_in_orders_is_false(): void
    {
        $order = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $product = $this->createProduct('Product A', $this->productionArea1);
        $this->createOrderLine($order, $product, 10);

        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id]
        );

        $this->assertFalse($advanceOrder->use_products_in_orders);
    }

    public function test_only_includes_processed_orders(): void
    {
        $processedOrder = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $pendingOrder = $this->createOrder('2025-11-05', OrderStatus::PENDING);
        $canceledOrder = $this->createOrder('2025-11-05', OrderStatus::CANCELED);

        $product = $this->createProduct('Product A', $this->productionArea1);

        $this->createOrderLine($processedOrder, $product, 10);
        $this->createOrderLine($pendingOrder, $product, 5);
        $this->createOrderLine($canceledOrder, $product, 3);

        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$processedOrder->id, $pendingOrder->id, $canceledOrder->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id]
        );

        // Should only include product from PROCESSED order
        $this->assertEquals(1, $advanceOrder->products->count());
        $this->assertEquals(10, $advanceOrder->products->first()->pivot->ordered_quantity);
    }

    public function test_includes_all_order_lines_from_processed_orders(): void
    {
        $order = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $product = $this->createProduct('Product A', $this->productionArea1);

        $this->createOrderLine($order, $product, 10);
        $this->createOrderLine($order, $product, 15);
        $this->createOrderLine($order, $product, 5);

        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id]
        );

        // Should sum all quantities
        $this->assertEquals(1, $advanceOrder->products->count());
        $this->assertEquals(30, $advanceOrder->products->first()->pivot->ordered_quantity);
    }

    public function test_only_includes_partially_scheduled_lines_from_partially_scheduled_orders(): void
    {
        $order = $this->createOrder('2025-11-05', OrderStatus::PARTIALLY_SCHEDULED);
        $product = $this->createProduct('Product A', $this->productionArea1);

        // Create order lines with different partially_scheduled values
        $line1 = $this->createOrderLine($order, $product, 10);
        $line1->update(['partially_scheduled' => true]);

        $line2 = $this->createOrderLine($order, $product, 15);
        $line2->update(['partially_scheduled' => false]);

        $line3 = $this->createOrderLine($order, $product, 20);
        $line3->update(['partially_scheduled' => true]);

        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id]
        );

        // Should only include lines with partially_scheduled = true (10 + 20 = 30)
        $this->assertEquals(1, $advanceOrder->products->count());
        $this->assertEquals(30, $advanceOrder->products->first()->pivot->ordered_quantity);
    }

    public function test_only_includes_order_lines_from_selected_production_areas(): void
    {
        $order = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);

        $productArea1 = $this->createProduct('Product Area 1', $this->productionArea1);
        $productArea2 = $this->createProduct('Product Area 2', $this->productionArea2);

        $this->createOrderLine($order, $productArea1, 10);
        $this->createOrderLine($order, $productArea2, 15);

        // Only select production area 1
        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id]
        );

        // Should only include product from production area 1
        $this->assertEquals(1, $advanceOrder->products->count());
        $this->assertEquals($productArea1->id, $advanceOrder->products->first()->id);
        $this->assertEquals(10, $advanceOrder->products->first()->pivot->ordered_quantity);
    }

    public function test_groups_products_and_sums_quantities_across_orders(): void
    {
        $order1 = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $order2 = $this->createOrder('2025-11-06', OrderStatus::PROCESSED);
        $order3 = $this->createOrder('2025-11-07', OrderStatus::PARTIALLY_SCHEDULED);

        $productA = $this->createProduct('Product A', $this->productionArea1);
        $productB = $this->createProduct('Product B', $this->productionArea1);

        // Order 1: Product A x 10, Product B x 5
        $this->createOrderLine($order1, $productA, 10);
        $this->createOrderLine($order1, $productB, 5);

        // Order 2: Product A x 15
        $this->createOrderLine($order2, $productA, 15);

        // Order 3: Product A x 20 (partially_scheduled = true), Product B x 10 (partially_scheduled = false)
        $line1 = $this->createOrderLine($order3, $productA, 20);
        $line1->update(['partially_scheduled' => true]);

        $line2 = $this->createOrderLine($order3, $productB, 10);
        $line2->update(['partially_scheduled' => false]);

        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id, $order3->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id]
        );

        // Should have 2 products
        $this->assertEquals(2, $advanceOrder->products->count());

        // Product A: 10 + 15 + 20 = 45
        $productAInOrder = $advanceOrder->products->firstWhere('id', $productA->id);
        $this->assertEquals(45, $productAInOrder->pivot->ordered_quantity);

        // Product B: 5 (line2 not included because partially_scheduled = false)
        $productBInOrder = $advanceOrder->products->firstWhere('id', $productB->id);
        $this->assertEquals(5, $productBInOrder->pivot->ordered_quantity);
    }

    public function test_throws_exception_when_no_valid_orders(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No se encontraron pedidos con estado PROCESSED o PARTIALLY_SCHEDULED.');

        $pendingOrder = $this->createOrder('2025-11-05', OrderStatus::PENDING);

        $this->repository->createAdvanceOrderFromOrders(
            [$pendingOrder->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id]
        );
    }

    public function test_throws_exception_when_no_valid_order_lines(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No se encontraron líneas de pedido que cumplan con los criterios seleccionados.');

        $order = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $product = $this->createProduct('Product Area 2', $this->productionArea2);
        $this->createOrderLine($order, $product, 10);

        // Select only production area 1, but product belongs to area 2
        $this->repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id]
        );
    }

    public function test_complex_scenario_with_multiple_orders_and_production_areas(): void
    {
        // Create orders
        $order1 = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $order2 = $this->createOrder('2025-11-07', OrderStatus::PARTIALLY_SCHEDULED);
        $order3 = $this->createOrder('2025-11-10', OrderStatus::PROCESSED);

        // Create products in different areas
        $productHot1 = $this->createProduct('Hot Dish 1', $this->productionArea1);
        $productHot2 = $this->createProduct('Hot Dish 2', $this->productionArea1);
        $productCold1 = $this->createProduct('Cold Salad 1', $this->productionArea2);

        // Order 1 (PROCESSED): All lines included
        $this->createOrderLine($order1, $productHot1, 10);
        $this->createOrderLine($order1, $productCold1, 5);

        // Order 2 (PARTIALLY_SCHEDULED): Only partially_scheduled = true
        $line1 = $this->createOrderLine($order2, $productHot1, 15);
        $line1->update(['partially_scheduled' => true]);

        $line2 = $this->createOrderLine($order2, $productHot2, 8);
        $line2->update(['partially_scheduled' => false]);

        $line3 = $this->createOrderLine($order2, $productCold1, 12);
        $line3->update(['partially_scheduled' => true]);

        // Order 3 (PROCESSED): All lines included
        $this->createOrderLine($order3, $productHot2, 20);
        $this->createOrderLine($order3, $productCold1, 7);

        // Select both production areas
        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id, $order3->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id, $this->productionArea2->id]
        );

        // Verify results
        $this->assertEquals(3, $advanceOrder->products->count());

        // Hot Dish 1: 10 (order1) + 15 (order2, partially_scheduled=true) = 25
        $this->assertEquals(25, $advanceOrder->products->firstWhere('id', $productHot1->id)->pivot->ordered_quantity);

        // Hot Dish 2: 20 (order3) [order2 line excluded because partially_scheduled=false] = 20
        $this->assertEquals(20, $advanceOrder->products->firstWhere('id', $productHot2->id)->pivot->ordered_quantity);

        // Cold Salad 1: 5 (order1) + 12 (order2, partially_scheduled=true) + 7 (order3) = 24
        $this->assertEquals(24, $advanceOrder->products->firstWhere('id', $productCold1->id)->pivot->ordered_quantity);

        // Verify date range
        $this->assertEquals('2025-11-05', $advanceOrder->initial_dispatch_date->format('Y-m-d'));
        $this->assertEquals('2025-11-10', $advanceOrder->final_dispatch_date->format('Y-m-d'));
        $this->assertEquals('2025-11-04 08:00:00', $advanceOrder->preparation_datetime->format('Y-m-d H:i:s'));
        $this->assertFalse($advanceOrder->use_products_in_orders);
    }

    public function test_associates_production_areas_to_advance_order(): void
    {
        // Create orders
        $order1 = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $order2 = $this->createOrder('2025-11-07', OrderStatus::PROCESSED);

        // Create products in both production areas
        $productHot = $this->createProduct('Hot Dish', $this->productionArea1);
        $productCold = $this->createProduct('Cold Salad', $this->productionArea2);

        // Create order lines
        $this->createOrderLine($order1, $productHot, 10);
        $this->createOrderLine($order2, $productCold, 15);

        // Execute: Select both production areas
        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id, $this->productionArea2->id]
        );

        // Assert: AdvanceOrder should be associated with both production areas
        $this->assertEquals(2, $advanceOrder->productionAreas->count());

        $productionAreaIds = $advanceOrder->productionAreas->pluck('id')->toArray();
        $this->assertContains($this->productionArea1->id, $productionAreaIds);
        $this->assertContains($this->productionArea2->id, $productionAreaIds);
    }

    public function test_associates_only_selected_production_areas(): void
    {
        // Create orders
        $order1 = $this->createOrder('2025-11-05', OrderStatus::PROCESSED);
        $order2 = $this->createOrder('2025-11-07', OrderStatus::PROCESSED);

        // Create products in both production areas
        $productHot = $this->createProduct('Hot Dish', $this->productionArea1);
        $productCold = $this->createProduct('Cold Salad', $this->productionArea2);

        // Create order lines
        $this->createOrderLine($order1, $productHot, 10);
        $this->createOrderLine($order2, $productCold, 15);

        // Execute: Select only production area 1
        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id],
            '2025-11-04 08:00:00',
            [$this->productionArea1->id]
        );

        // Assert: AdvanceOrder should only be associated with production area 1
        $this->assertEquals(1, $advanceOrder->productionAreas->count());
        $this->assertEquals($this->productionArea1->id, $advanceOrder->productionAreas->first()->id);

        // Verify only products from area 1 are included
        $this->assertEquals(1, $advanceOrder->products->count());
        $this->assertEquals($productHot->id, $advanceOrder->products->first()->id);
    }

    public function test_calculates_total_to_produce_when_creating_from_orders(): void
    {
        // Create order with products
        $order = $this->createOrder('2025-11-10', OrderStatus::PROCESSED);
        $product = $this->createProduct('Test Product', $this->productionArea1);
        $this->createOrderLine($order, $product, 25);

        // Create AdvanceOrder from orders
        $advanceOrder = $this->repository->createAdvanceOrderFromOrders(
            [$order->id],
            '2025-11-09 08:00:00',
            [$this->productionArea1->id]
        );

        // Get the AdvanceOrderProduct
        $advanceOrderProduct = $advanceOrder->advanceOrderProducts()
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($advanceOrderProduct);

        // Validate that total_to_produce is calculated correctly
        // Since warehouse stock is 0, total_to_produce should equal ordered_quantity_new
        $this->assertEquals(25, $advanceOrderProduct->ordered_quantity);
        $this->assertEquals(25, $advanceOrderProduct->ordered_quantity_new);
        $this->assertEquals(0, $advanceOrderProduct->quantity);
        $this->assertEquals(25, $advanceOrderProduct->total_to_produce,
            'total_to_produce should be calculated by Observer when creating OP from Orders');
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