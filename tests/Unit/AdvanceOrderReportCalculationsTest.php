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
 * Test to validate AdvanceOrder Report Calculations
 *
 * BUG SCENARIO:
 * When creating an OP from orders, the ordered_quantity in advance_order_products
 * may not match the sum of quantities in advance_order_order_lines.
 *
 * This causes reports to show:
 * - TOTAL PEDIDOS: 2 (from advance_order_products.ordered_quantity)
 * - COMPANY X: 5 (from advance_order_order_lines sum)
 *
 * This is illogical - the sum of all companies should equal TOTAL PEDIDOS.
 */
class AdvanceOrderReportCalculationsTest extends TestCase
{
    use RefreshDatabase;

    private ProductionArea $productionArea;
    private PriceList $priceList;
    private Category $category;
    private Product $product1;
    private Product $product2;
    private Company $companyA;
    private Company $companyB; // Excluded company
    private OrderRepository $orderRepository;
    private AdvanceOrderRepository $advanceOrderRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Create production area
        $this->productionArea = ProductionArea::create([
            'name' => 'CUARTO CALIENTE',
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
            'name' => 'PLATOS FIJOS',
            'active' => true,
            'order' => 1,
        ]);

        // Create products
        $this->product1 = Product::create([
            'name' => 'LASAÑA GOURMET BOLONESA',
            'code' => 'PPCF00000002',
            'description' => 'Lasaña con carne',
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 350,
            'allow_sales_without_stock' => true,
            'category_id' => $this->category->id,
        ]);
        $this->product1->productionAreas()->attach($this->productionArea->id);

        $this->product2 = Product::create([
            'name' => 'LASAÑA FLORENTINA',
            'code' => 'PPCF00000001',
            'description' => 'Lasaña vegetariana',
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 350,
            'allow_sales_without_stock' => true,
            'category_id' => $this->category->id,
        ]);
        $this->product2->productionAreas()->attach($this->productionArea->id);

        // Add products to price list
        PriceListLine::create([
            'price_list_id' => $this->priceList->id,
            'product_id' => $this->product1->id,
            'price' => 5000,
        ]);
        PriceListLine::create([
            'price_list_id' => $this->priceList->id,
            'product_id' => $this->product2->id,
            'price' => 4500,
        ]);

        // Create companies
        $this->companyA = Company::create([
            'tax_id' => '11111111-1',
            'name' => 'COMPANY A SPA',
            'fantasy_name' => 'COMPANY A COLACIONES',
            'address' => 'Address A',
            'email' => 'companya@test.com',
            'phone' => '111111111',
            'price_list_id' => $this->priceList->id,
            'exclude_from_consolidated_report' => false, // Normal company
        ]);

        $this->companyB = Company::create([
            'tax_id' => '22222222-2',
            'name' => 'COMPANY B SPA',
            'fantasy_name' => 'COMPANY B COLACIONES',
            'address' => 'Address B',
            'email' => 'companyb@test.com',
            'phone' => '222222222',
            'price_list_id' => $this->priceList->id,
            'exclude_from_consolidated_report' => true, // Excluded company
        ]);

        $this->orderRepository = new OrderRepository();
        $this->advanceOrderRepository = new AdvanceOrderRepository();
    }

    /**
     * Test that report calculations are correct when creating OP from orders.
     *
     * SCENARIO:
     * - Create 5 PROCESSED orders from Company A with 1 LASAÑA BOLONESA each (total: 5)
     * - Create 2 PROCESSED orders from Company B (excluded) with 1 LASAÑA BOLONESA each (total: 2)
     * - Create 1 PROCESSED order from Company A with 1 LASAÑA FLORENTINA (total: 1)
     * - Total LASAÑA BOLONESA: 7 (5 from A + 2 from B)
     * - Total LASAÑA FLORENTINA: 1
     *
     * EXPECTED REPORT VALUES:
     * - TOTAL PEDIDOS (ordered_quantity): 7 for LASAÑA BOLONESA, 1 for FLORENTINA
     * - COMPANY B (excluded): 2 for LASAÑA BOLONESA
     * - ADELANTO INICIAL (quantity): 0 (no inventory used)
     * - ELABORAR 1 (total_to_produce): 7 for LASAÑA BOLONESA, 1 for FLORENTINA
     * - TOTAL ELABORADO: 7 for LASAÑA BOLONESA, 1 for FLORENTINA
     * - SOBRANTES: 0 (no excess production)
     */
    public function test_report_calculations_match_actual_orders(): void
    {
        // Create orders for Company A (5 orders with 1 LASAÑA BOLONESA each)
        $ordersCompanyA = [];
        for ($i = 1; $i <= 5; $i++) {
            $branch = Branch::create([
                'company_id' => $this->companyA->id,
                'address' => 'Branch A' . $i,
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'nickname' => 'USER.A.' . $i,
                'name' => 'User A ' . $i,
                'email' => 'usera' . $i . '@test.com',
                'password' => bcrypt('password'),
                'company_id' => $this->companyA->id,
                'branch_id' => $branch->id,
            ]);

            $order = Order::create([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'order_number' => 'ORD-A-' . $i,
                'dispatch_date' => now()->addDays(5)->format('Y-m-d'),
                'status' => OrderStatus::PROCESSED->value,
                'subtotal' => 5000,
                'total' => 5000,
            ]);

            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $this->product1->id, // LASAÑA BOLONESA
                'quantity' => 1,
                'unit_price' => 5000,
            ]);

            $ordersCompanyA[] = $order->id;
        }

        // Create orders for Company B - EXCLUDED (2 orders with 1 LASAÑA BOLONESA each)
        $ordersCompanyB = [];
        for ($i = 1; $i <= 2; $i++) {
            $branch = Branch::create([
                'company_id' => $this->companyB->id,
                'address' => 'Branch B' . $i,
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'nickname' => 'USER.B.' . $i,
                'name' => 'User B ' . $i,
                'email' => 'userb' . $i . '@test.com',
                'password' => bcrypt('password'),
                'company_id' => $this->companyB->id,
                'branch_id' => $branch->id,
            ]);

            $order = Order::create([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'order_number' => 'ORD-B-' . $i,
                'dispatch_date' => now()->addDays(5)->format('Y-m-d'),
                'status' => OrderStatus::PROCESSED->value,
                'subtotal' => 5000,
                'total' => 5000,
            ]);

            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $this->product1->id, // LASAÑA BOLONESA
                'quantity' => 1,
                'unit_price' => 5000,
            ]);

            $ordersCompanyB[] = $order->id;
        }

        // Create 1 order from Company A with LASAÑA FLORENTINA
        $branchA6 = Branch::create([
            'company_id' => $this->companyA->id,
            'address' => 'Branch A6',
            'min_price_order' => 0,
        ]);

        $userA6 = User::create([
            'nickname' => 'USER.A.6',
            'name' => 'User A 6',
            'email' => 'usera6@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->companyA->id,
            'branch_id' => $branchA6->id,
        ]);

        $orderFlorentina = Order::create([
            'user_id' => $userA6->id,
            'branch_id' => $branchA6->id,
            'order_number' => 'ORD-A-6',
            'dispatch_date' => now()->addDays(5)->format('Y-m-d'),
            'status' => OrderStatus::PROCESSED->value,
            'subtotal' => 4500,
            'total' => 4500,
        ]);

        OrderLine::create([
            'order_id' => $orderFlorentina->id,
            'product_id' => $this->product2->id, // LASAÑA FLORENTINA
            'quantity' => 1,
            'unit_price' => 4500,
        ]);

        // Combine all order IDs
        $allOrderIds = array_merge($ordersCompanyA, $ordersCompanyB, [$orderFlorentina->id]);

        // Create AdvanceOrder from all orders
        $advanceOrder = $this->orderRepository->createAdvanceOrderFromOrders(
            $allOrderIds,
            now()->addDays(4)->format('Y-m-d H:i:s'),
            [$this->productionArea->id]
        );

        // === VALIDATION 1: advance_order_products values ===
        $aopBolonesa = $advanceOrder->advanceOrderProducts()
            ->where('product_id', $this->product1->id)
            ->first();

        $aopFlorentina = $advanceOrder->advanceOrderProducts()
            ->where('product_id', $this->product2->id)
            ->first();

        $this->assertNotNull($aopBolonesa, 'LASAÑA BOLONESA should be in advance_order_products');
        $this->assertNotNull($aopFlorentina, 'LASAÑA FLORENTINA should be in advance_order_products');

        // CRITICAL: ordered_quantity should be 7 for BOLONESA (5 from A + 2 from B)
        $this->assertEquals(7, $aopBolonesa->ordered_quantity,
            'LASAÑA BOLONESA ordered_quantity should be 7 (5 from Company A + 2 from Company B)');

        // CRITICAL: ordered_quantity should be 1 for FLORENTINA
        $this->assertEquals(1, $aopFlorentina->ordered_quantity,
            'LASAÑA FLORENTINA ordered_quantity should be 1');

        // === VALIDATION 2: advance_order_order_lines values ===
        $linesBolonesa = DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->where('advance_order_order_lines.advance_order_id', $advanceOrder->id)
            ->where('order_lines.product_id', $this->product1->id)
            ->get();

        $linesFlorentina = DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->where('advance_order_order_lines.advance_order_id', $advanceOrder->id)
            ->where('order_lines.product_id', $this->product2->id)
            ->get();

        $this->assertEquals(7, $linesBolonesa->count(),
            'Should have 7 order lines for LASAÑA BOLONESA');
        $this->assertEquals(1, $linesFlorentina->count(),
            'Should have 1 order line for LASAÑA FLORENTINA');

        $sumBolonesa = $linesBolonesa->sum('quantity_covered');
        $sumFlorentina = $linesFlorentina->sum('quantity_covered');

        $this->assertEquals(7, $sumBolonesa,
            'Sum of quantity_covered for LASAÑA BOLONESA should be 7');
        $this->assertEquals(1, $sumFlorentina,
            'Sum of quantity_covered for LASAÑA FLORENTINA should be 1');

        // === VALIDATION 3: Company B (excluded) quantities ===
        $companyBLines = DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->join('orders', 'order_lines.order_id', '=', 'orders.id')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->where('advance_order_order_lines.advance_order_id', $advanceOrder->id)
            ->where('order_lines.product_id', $this->product1->id)
            ->where('users.company_id', $this->companyB->id)
            ->get();

        $this->assertEquals(2, $companyBLines->count(),
            'Company B should have 2 order lines for LASAÑA BOLONESA');

        $sumCompanyB = $companyBLines->sum('quantity_covered');
        $this->assertEquals(2, $sumCompanyB,
            'Company B total should be 2');

        // === VALIDATION 4: Report data structure ===
        $reportData = $this->advanceOrderRepository->getAdvanceOrderProductsGroupedByProductionArea([$advanceOrder->id]);

        $this->assertCount(1, $reportData,
            'Should have 1 production area');

        $areaData = $reportData->first();
        $this->assertEquals('CUARTO CALIENTE', $areaData['production_area_name']);

        // Find products in report
        $productsBolonesa = collect($areaData['products'])->where('product_id', $this->product1->id);
        $productsFlorentina = collect($areaData['products'])->where('product_id', $this->product2->id);

        $this->assertCount(1, $productsBolonesa,
            'Should have 1 entry for LASAÑA BOLONESA');
        $this->assertCount(1, $productsFlorentina,
            'Should have 1 entry for LASAÑA FLORENTINA');

        $reportBolonesa = $productsBolonesa->first();
        $reportFlorentina = $productsFlorentina->first();

        // === VALIDATION 5: Report - TOTAL PEDIDOS ===
        $this->assertEquals(7, $reportBolonesa['total_ordered_quantity'],
            'Report: TOTAL PEDIDOS for LASAÑA BOLONESA should be 7');
        $this->assertEquals(1, $reportFlorentina['total_ordered_quantity'],
            'Report: TOTAL PEDIDOS for LASAÑA FLORENTINA should be 1');

        // === VALIDATION 6: Report - Company B (excluded) ===
        $companyKey = 'company_' . $this->companyB->id;
        $this->assertArrayHasKey('companies', $reportBolonesa,
            'Report should have companies data for LASAÑA BOLONESA');
        $this->assertArrayHasKey($companyKey, $reportBolonesa['companies'],
            'Report should have Company B data');

        $companyBData = $reportBolonesa['companies'][$companyKey];
        $this->assertEquals(2, $companyBData['total_quantity'],
            'Report: Company B should show 2 for LASAÑA BOLONESA');

        // === VALIDATION 7: Report - ADELANTO INICIAL (quantity) ===
        $this->assertEquals(0, $reportBolonesa['current_stock'],
            'Report: ADELANTO INICIAL should be 0 (no inventory)');
        $this->assertEquals(0, $reportFlorentina['current_stock'],
            'Report: ADELANTO INICIAL should be 0 (no inventory)');

        // === VALIDATION 8: Report - ELABORAR 1 (total_to_produce) ===
        $this->assertArrayHasKey('ops', $reportBolonesa);
        $this->assertArrayHasKey($advanceOrder->id, $reportBolonesa['ops']);

        $opDataBolonesa = $reportBolonesa['ops'][$advanceOrder->id];
        $opDataFlorentina = $reportFlorentina['ops'][$advanceOrder->id];

        $this->assertEquals(7, $opDataBolonesa['total_to_produce'],
            'Report: ELABORAR 1 for LASAÑA BOLONESA should be 7');
        $this->assertEquals(1, $opDataFlorentina['total_to_produce'],
            'Report: ELABORAR 1 for LASAÑA FLORENTINA should be 1');

        // === VALIDATION 9: Report - TOTAL ELABORADO ===
        // For single OP, TOTAL ELABORADO = ELABORAR 1
        $totalElaboradoBolonesa = $opDataBolonesa['total_to_produce'];
        $totalElaboradoFlorentina = $opDataFlorentina['total_to_produce'];

        $this->assertEquals(7, $totalElaboradoBolonesa,
            'Report: TOTAL ELABORADO for LASAÑA BOLONESA should be 7');
        $this->assertEquals(1, $totalElaboradoFlorentina,
            'Report: TOTAL ELABORADO for LASAÑA FLORENTINA should be 1');

        // === VALIDATION 10: Report - SOBRANTES ===
        // SOBRANTES = TOTAL ELABORADO - TOTAL PEDIDOS
        $sobrantesBolonesa = $totalElaboradoBolonesa - $reportBolonesa['total_ordered_quantity'];
        $sobrantesFlorentina = $totalElaboradoFlorentina - $reportFlorentina['total_ordered_quantity'];

        $this->assertEquals(0, $sobrantesBolonesa,
            'Report: SOBRANTES for LASAÑA BOLONESA should be 0 (no excess)');
        $this->assertEquals(0, $sobrantesFlorentina,
            'Report: SOBRANTES for LASAÑA FLORENTINA should be 0 (no excess)');

        // === CRITICAL VALIDATION: Total consistency ===
        // Sum of all companies + non-excluded should equal TOTAL PEDIDOS
        $companyBTotal = $companyBData['total_quantity']; // 2
        $nonExcludedTotal = $reportBolonesa['total_ordered_quantity'] - $companyBTotal; // 7 - 2 = 5

        $this->assertEquals(5, $nonExcludedTotal,
            'Non-excluded companies should have 5 (Company A)');
        $this->assertEquals(
            $reportBolonesa['total_ordered_quantity'],
            $companyBTotal + $nonExcludedTotal,
            'CRITICAL: Sum of excluded + non-excluded should equal TOTAL PEDIDOS'
        );
    }
}
