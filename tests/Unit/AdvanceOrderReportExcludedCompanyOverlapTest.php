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
 * Test Report Excluded Company Calculation with Overlapping OPs
 *
 * SCENARIO (replicates production bug - DELICIUS FOOD COLACIONES):
 * 1. Create 5 orders from excluded company for LASAÑA on 2025-11-05:
 *    - Order #1, #2, #3 (will be in all OPs)
 *    - Order #4, #5 (will be only in OP #3)
 * 2. Create OP #1 with orders #1, #2, #3 (3 orders, 3 units)
 * 3. Create OP #2 with orders #1, #2, #3 (3 orders, 3 units - OVERLAP)
 * 4. Create OP #3 with all 5 orders (5 orders, 5 units - OVERLAP)
 *
 * CURRENT BEHAVIOR (WRONG):
 * - Report shows excluded company total = 3 + 3 + 5 = 11 (counts duplicates)
 *
 * EXPECTED BEHAVIOR (CORRECT):
 * - Report should show excluded company total = 5 (unique orders only)
 */
class AdvanceOrderReportExcludedCompanyOverlapTest extends TestCase
{
    use RefreshDatabase;

    private OrderRepository $orderRepository;
    private AdvanceOrderRepository $advanceOrderRepository;
    private ProductionArea $productionArea;
    private Product $product;
    private Company $excludedCompany;

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
            'name' => 'PLATOS',
            'active' => true,
            'order' => 1,
        ]);

        // Create EXCLUDED company (like DELICIUS FOOD COLACIONES)
        $this->excludedCompany = Company::create([
            'tax_id' => '11111111-1',
            'name' => 'TEST EXCLUDED COMPANY',
            'fantasy_name' => 'TEST EXCLUDED',
            'address' => 'Test Address',
            'email' => 'test@test.com',
            'phone' => '111111111',
            'price_list_id' => $priceList->id,
            'exclude_from_consolidated_report' => true, // EXCLUDED
        ]);

        $branch = Branch::create([
            'company_id' => $this->excludedCompany->id,
            'address' => 'Test Address',
            'min_price_order' => 0,
        ]);

        $this->product = Product::create([
            'name' => 'LASAÑA BOLONESA',
            'description' => 'Lasaña',
            'code' => 'LAS-BOL-001',
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
            'amount' => 5000,
        ]);

        // Create 5 users from excluded company
        $dispatchDate = Carbon::parse('2025-11-05');

        for ($i = 1; $i <= 5; $i++) {
            $user = User::create([
                'nickname' => "TEST.USER.$i",
                'name' => "Test User $i",
                'email' => "test$i@test.com",
                'password' => bcrypt('password'),
                'company_id' => $this->excludedCompany->id,
                'branch_id' => $branch->id,
                'validate_subcategory_rules' => false,
            ]);

            $order = Order::create([
                'user_id' => $user->id,
                'date' => $dispatchDate->format('Y-m-d'),
                'dispatch_date' => $dispatchDate->format('Y-m-d'),
                'status' => OrderStatus::PROCESSED,
                'notes' => "Order $i",
            ]);

            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 5000,
                'price' => 5000,
            ]);
        }

        $this->orderRepository = new OrderRepository();
        $this->advanceOrderRepository = new AdvanceOrderRepository();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_report_shows_correct_excluded_company_total_with_overlap(): void
    {
        $dispatchDate = Carbon::parse('2025-11-05');
        $preparationDate = $dispatchDate->copy()->subDays(3);

        // Get all orders
        $allOrders = Order::where('dispatch_date', $dispatchDate->format('Y-m-d'))
            ->orderBy('id')
            ->get();

        $this->assertEquals(5, $allOrders->count(), 'Should have 5 orders created');

        // Create OP #1 with orders 1, 2, 3
        $op1 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$allOrders[0]->id, $allOrders[1]->id, $allOrders[2]->id],
            $preparationDate->format('Y-m-d H:i:s'),
            [$this->productionArea->id]
        );
        $op1->update(['status' => AdvanceOrderStatus::EXECUTED]);

        // Create OP #2 with orders 1, 2, 3 (OVERLAP with OP #1)
        $op2 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$allOrders[0]->id, $allOrders[1]->id, $allOrders[2]->id],
            $preparationDate->format('Y-m-d H:i:s'),
            [$this->productionArea->id]
        );
        $op2->update(['status' => AdvanceOrderStatus::EXECUTED]);

        // Create OP #3 with all 5 orders (OVERLAP with OP #1 and #2)
        $op3 = $this->orderRepository->createAdvanceOrderFromOrders(
            $allOrders->pluck('id')->toArray(),
            $preparationDate->format('Y-m-d H:i:s'),
            [$this->productionArea->id]
        );
        $op3->update(['status' => AdvanceOrderStatus::EXECUTED]);

        // Get report data
        $reportData = $this->advanceOrderRepository->getAdvanceOrderProductsGroupedByProductionArea([
            $op1->id,
            $op2->id,
            $op3->id,
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

        // Verify excluded company data
        $this->assertArrayHasKey('companies', $productData, 'Product should have companies data');
        $this->assertNotEmpty($productData['companies'], 'Product should have at least one excluded company');

        // Find our excluded company in the data
        $companyData = null;
        foreach ($productData['companies'] as $company) {
            if ($company['company_id'] === $this->excludedCompany->id) {
                $companyData = $company;
                break;
            }
        }

        $this->assertNotNull($companyData, 'Excluded company should be in report');

        // CRITICAL ASSERTION: Company total should be 5 (unique orders)
        // NOT 11 (3 + 3 + 5 with duplicates)
        $this->assertEquals(5, $companyData['total_quantity'],
            'Excluded company total should be 5 (unique orders), NOT 11 (3 + 3 + 5 with duplicates from overlapping OPs)');
    }
}
