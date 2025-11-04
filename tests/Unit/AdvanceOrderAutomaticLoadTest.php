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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test Automatic Product Loading (use_products_in_orders = true)
 *
 * SCENARIO:
 * 1. Create OP manually with use_products_in_orders = false
 * 2. Create orders in the date range
 * 3. Update OP to use_products_in_orders = true
 * 4. Verify products are loaded automatically
 * 5. Verify listener syncs orders/order_lines from date range
 *
 * EXPECTED:
 * - Products should be loaded from orders in date range
 * - All orders in date range should be synced
 */
class AdvanceOrderAutomaticLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-01-15 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_automatic_load_syncs_orders_from_date_range(): void
    {
        // 1. Setup basic data
        $priceList = PriceList::create(['name' => 'Test Price List']);
        $company = Company::create([
            'tax_id' => '12345678-9',
            'name' => 'Test Company',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'company@test.com',
            'phone' => '123456789',
            'price_list_id' => $priceList->id,
            'exclude_from_consolidated_report' => false,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'address' => 'Branch Address',
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

        // 2. Create category and product
        $category = Category::create([
            'name' => 'PLATOS PRINCIPALES',
            'active' => true,
        ]);

        $productionArea = ProductionArea::create([
            'name' => 'COCINA CENTRAL',
            'active' => true,
        ]);

        $product = Product::create([
            'name' => 'LASAÑA BOLONESA',
            'description' => 'Lasaña con salsa bolonesa',
            'code' => 'LAS-BOL-001',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 350,
            'allow_sales_without_stock' => true,
        ]);

        $product->productionAreas()->attach($productionArea->id);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'amount' => 5000,
        ]);

        // 3. Create 7 orders in date range
        $dispatchDate = Carbon::parse('2025-01-20');

        for ($i = 1; $i <= 7; $i++) {
            $order = Order::create([
                'user_id' => $user->id,
                'date' => $dispatchDate->format('Y-m-d'),
                'dispatch_date' => $dispatchDate->format('Y-m-d'),
                'status' => OrderStatus::PROCESSED,
                'notes' => "Order $i",
            ]);

            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 5000,
                'price' => 5000,
            ]);
        }

        // 4. Create AdvanceOrder with use_products_in_orders = false
        $advanceOrder = AdvanceOrder::create([
            'initial_dispatch_date' => $dispatchDate->format('Y-m-d'),
            'final_dispatch_date' => $dispatchDate->format('Y-m-d'),
            'preparation_datetime' => $dispatchDate->copy()->subDays(3)->format('Y-m-d H:i:s'),
            'use_products_in_orders' => false,
            'status' => AdvanceOrderStatus::PENDING,
        ]);

        $advanceOrder->productionAreas()->attach($productionArea->id);

        // 5. VERIFY: No products yet
        $this->assertEquals(0, $advanceOrder->advanceOrderProducts()->count(),
            'OP should have no products initially');

        // 6. AUTOMATIC LOAD: Update use_products_in_orders to true
        $advanceOrder->update(['use_products_in_orders' => true]);

        // Refresh to get updated relations
        $advanceOrder->refresh();

        // 7. VERIFY: Product was loaded automatically
        $this->assertEquals(1, $advanceOrder->advanceOrderProducts()->count(),
            'OP should have 1 product loaded automatically');

        $advanceOrderProduct = $advanceOrder->advanceOrderProducts()->first();
        $this->assertEquals($product->id, $advanceOrderProduct->product_id,
            'Loaded product should be the correct one');
        $this->assertEquals(7, $advanceOrderProduct->ordered_quantity,
            'ordered_quantity should be 7 (sum of all order lines)');

        // 8. VERIFY: Listener synced ALL 7 orders from date range
        $ordersInPivot = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->count();

        $this->assertEquals(7, $ordersInPivot,
            'Automatic load should sync ALL 7 orders from date range');

        $orderLinesInPivot = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $advanceOrder->id)
            ->count();

        $this->assertEquals(7, $orderLinesInPivot,
            'Automatic load should sync ALL 7 order lines from date range');

        // 9. VERIFY: Orders are the correct ones
        $orderIdsInPivot = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->pluck('order_id')
            ->sort()
            ->values()
            ->toArray();

        $allOrderIds = Order::where('dispatch_date', $dispatchDate->format('Y-m-d'))
            ->pluck('id')
            ->sort()
            ->values()
            ->toArray();

        $this->assertEquals($allOrderIds, $orderIdsInPivot,
            'Pivot should contain all orders from date range');
    }
}
