<?php

namespace Tests\Unit;

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
use Tests\Helpers\AdvanceOrderReportTestHelper;
use Tests\TestCase;

/**
 * Partially Scheduled Order Flow Test
 *
 * This test validates the critical behavior of partially_scheduled flags:
 * - Products with partially_scheduled = false should NOT be included in OPs
 * - Products with partially_scheduled = true should be included
 * - When order changes from PARTIALLY_SCHEDULED to PROCESSED, all products are included
 *
 * SCENARIO:
 * - Single Order A (PARTIALLY_SCHEDULED) with 3 products
 * - Create 4 OPs at different moments
 * - Validate which products are included/excluded based on partially_scheduled flags
 *
 * KEY VALIDATIONS:
 * - OP #1: Only Product A included (only one with partially_scheduled = true)
 * - OP #2: Only Product A included (still the only one)
 * - OP #3: Products A and B included (B now has partially_scheduled = true), Product C excluded
 * - OP #4: All products included (order changed to PROCESSED)
 */
class PartiallyScheduledOrderFlowTest extends TestCase
{
    use RefreshDatabase;
    use AdvanceOrderReportTestHelper;

    // Test date
    private Carbon $dateFA;

    // Models
    private User $user;
    private Company $company;
    private Category $category;
    private ProductionArea $productionArea;
    private Warehouse $warehouse;

    // Products
    private Product $productA;
    private Product $productB;
    private Product $productC;

    // Order
    private Order $orderA;

    // Repositories
    private OrderRepository $orderRepository;
    private WarehouseRepository $warehouseRepository;

    // Report Configuration
    private \App\Models\ReportConfiguration $reportConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Set test date
        $this->dateFA = Carbon::parse('2025-11-20');
        Carbon::setTestNow('2025-11-18 10:00:00');

        // Initialize repositories
        $this->orderRepository = app(OrderRepository::class);
        $this->warehouseRepository = app(WarehouseRepository::class);

        // Create test environment
        $this->createTestEnvironment();

        // Create default report configuration for tests
        $this->reportConfig = \App\Models\ReportConfiguration::create([
            'name' => 'test_default_config',
            'description' => 'Default configuration for tests',
            'use_groupers' => true,
            'exclude_cafeterias' => true,
            'exclude_agreements' => true,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_partially_scheduled_order_flow_across_eight_moments(): void
    {
        // ==================================================================
        // MOMENT 1: Create Order A (PARTIALLY_SCHEDULED)
        // ==================================================================
        $this->createMoment1Order();

        // Validate initial production status
        $this->orderA->refresh();
        $this->assertEquals(
            OrderProductionStatus::NOT_PRODUCED->value,
            $this->orderA->production_status,
            'MOMENT 1: Order A should be NOT_PRODUCED (no OPs executed yet)'
        );

        // Validate production detail
        $detail = $this->orderRepository->getProductionDetail($this->orderA);
        $this->assertEquals(OrderProductionStatus::NOT_PRODUCED->value, $detail['production_status']);
        $this->assertEquals(3, $detail['summary']['total_products']);
        $this->assertEquals(0, $detail['summary']['fully_produced_count']);
        $this->assertEquals(0, $detail['summary']['partially_produced_count']);
        $this->assertEquals(3, $detail['summary']['not_produced_count']);
        $this->assertEquals(0, $detail['summary']['total_coverage_percentage']);

        // ==================================================================
        // MOMENT 2: Create OP #1 (only Product A has partially_scheduled = true)
        // ==================================================================
        $op1 = $this->createOp1();

        $this->validateOp1IncludesOnlyProductA($op1);
        $this->validateOp1ExcludesProductsBAndC($op1);
        $this->validateOp1Pivots($op1);
        $this->validateOp1Calculations($op1);

        // Execute OP #1
        $this->executeOp($op1);

        // Validate production status after OP #1 executed
        // Reload the order from database to get updated values
        $this->orderA = \App\Models\Order::find($this->orderA->id);

        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $this->orderA->production_status,
            'MOMENT 2: Order A should be PARTIALLY_PRODUCED after OP #1 (only Product A covered: 15/15, missing B and C)'
        );

        // Validate production detail MOMENT 2
        $detail = $this->orderRepository->getProductionDetail($this->orderA);
        $this->assertEquals(OrderProductionStatus::PARTIALLY_PRODUCED->value, $detail['production_status']);
        $this->assertEquals(3, $detail['summary']['total_products']);
        $this->assertEquals(1, $detail['summary']['fully_produced_count'], 'MOMENT 2: Product A should be fully produced');
        $this->assertEquals(0, $detail['summary']['partially_produced_count']);
        $this->assertEquals(2, $detail['summary']['not_produced_count'], 'MOMENT 2: Products B and C should be not produced');

        // Product A: 15 produced of 15 required = 100%
        $productA = collect($detail['products'])->firstWhere('product_id', $this->productA->id);
        $this->assertEquals(15, $productA['required_quantity']);
        $this->assertEquals(15, $productA['produced_quantity']);
        $this->assertEquals(0, $productA['pending_quantity']);
        $this->assertEquals(100, $productA['coverage_percentage']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productA['status']);

        // ==================================================================
        // MOMENT 3: Update Order A - Product A quantity increases
        // ==================================================================
        $this->updateMoment3Order();

        // ==================================================================
        // MOMENT 4: Create OP #2 (still only Product A has partially_scheduled = true)
        // ==================================================================
        $op2 = $this->createOp2();

        $this->validateOp2IncludesOnlyProductA($op2);
        $this->validateOp2ExcludesProductsBAndC($op2);
        $this->validateOp2Pivots($op2);
        $this->validateOp2Calculations($op2);

        // Execute OP #2
        $this->executeOp($op2);

        // Validate production status after OP #2 executed
        $this->orderA->refresh();
        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $this->orderA->production_status,
            'MOMENT 4: Order A should be PARTIALLY_PRODUCED after OP #2 (only Product A fully covered: 23/23, missing B and C)'
        );

        // Validate production detail MOMENT 4
        $detail = $this->orderRepository->getProductionDetail($this->orderA);
        $this->assertEquals(OrderProductionStatus::PARTIALLY_PRODUCED->value, $detail['production_status']);
        $this->assertEquals(3, $detail['summary']['total_products']);
        $this->assertEquals(1, $detail['summary']['fully_produced_count'], 'MOMENT 4: Product A should be fully produced (23/23)');
        $this->assertEquals(0, $detail['summary']['partially_produced_count']);
        $this->assertEquals(2, $detail['summary']['not_produced_count'], 'MOMENT 4: Products B and C should be not produced');

        // Product A: 23 produced of 23 required (15 from OP#1 + 8 from OP#2)
        $productA = collect($detail['products'])->firstWhere('product_id', $this->productA->id);
        $this->assertEquals(23, $productA['required_quantity']);
        $this->assertEquals(23, $productA['produced_quantity'], 'MOMENT 4: Product A should have 23 produced (15+8)');
        $this->assertEquals(0, $productA['pending_quantity']);
        $this->assertEquals(100, $productA['coverage_percentage']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productA['status']);

        // ==================================================================
        // MOMENT 5: Update Order A - Product B becomes partially_scheduled = true
        // ==================================================================
        $this->updateMoment5Order();

        // ==================================================================
        // MOMENT 6: Create OP #3 (Products A and B have partially_scheduled = true)
        // ==================================================================
        $op3 = $this->createOp3();

        $this->validateOp3IncludesProductsAAndB($op3);
        $this->validateOp3ExcludesProductC($op3);
        $this->validateOp3Pivots($op3);
        $this->validateOp3Calculations($op3);

        // Execute OP #3
        $this->executeOp($op3);

        // Validate production status after OP #3 executed
        $this->orderA->refresh();
        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $this->orderA->production_status,
            'MOMENT 6: Order A should be PARTIALLY_PRODUCED after OP #3 (Products A: 23/23 and B: 18/18 covered, missing C)'
        );

        // Validate production detail MOMENT 6
        $detail = $this->orderRepository->getProductionDetail($this->orderA);
        $this->assertEquals(OrderProductionStatus::PARTIALLY_PRODUCED->value, $detail['production_status']);
        $this->assertEquals(3, $detail['summary']['total_products']);
        $this->assertEquals(2, $detail['summary']['fully_produced_count'], 'MOMENT 6: Products A and B should be fully produced');
        $this->assertEquals(0, $detail['summary']['partially_produced_count']);
        $this->assertEquals(1, $detail['summary']['not_produced_count'], 'MOMENT 6: Product C should be not produced');

        // Product A: still 23/23
        $productA = collect($detail['products'])->firstWhere('product_id', $this->productA->id);
        $this->assertEquals(23, $productA['produced_quantity']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productA['status']);

        // Product B: 18/18 produced
        $productB = collect($detail['products'])->firstWhere('product_id', $this->productB->id);
        $this->assertEquals(18, $productB['required_quantity']);
        $this->assertEquals(18, $productB['produced_quantity']);
        $this->assertEquals(0, $productB['pending_quantity']);
        $this->assertEquals(100, $productB['coverage_percentage']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productB['status']);

        // Product C: 0/25 (not produced yet)
        $productC = collect($detail['products'])->firstWhere('product_id', $this->productC->id);
        $this->assertEquals(25, $productC['required_quantity']);
        $this->assertEquals(0, $productC['produced_quantity']);
        $this->assertEquals(25, $productC['pending_quantity']);
        $this->assertEquals(0, $productC['coverage_percentage']);
        $this->assertEquals(OrderProductionStatus::NOT_PRODUCED->value, $productC['status']);

        // ==================================================================
        // MOMENT 7: Update Order A - Change to PROCESSED
        // ==================================================================
        $this->updateMoment7Order();

        // ==================================================================
        // MOMENT 8: Create OP #4 (all products included because order is PROCESSED)
        // ==================================================================
        $op4 = $this->createOp4();

        $this->validateOp4IncludesAllProducts($op4);
        $this->validateOp4Pivots($op4);
        $this->validateOp4Calculations($op4);

        // Execute OP #4
        $this->executeOp($op4);

        // Validate production status after OP #4 executed
        $this->orderA->refresh();
        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $this->orderA->production_status,
            'MOMENT 8: Order A should be FULLY_PRODUCED after OP #4 (ALL products covered: A: 23/23, B: 18/18, C: 31/31)'
        );

        // Validate production detail MOMENT 8 - FULLY PRODUCED
        $detail = $this->orderRepository->getProductionDetail($this->orderA);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $detail['production_status']);
        $this->assertEquals(3, $detail['summary']['total_products']);
        $this->assertEquals(3, $detail['summary']['fully_produced_count'], 'MOMENT 8: All 3 products should be fully produced');
        $this->assertEquals(0, $detail['summary']['partially_produced_count']);
        $this->assertEquals(0, $detail['summary']['not_produced_count']);
        $this->assertEquals(100, $detail['summary']['total_coverage_percentage'], 'MOMENT 8: Total coverage should be 100%');

        // Product A: 23/23 (still fully produced)
        $productA = collect($detail['products'])->firstWhere('product_id', $this->productA->id);
        $this->assertEquals(23, $productA['produced_quantity']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productA['status']);

        // Product B: 18/18 (still fully produced)
        $productB = collect($detail['products'])->firstWhere('product_id', $this->productB->id);
        $this->assertEquals(18, $productB['produced_quantity']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productB['status']);

        // Product C: 31/31 (NOW fully produced)
        $productC = collect($detail['products'])->firstWhere('product_id', $this->productC->id);
        $this->assertEquals(31, $productC['required_quantity']);
        $this->assertEquals(31, $productC['produced_quantity'], 'MOMENT 8: Product C should have 31 produced');
        $this->assertEquals(0, $productC['pending_quantity']);
        $this->assertEquals(100, $productC['coverage_percentage']);
        $this->assertEquals(OrderProductionStatus::FULLY_PRODUCED->value, $productC['status']);
    }

    /**
     * Test consolidated report date range validation
     *
     * This test runs the complete partially scheduled order flow scenario
     * and validates that the final consolidated report (with all 4 OPs)
     * has the correct date range in the header.
     *
     * VALIDATION:
     * - Report header should show: "Desde: [earliest dispatch date] - Hasta: [latest dispatch date]"
     * - Date range should match the dispatch dates from all orders included in the 4 OPs
     */
    public function test_consolidated_report_date_range_is_correct_after_all_ops(): void
    {
        // Run the complete scenario and get all 4 OPs
        [$op1, $op2, $op3, $op4] = $this->runPartiallyScheduledOrderScenario();

        // Get IDs of all OPs
        $opIds = [$op1->id, $op2->id, $op3->id, $op4->id];

        // Generate consolidated report with all parameters set to true
        $filePath = $this->generateConsolidatedReport(
            $opIds,
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true
        );

        // Load the Excel file
        $spreadsheet = $this->loadExcelFile($filePath);

        // Extract date range from report header
        $dateRange = $this->extractDateRangeFromReport($spreadsheet);

        // Validate that date range matches the advance orders' dispatch dates
        $this->validateDateRangeMatchesAdvanceOrders($opIds, $dateRange);

        // Clean up test file
        // $this->cleanupTestFile($filePath);  // Commented to keep the file for inspection
    }

    /**
     * Test: Validate OP columns (ADELANTO and ELABORAR) in consolidated report
     *
     * This test validates:
     * 1. Correct number of ADELANTO columns (4 columns for 4 OPs)
     * 2. Correct number of ELABORAR columns (4 columns for 4 OPs)
     * 3. Values in those columns match database quantities
     */
    public function test_consolidated_report_op_columns_are_correct(): void
    {
        // Run the complete scenario and get all 4 OPs
        [$op1, $op2, $op3, $op4] = $this->runPartiallyScheduledOrderScenario();

        // Get IDs of all OPs
        $opIds = [$op1->id, $op2->id, $op3->id, $op4->id];

        // Generate consolidated report with all parameters set to true
        $filePath = $this->generateConsolidatedReport(
            $opIds,
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true
        );

        // Load the Excel file
        $spreadsheet = $this->loadExcelFile($filePath);

        // Validate OP columns
        $this->validateOpColumnsInReport($spreadsheet, $opIds);

        // Clean up test file
        // $this->cleanupTestFile($filePath);  // Commented to keep the file for inspection
    }

    /**
     * Test: Simple single OP with 10 excluded companies
     *
     * This test creates a simple scenario to validate excluded company columns:
     * - 10 companies (all with exclude_from_consolidated_report = true)
     * - 10 users (one per company)
     * - 10 PROCESSED orders (one per user, different quantities)
     * - 1 OP that includes all 10 orders
     * - Validates: Each company column shows correct quantity
     */
    public function test_single_op_with_ten_excluded_companies(): void
    {
        // Use legacy DiscriminatedCompanyColumnProvider for this test
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\DiscriminatedCompanyColumnProvider::class
        );

        // Define quantities for each company (different to ensure correct mapping)
        $companyQuantities = [5, 10, 15, 20, 25, 30, 35, 40, 45, 50];
        $expectedTotalPedidos = array_sum($companyQuantities); // 275

        $companies = [];
        $users = [];
        $orders = [];

        // Create 10 companies, users, and orders
        for ($i = 1; $i <= 10; $i++) {
            // Create company (excluded from consolidated)
            $company = Company::create([
                'name' => "Test Company {$i} S.A.",
                'fantasy_name' => "Company {$i}",
                'tax_id' => str_pad($i, 8, '0', STR_PAD_LEFT) . "-{$i}",
                'company_code' => "COMP{$i}",
                'email' => "company{$i}@test.com",
                'phone_number' => str_repeat((string)$i, 9),
                'address' => "Address {$i}",
                'price_list_id' => $this->company->price_list_id,
                'exclude_from_consolidated_report' => true, // Show as discriminated column
            ]);

            $branch = Branch::create([
                'company_id' => $company->id,
                'address' => "Branch {$i} Address",
                'fantasy_name' => "Branch {$i}",
                'branch_code' => "BR{$i}",
                'phone' => str_repeat((string)$i, 9),
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'name' => "User {$i}",
                'email' => "user{$i}@test.com",
                'nickname' => "user{$i}",
                'password' => bcrypt('password'),
                'company_id' => $company->id,
                'branch_id' => $branch->id,
            ]);

            // Create PROCESSED order with unique quantity
            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $companyQuantities[$i - 1], false);

            $companies[] = $company;
            $users[] = $user;
            $orders[] = $order;
        }

        // Create single OP with all 10 orders
        $orderIds = array_map(fn($order) => $order->id, $orders);
        $op1 = $this->createAndExecuteOp($orderIds);

        // Generate consolidated report
        $filePath = $this->generateConsolidatedReport(
            [$op1->id],
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true
        );

        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Find header row
        $headerRow = $this->findHeaderRow($spreadsheet);
        $this->assertNotNull($headerRow, 'Header row not found');

        // Find TOTAL PEDIDOS column and product code column
        $totalPedidosCol = null;
        $productCodeCol = null;
        $companyColumns = []; // Track all company columns with their fantasy names

        $col = 'A';
        while (true) {
            $headerValue = $sheet->getCell($col . $headerRow)->getValue();

            if ($headerValue === null || $headerValue === '') {
                break;
            }

            if ($headerValue === 'TOTAL PEDIDOS') {
                $totalPedidosCol = $col;
            }
            if ($headerValue === 'Código de Producto') {
                $productCodeCol = $col;
            }
            // Match company columns by fantasy_name pattern (Company 1, Company 2, etc.)
            if (preg_match('/^COMPANY (\d+)$/i', $headerValue, $matches)) {
                $companyNumber = (int)$matches[1];
                $companyColumns[$companyNumber] = $col;
            }

            $col++;
        }

        $this->assertNotNull($totalPedidosCol, 'TOTAL PEDIDOS column not found');
        $this->assertNotNull($productCodeCol, 'Product code column not found');
        $this->assertCount(10, $companyColumns, 'Should have 10 company columns');

        // Find Product A row
        $productARow = null;
        for ($rowIndex = $headerRow + 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
            $productCode = $sheet->getCell($productCodeCol . $rowIndex)->getValue();
            if ($productCode === 'PRODUCT_A') {
                $productARow = $rowIndex;
                break;
            }
        }

        $this->assertNotNull($productARow, 'Product A row not found');

        // Validate TOTAL PEDIDOS
        $actualTotalPedidos = $sheet->getCell($totalPedidosCol . $productARow)->getValue();
        $this->assertEquals(
            $expectedTotalPedidos,
            $actualTotalPedidos,
            "TOTAL PEDIDOS should be {$expectedTotalPedidos}, but got {$actualTotalPedidos}"
        );

        // Validate each company column has correct value
        // The companies are ordered by company_id in the report (see DiscriminatedCompanyColumnProvider display_order)
        for ($i = 1; $i <= 10; $i++) {
            $this->assertArrayHasKey($i, $companyColumns, "Company {$i} column not found in report");

            $actualValue = $sheet->getCell($companyColumns[$i] . $productARow)->getValue();
            $expectedValue = $companyQuantities[$i - 1];

            $this->assertEquals(
                $expectedValue,
                $actualValue,
                "Company {$i} should have {$expectedValue} units, but got {$actualValue}"
            );
        }

        // Validate production values (single OP, all PROCESSED orders)
        $expectedProductionValues = [
            'PRODUCT_A' => [
                'adelanto_inicial' => 0,           // No advances
                'elaborar_1' => $expectedTotalPedidos, // All orders in single OP
                'total_elaborado' => $expectedTotalPedidos, // Total produced
                'sobrantes' => 0,                  // No leftovers
            ],
        ];

        $this->validateProductionValues($spreadsheet, $expectedProductionValues);
    }

    /**
     * Test: Single OP with 10 groupers using ReportGrouperColumnProvider
     *
     * VALIDATION:
     * - Creates exactly 10 report groupers with 1 company each
     * - Uses ReportGrouperColumnProvider service (new implementation)
     * - Validates grouper names in headers (not "Company 1, Company 2")
     * - Validates quantities per grouper match assigned company
     * - Validates production values: TOTAL PEDIDOS, ELABORAR, TOTAL ELABORADO, SOBRANTES
     *
     * STRUCTURE:
     * - 10 companies (NOT excluded from consolidated)
     * - 10 report groupers (GROUPER 1, GROUPER 2, ..., GROUPER 10)
     * - Each grouper associated with exactly 1 company
     * - 10 PROCESSED orders with quantities: [5, 10, 15, 20, 25, 30, 35, 40, 45, 50]
     * - Single OP containing all 10 orders
     */
    public function test_single_op_with_ten_groupers(): void
    {
        // Define quantities for each company (different to ensure correct mapping)
        $companyQuantities = [5, 10, 15, 20, 25, 30, 35, 40, 45, 50];
        $expectedTotalPedidos = array_sum($companyQuantities); // 275

        $companies = [];
        $users = [];
        $orders = [];

        // Create 10 companies, users, and orders
        for ($i = 1; $i <= 10; $i++) {
            // Create company (NOT excluded, will use groupers instead)
            $company = Company::create([
                'name' => "Test Grouper Company {$i} S.A.",
                'fantasy_name' => "Grouper Company {$i}",
                'tax_id' => str_pad($i + 100, 8, '0', STR_PAD_LEFT) . "-{$i}",
                'company_code' => "GCOMP{$i}",
                'email' => "gcompany{$i}@test.com",
                'phone_number' => str_repeat((string)$i, 9),
                'address' => "Grouper Address {$i}",
                'price_list_id' => $this->company->price_list_id,
                'exclude_from_consolidated_report' => false, // Do NOT exclude (use groupers instead)
            ]);

            $branch = Branch::create([
                'company_id' => $company->id,
                'address' => "Grouper Branch {$i} Address",
                'fantasy_name' => "Grouper Branch {$i}",
                'branch_code' => "GBR{$i}",
                'phone' => str_repeat((string)$i, 9),
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'name' => "Grouper User {$i}",
                'email' => "guser{$i}@test.com",
                'nickname' => "guser{$i}",
                'password' => bcrypt('password'),
                'company_id' => $company->id,
                'branch_id' => $branch->id,
            ]);

            // Create PROCESSED order with unique quantity
            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $companyQuantities[$i - 1], false);

            $companies[] = $company;
            $users[] = $user;
            $orders[] = $order;
        }

        // Create 10 report groupers with exactly 1 company each
        $groupers = $this->createReportGroupersWithCompanies($companies);
        $this->assertCount(10, $groupers, 'Should have created 10 groupers');

        // Override service binding to use ReportGrouperColumnProvider
        // This simulates what would be configured in AppServiceProvider
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\ReportGrouperColumnProvider::class
        );

        // Create single OP with all 10 orders
        $orderIds = array_map(fn($order) => $order->id, $orders);
        $op1 = $this->createAndExecuteOp($orderIds);

        // Generate consolidated report
        $filePath = $this->generateConsolidatedReport(
            [$op1->id],
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true
        );

        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Find header row
        $headerRow = $this->findHeaderRow($spreadsheet);
        $this->assertNotNull($headerRow, 'Header row not found');

        // Find TOTAL PEDIDOS column, product code column, and grouper columns
        $totalPedidosCol = null;
        $productCodeCol = null;
        $grouperColumns = []; // Track all grouper columns by grouper number

        $col = 'A';
        while (true) {
            $headerValue = $sheet->getCell($col . $headerRow)->getValue();

            if ($headerValue === null || $headerValue === '') {
                break;
            }

            if ($headerValue === 'TOTAL PEDIDOS') {
                $totalPedidosCol = $col;
            }
            if ($headerValue === 'Código de Producto') {
                $productCodeCol = $col;
            }
            // Match grouper columns by pattern: "GROUPER 1", "GROUPER 2", etc.
            if (preg_match('/^GROUPER (\d+)$/i', $headerValue, $matches)) {
                $grouperNumber = (int)$matches[1];
                $grouperColumns[$grouperNumber] = $col;
            }

            $col++;
        }

        $this->assertNotNull($totalPedidosCol, 'TOTAL PEDIDOS column not found');
        $this->assertNotNull($productCodeCol, 'Product code column not found');
        $this->assertCount(10, $grouperColumns, 'Should have 10 grouper columns');

        // Validate grouper columns are in correct order (display_order)
        for ($i = 1; $i <= 10; $i++) {
            $this->assertArrayHasKey($i, $grouperColumns, "GROUPER {$i} column not found in report");
        }

        // Find Product A row
        $productARow = null;
        for ($rowIndex = $headerRow + 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
            $productCode = $sheet->getCell($productCodeCol . $rowIndex)->getValue();
            if ($productCode === 'PRODUCT_A') {
                $productARow = $rowIndex;
                break;
            }
        }

        $this->assertNotNull($productARow, 'Product A row not found');

        // Validate TOTAL PEDIDOS
        $actualTotalPedidos = $sheet->getCell($totalPedidosCol . $productARow)->getValue();
        $this->assertEquals(
            $expectedTotalPedidos,
            $actualTotalPedidos,
            "TOTAL PEDIDOS should be {$expectedTotalPedidos}, but got {$actualTotalPedidos}"
        );

        // Validate each grouper column has correct value
        // Each grouper is associated with exactly 1 company in order: GROUPER 1 -> Company 1, GROUPER 2 -> Company 2, etc.
        for ($i = 1; $i <= 10; $i++) {
            $this->assertArrayHasKey($i, $grouperColumns, "GROUPER {$i} column not found in report");

            $actualValue = $sheet->getCell($grouperColumns[$i] . $productARow)->getValue();
            $expectedValue = $companyQuantities[$i - 1];

            $this->assertEquals(
                $expectedValue,
                $actualValue,
                "GROUPER {$i} should have {$expectedValue} units, but got {$actualValue}"
            );
        }

        // Validate production values (single OP, all PROCESSED orders)
        $expectedProductionValues = [
            'PRODUCT_A' => [
                'adelanto_inicial' => 0,           // No advances
                'elaborar_1' => $expectedTotalPedidos, // All orders in single OP
                'total_elaborado' => $expectedTotalPedidos, // Total produced
                'sobrantes' => 0,                  // No leftovers
            ],
        ];

        $this->validateProductionValues($spreadsheet, $expectedProductionValues);
    }

    /**
     * Test: Single OP with 3 groupers with distributed company assignments
     *
     * VALIDATION:
     * - Creates exactly 3 report groupers with multiple companies each:
     *   - GROUPER 1: 3 companies (companies 1-3) with quantities [5, 10, 15] = 30 total
     *   - GROUPER 2: 5 companies (companies 4-8) with quantities [20, 25, 30, 35, 40] = 150 total
     *   - GROUPER 3: 2 companies (companies 9-10) with quantities [45, 50] = 95 total
     * - Uses ReportGrouperColumnProvider service (new implementation)
     * - Validates grouper names in headers
     * - Validates quantities per grouper match sum of assigned companies
     * - Validates production values: TOTAL PEDIDOS, ELABORAR, TOTAL ELABORADO, SOBRANTES
     *
     * STRUCTURE:
     * - 10 companies (NOT excluded from consolidated)
     * - 3 report groupers with uneven distribution
     * - 10 PROCESSED orders with quantities: [5, 10, 15, 20, 25, 30, 35, 40, 45, 50]
     * - Single OP containing all 10 orders
     */
    public function test_single_op_with_three_groupers_distributed_companies(): void
    {
        // Define quantities for each company (different to ensure correct mapping)
        $companyQuantities = [5, 10, 15, 20, 25, 30, 35, 40, 45, 50];
        $expectedTotalPedidos = array_sum($companyQuantities); // 275

        $companies = [];
        $users = [];
        $orders = [];

        // Create 10 companies, users, and orders
        for ($i = 1; $i <= 10; $i++) {
            // Create company (NOT excluded, will use groupers instead)
            $company = Company::create([
                'name' => "Test Distributed Company {$i} S.A.",
                'fantasy_name' => "Distributed Company {$i}",
                'tax_id' => str_pad($i + 200, 8, '0', STR_PAD_LEFT) . "-{$i}",
                'company_code' => "DCOMP{$i}",
                'email' => "dcompany{$i}@test.com",
                'phone_number' => str_repeat((string)$i, 9),
                'address' => "Distributed Address {$i}",
                'price_list_id' => $this->company->price_list_id,
                'exclude_from_consolidated_report' => false, // Do NOT exclude (use groupers instead)
            ]);

            $branch = Branch::create([
                'company_id' => $company->id,
                'address' => "Distributed Branch {$i} Address",
                'fantasy_name' => "Distributed Branch {$i}",
                'branch_code' => "DBR{$i}",
                'phone' => str_repeat((string)$i, 9),
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'name' => "Distributed User {$i}",
                'email' => "duser{$i}@test.com",
                'nickname' => "duser{$i}",
                'password' => bcrypt('password'),
                'company_id' => $company->id,
                'branch_id' => $branch->id,
            ]);

            // Create PROCESSED order with unique quantity
            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $companyQuantities[$i - 1], false);

            $companies[] = $company;
            $users[] = $user;
            $orders[] = $order;
        }

        // Create report configuration FIRST
        $reportConfig = \App\Models\ReportConfiguration::create([
            'name' => 'test_groupers_basic_config',
            'description' => 'Test configuration for groupers',
            'use_groupers' => true,
            'exclude_cafeterias' => false,
            'exclude_agreements' => false,
            'is_active' => true,
        ]);

        // Create 3 report groupers with distributed company assignments
        // GROUPER 1: Companies 1-3 (indices 0-2)
        $grouper1 = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER 1 - FIRST THREE',
            'code' => 'GRP1_3COMP',
            'display_order' => 1,
            'is_active' => true,
        ]);
        $grouper1->companies()->attach([$companies[0]->id, $companies[1]->id, $companies[2]->id]);

        // GROUPER 2: Companies 4-8 (indices 3-7)
        $grouper2 = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER 2 - MIDDLE FIVE',
            'code' => 'GRP2_5COMP',
            'display_order' => 2,
            'is_active' => true,
        ]);
        $grouper2->companies()->attach([
            $companies[3]->id,
            $companies[4]->id,
            $companies[5]->id,
            $companies[6]->id,
            $companies[7]->id,
        ]);

        // GROUPER 3: Companies 9-10 (indices 8-9)
        $grouper3 = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER 3 - LAST TWO',
            'code' => 'GRP3_2COMP',
            'display_order' => 3,
            'is_active' => true,
        ]);
        $grouper3->companies()->attach([$companies[8]->id, $companies[9]->id]);

        // Override service binding to use ReportGrouperColumnProvider
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\ReportGrouperColumnProvider::class
        );

        // Create single OP with all 10 orders
        $orderIds = array_map(fn($order) => $order->id, $orders);
        $op1 = $this->createAndExecuteOp($orderIds);

        // Generate consolidated report with custom filename
        $filePath = $this->generateConsolidatedReport(
            [$op1->id],
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true,
            customFileName: 'grouper-3-distributed-companies'
        );

        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Find header row
        $headerRow = $this->findHeaderRow($spreadsheet);
        $this->assertNotNull($headerRow, 'Header row not found');

        // Find TOTAL PEDIDOS column, product code column, and grouper columns
        $totalPedidosCol = null;
        $productCodeCol = null;
        $grouperColumns = []; // Track grouper columns by name

        $col = 'A';
        while (true) {
            $headerValue = $sheet->getCell($col . $headerRow)->getValue();

            if ($headerValue === null || $headerValue === '') {
                break;
            }

            if ($headerValue === 'TOTAL PEDIDOS') {
                $totalPedidosCol = $col;
            }
            if ($headerValue === 'Código de Producto') {
                $productCodeCol = $col;
            }
            // Match grouper columns by name
            if (str_contains($headerValue, 'GROUPER 1 - FIRST THREE')) {
                $grouperColumns['grouper1'] = $col;
            }
            if (str_contains($headerValue, 'GROUPER 2 - MIDDLE FIVE')) {
                $grouperColumns['grouper2'] = $col;
            }
            if (str_contains($headerValue, 'GROUPER 3 - LAST TWO')) {
                $grouperColumns['grouper3'] = $col;
            }

            $col++;
        }

        $this->assertNotNull($totalPedidosCol, 'TOTAL PEDIDOS column not found');
        $this->assertNotNull($productCodeCol, 'Product code column not found');
        $this->assertCount(3, $grouperColumns, 'Should have 3 grouper columns');

        // Validate all grouper columns exist
        $this->assertArrayHasKey('grouper1', $grouperColumns, 'GROUPER 1 - FIRST THREE column not found');
        $this->assertArrayHasKey('grouper2', $grouperColumns, 'GROUPER 2 - MIDDLE FIVE column not found');
        $this->assertArrayHasKey('grouper3', $grouperColumns, 'GROUPER 3 - LAST TWO column not found');

        // Find Product A row
        $productARow = null;
        for ($rowIndex = $headerRow + 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
            $productCode = $sheet->getCell($productCodeCol . $rowIndex)->getValue();
            if ($productCode === 'PRODUCT_A') {
                $productARow = $rowIndex;
                break;
            }
        }

        $this->assertNotNull($productARow, 'Product A row not found');

        // Validate TOTAL PEDIDOS
        $actualTotalPedidos = $sheet->getCell($totalPedidosCol . $productARow)->getValue();
        $this->assertEquals(
            $expectedTotalPedidos,
            $actualTotalPedidos,
            "TOTAL PEDIDOS should be {$expectedTotalPedidos}, but got {$actualTotalPedidos}"
        );

        // Validate each grouper column has correct sum of assigned companies
        // GROUPER 1: Companies 1-3 with quantities [5, 10, 15] = 30
        $expectedGrouper1 = 5 + 10 + 15; // 30
        $actualGrouper1 = $sheet->getCell($grouperColumns['grouper1'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedGrouper1,
            $actualGrouper1,
            "GROUPER 1 - FIRST THREE should have {$expectedGrouper1} units (5+10+15), but got {$actualGrouper1}"
        );

        // GROUPER 2: Companies 4-8 with quantities [20, 25, 30, 35, 40] = 150
        $expectedGrouper2 = 20 + 25 + 30 + 35 + 40; // 150
        $actualGrouper2 = $sheet->getCell($grouperColumns['grouper2'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedGrouper2,
            $actualGrouper2,
            "GROUPER 2 - MIDDLE FIVE should have {$expectedGrouper2} units (20+25+30+35+40), but got {$actualGrouper2}"
        );

        // GROUPER 3: Companies 9-10 with quantities [45, 50] = 95
        $expectedGrouper3 = 45 + 50; // 95
        $actualGrouper3 = $sheet->getCell($grouperColumns['grouper3'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedGrouper3,
            $actualGrouper3,
            "GROUPER 3 - LAST TWO should have {$expectedGrouper3} units (45+50), but got {$actualGrouper3}"
        );

        // Validate production values (single OP, all PROCESSED orders)
        $expectedProductionValues = [
            'PRODUCT_A' => [
                'adelanto_inicial' => 0,           // No advances
                'elaborar_1' => $expectedTotalPedidos, // All orders in single OP
                'total_elaborado' => $expectedTotalPedidos, // Total produced
                'sobrantes' => 0,                  // No leftovers
            ],
        ];

        $this->validateProductionValues($spreadsheet, $expectedProductionValues);
    }

    /**
     * Test: TDD - Groupers with exclude_cafeterias and exclude_agreements configuration
     *
     * PHASE: RED (TDD) - This test MUST fail initially
     *
     * VALIDATION:
     * - 20 companies with 20 users:
     *   - 10 Café + Consolidado users (companies 1-10)
     *   - 5 Convenio + Consolidado users (companies 11-15)
     *   - 5 Convenio + Individual users (companies 16-20)
     * - 3 report groupers:
     *   - GROUPER CAFE: 3 Café companies (1-3) with quantities [5, 10, 15] = 30 total
     *   - GROUPER CONVENIO CONSOLIDADO: 5 Convenio Consolidado companies (11-15) with [60, 65, 70, 75, 80] = 350 total
     *   - GROUPER CONVENIO INDIVIDUAL: 5 Convenio Individual companies (16-20) with [85, 90, 95, 100, 105] = 475 total
     * - Report configuration: exclude_cafeterias=true, exclude_agreements=true
     * - Expected columns after groupers:
     *   - CAFETERIAS: Sum of Café companies NOT in groupers (4-10) = [20, 25, 30, 35, 40, 45, 50] = 245
     *   - CONVENIOS: Sum of Convenio companies NOT in groupers (none in this case) = 0
     *
     * STRUCTURE:
     * - 20 PROCESSED orders with quantities: [5, 10, 15, ..., 100, 105]
     * - Single OP containing all 20 orders
     * - Custom filename for visual inspection
     */
    public function test_tdd_groupers_with_exclude_cafeterias_and_agreements(): void
    {
        // Override service binding for this test to use ReportGrouperColumnProvider
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\ReportGrouperColumnProvider::class
        );

        // Define quantities for each company (incremental 5, 10, 15, ..., 100, 105)
        $companyQuantities = [];
        for ($i = 1; $i <= 20; $i++) {
            $companyQuantities[] = $i * 5;
        }
        $expectedTotalPedidos = array_sum($companyQuantities); // 1050

        // Create roles and permissions
        $roleCafe = \App\Models\Role::firstOrCreate(['name' => \App\Enums\RoleName::CAFE->value]);
        $roleConvenio = \App\Models\Role::firstOrCreate(['name' => \App\Enums\RoleName::AGREEMENT->value]);
        $permissionConsolidado = \App\Models\Permission::firstOrCreate(['name' => \App\Enums\PermissionName::CONSOLIDADO->value]);
        $permissionIndividual = \App\Models\Permission::firstOrCreate(['name' => \App\Enums\PermissionName::INDIVIDUAL->value]);

        $companies = [];
        $users = [];
        $orders = [];

        // Create 20 companies with specific roles and permissions
        for ($i = 1; $i <= 20; $i++) {
            // Determine role and permission
            if ($i <= 10) {
                // Companies 1-10: Café + Consolidado
                $role = $roleCafe;
                $permission = $permissionConsolidado;
                $typeLabel = 'CAFE-CONSOLIDADO';
            } elseif ($i <= 15) {
                // Companies 11-15: Convenio + Consolidado
                $role = $roleConvenio;
                $permission = $permissionConsolidado;
                $typeLabel = 'CONVENIO-CONSOLIDADO';
            } else {
                // Companies 16-20: Convenio + Individual
                $role = $roleConvenio;
                $permission = $permissionIndividual;
                $typeLabel = 'CONVENIO-INDIVIDUAL';
            }

            $company = Company::create([
                'name' => "Test Exclude Company {$i} S.A.",
                'fantasy_name' => "Exclude Company {$i} - {$typeLabel}",
                'tax_id' => str_pad($i + 300, 8, '0', STR_PAD_LEFT) . "-{$i}",
                'company_code' => "ECOMP{$i}",
                'email' => "ecompany{$i}@test.com",
                'phone_number' => str_repeat((string)($i % 10), 9),
                'address' => "Exclude Address {$i}",
                'price_list_id' => $this->company->price_list_id,
                'exclude_from_consolidated_report' => false,
            ]);

            $branch = Branch::create([
                'company_id' => $company->id,
                'address' => "Exclude Branch {$i} Address",
                'fantasy_name' => "Exclude Branch {$i}",
                'branch_code' => "EBR{$i}",
                'phone' => str_repeat((string)($i % 10), 9),
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'name' => "Exclude User {$i}",
                'email' => "euser{$i}@test.com",
                'nickname' => "euser{$i}",
                'password' => bcrypt('password'),
                'company_id' => $company->id,
                'branch_id' => $branch->id,
            ]);

            // Attach role and permission
            $user->roles()->attach($role->id);
            $user->permissions()->attach($permission->id);

            // Create PROCESSED order with unique quantity
            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $companyQuantities[$i - 1], false);

            $companies[] = $company;
            $users[] = $user;
            $orders[] = $order;
        }

        // Create 3 report groupers with specific company assignments
        // GROUPER 1: 3 Café companies (indices 0-2, companies 1-3)
        $grouperCafe = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $this->reportConfig->id,
            'name' => 'GROUPER CAFE',
            'code' => 'GRP_CAFE',
            'display_order' => 1,
            'is_active' => true,
        ]);
        $grouperCafe->companies()->attach([
            $companies[0]->id,  // Company 1
            $companies[1]->id,  // Company 2
            $companies[2]->id,  // Company 3
        ]);

        // GROUPER 2: 5 Convenio Consolidado companies (indices 10-14, companies 11-15)
        $grouperConvenioConsolidado = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $this->reportConfig->id,
            'name' => 'GROUPER CONVENIO CONSOLIDADO',
            'code' => 'GRP_CONV_CONS',
            'display_order' => 2,
            'is_active' => true,
        ]);
        $grouperConvenioConsolidado->companies()->attach([
            $companies[10]->id, // Company 11
            $companies[11]->id, // Company 12
            $companies[12]->id, // Company 13
            $companies[13]->id, // Company 14
            $companies[14]->id, // Company 15
        ]);

        // GROUPER 3: 5 Convenio Individual companies (indices 15-19, companies 16-20)
        $grouperConvenioIndividual = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $this->reportConfig->id,
            'name' => 'GROUPER CONVENIO INDIVIDUAL',
            'code' => 'GRP_CONV_IND',
            'display_order' => 3,
            'is_active' => true,
        ]);
        $grouperConvenioIndividual->companies()->attach([
            $companies[15]->id, // Company 16
            $companies[16]->id, // Company 17
            $companies[17]->id, // Company 18
            $companies[18]->id, // Company 19
            $companies[19]->id, // Company 20
        ]);

        // Create report configuration with exclude flags
        $reportConfig = \App\Models\ReportConfiguration::create([
            'name' => 'test_exclude_config',
            'description' => 'Test configuration with cafeterias and agreements exclusions',
            'use_groupers' => true,
            'exclude_cafeterias' => true,
            'exclude_agreements' => true,
            'is_active' => true,
        ]);

        // Override service binding to use ReportGrouperColumnProvider
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\ReportGrouperColumnProvider::class
        );

        // Create single OP with all 20 orders
        $orderIds = array_map(fn($order) => $order->id, $orders);
        $op1 = $this->createAndExecuteOp($orderIds);

        // Generate consolidated report with custom filename
        $filePath = $this->generateConsolidatedReport(
            [$op1->id],
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true,
            customFileName: 'tdd-groupers-exclude-cafeterias-agreements'
        );

        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Find header row
        $headerRow = $this->findHeaderRow($spreadsheet);
        $this->assertNotNull($headerRow, 'Header row not found');

        // Find columns
        $totalPedidosCol = null;
        $productCodeCol = null;
        $grouperColumns = [];
        $cafeteriasCol = null;
        $conveniosCol = null;

        $col = 'A';
        while (true) {
            $headerValue = $sheet->getCell($col . $headerRow)->getValue();

            if ($headerValue === null || $headerValue === '') {
                break;
            }

            if ($headerValue === 'TOTAL PEDIDOS') {
                $totalPedidosCol = $col;
            }
            if ($headerValue === 'Código de Producto') {
                $productCodeCol = $col;
            }
            if (str_contains($headerValue, 'GROUPER CAFE')) {
                $grouperColumns['cafe'] = $col;
            }
            if (str_contains($headerValue, 'GROUPER CONVENIO CONSOLIDADO')) {
                $grouperColumns['convenio_consolidado'] = $col;
            }
            if (str_contains($headerValue, 'GROUPER CONVENIO INDIVIDUAL')) {
                $grouperColumns['convenio_individual'] = $col;
            }
            if ($headerValue === 'CAFETERIAS') {
                $cafeteriasCol = $col;
            }
            if ($headerValue === 'CONVENIOS') {
                $conveniosCol = $col;
            }

            $col++;
        }

        // Validate columns exist
        $this->assertNotNull($totalPedidosCol, 'TOTAL PEDIDOS column not found');
        $this->assertNotNull($productCodeCol, 'Product code column not found');
        $this->assertCount(3, $grouperColumns, 'Should have 3 grouper columns');
        $this->assertNotNull($cafeteriasCol, 'CAFETERIAS column not found (TDD: should fail here)');
        $this->assertNotNull($conveniosCol, 'CONVENIOS column not found (TDD: should fail here)');

        // Find Product A row
        $productARow = null;
        for ($rowIndex = $headerRow + 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
            $productCode = $sheet->getCell($productCodeCol . $rowIndex)->getValue();
            if ($productCode === 'PRODUCT_A') {
                $productARow = $rowIndex;
                break;
            }
        }

        $this->assertNotNull($productARow, 'Product A row not found');

        // Validate TOTAL PEDIDOS
        $actualTotalPedidos = $sheet->getCell($totalPedidosCol . $productARow)->getValue();
        $this->assertEquals(
            $expectedTotalPedidos,
            $actualTotalPedidos,
            "TOTAL PEDIDOS should be {$expectedTotalPedidos}, but got {$actualTotalPedidos}"
        );

        // Validate grouper columns
        // GROUPER CAFE: Companies 1-3 with quantities [5, 10, 15] = 30
        $expectedGrouperCafe = 5 + 10 + 15; // 30
        $actualGrouperCafe = $sheet->getCell($grouperColumns['cafe'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedGrouperCafe,
            $actualGrouperCafe,
            "GROUPER CAFE should have {$expectedGrouperCafe} units (5+10+15), but got {$actualGrouperCafe}"
        );

        // GROUPER CONVENIO CONSOLIDADO: Companies 11-15 with [55, 60, 65, 70, 75] = 325
        $expectedGrouperConvenioConsolidado = 55 + 60 + 65 + 70 + 75; // 325
        $actualGrouperConvenioConsolidado = $sheet->getCell($grouperColumns['convenio_consolidado'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedGrouperConvenioConsolidado,
            $actualGrouperConvenioConsolidado,
            "GROUPER CONVENIO CONSOLIDADO should have {$expectedGrouperConvenioConsolidado} units, but got {$actualGrouperConvenioConsolidado}"
        );

        // GROUPER CONVENIO INDIVIDUAL: Companies 16-20 with [80, 85, 90, 95, 100] = 450
        $expectedGrouperConvenioIndividual = 80 + 85 + 90 + 95 + 100; // 450
        $actualGrouperConvenioIndividual = $sheet->getCell($grouperColumns['convenio_individual'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedGrouperConvenioIndividual,
            $actualGrouperConvenioIndividual,
            "GROUPER CONVENIO INDIVIDUAL should have {$expectedGrouperConvenioIndividual} units, but got {$actualGrouperConvenioIndividual}"
        );

        // Validate CAFETERIAS column (Café companies NOT in groupers: 4-10)
        // Companies 4-10 have quantities: [20, 25, 30, 35, 40, 45, 50] = 245
        $expectedCafeterias = 20 + 25 + 30 + 35 + 40 + 45 + 50; // 245
        $actualCafeterias = $sheet->getCell($cafeteriasCol . $productARow)->getValue();
        $this->assertEquals(
            $expectedCafeterias,
            $actualCafeterias,
            "CAFETERIAS should have {$expectedCafeterias} units (companies 4-10 not in groupers), but got {$actualCafeterias}"
        );

        // Validate CONVENIOS column (Convenio companies NOT in groupers)
        // All Convenio companies (11-20) are in groupers, so CONVENIOS = 0
        $expectedConvenios = 0;
        $actualConvenios = $sheet->getCell($conveniosCol . $productARow)->getValue();
        $this->assertEquals(
            $expectedConvenios,
            $actualConvenios,
            "CONVENIOS should have {$expectedConvenios} units (all in groupers), but got {$actualConvenios}"
        );

        // Validate production values
        $expectedProductionValues = [
            'PRODUCT_A' => [
                'adelanto_inicial' => 0,
                'elaborar_1' => $expectedTotalPedidos,
                'total_elaborado' => $expectedTotalPedidos,
                'sobrantes' => 0,
            ],
        ];

        $this->validateProductionValues($spreadsheet, $expectedProductionValues);

        // Cleanup config
        $reportConfig->delete();
    }

    /**
     * Test: Complex multi-company scenario with ADELANTO, ELABORAR, TOTAL ELABORADO and SOBRANTES validation
     *
     * This test creates a complex scenario with:
     * - 3 companies with different branches
     * - 3 users (one per company)
     * - Multiple orders (PROCESSED and PARTIALLY_SCHEDULED) before each OP creation
     * - 4 OPs with different product combinations
     * - Validates: ADELANTO, ELABORAR, TOTAL ELABORADO, SOBRANTES columns
     */
    public function test_complex_multi_company_consolidated_report_calculations(): void
    {
        // Use legacy DiscriminatedCompanyColumnProvider for this test
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\DiscriminatedCompanyColumnProvider::class
        );

        // Create additional companies, branches, and users
        // Company 2: Will be shown discriminated in report
        // NOTE: Despite the confusing field name, exclude_from_consolidated_report = true
        // means the company WILL be shown as a discriminated column (see AdvanceOrderRepository.php:412)
        $company2 = Company::create([
            'name' => 'Test Company 2 S.A.',
            'fantasy_name' => 'Company 2',
            'tax_id' => '22222222-2',
            'company_code' => 'COMP2',
            'email' => 'company2@test.com',
            'phone_number' => '222222222',
            'address' => 'Address 2',
            'price_list_id' => $this->company->price_list_id,
            'exclude_from_consolidated_report' => true, // Show discriminated (counter-intuitive but correct)
        ]);

        $branch2 = Branch::create([
            'company_id' => $company2->id,
            'address' => 'Branch 2 Address',
            'fantasy_name' => 'Branch 2',
            'branch_code' => 'BR2',
            'phone' => '222222222',
            'min_price_order' => 0,
        ]);

        $user2 = User::create([
            'name' => 'User 2',
            'email' => 'user2@test.com',
            'nickname' => 'user2',
            'password' => bcrypt('password'),
            'company_id' => $company2->id,
            'branch_id' => $branch2->id,
        ]);

        // Company 3: Will also be shown discriminated in report
        $company3 = Company::create([
            'name' => 'Test Company 3 S.A.',
            'fantasy_name' => 'Company 3',
            'tax_id' => '33333333-3',
            'company_code' => 'COMP3',
            'email' => 'company3@test.com',
            'phone_number' => '333333333',
            'address' => 'Address 3',
            'price_list_id' => $this->company->price_list_id,
            'exclude_from_consolidated_report' => true, // Show discriminated (counter-intuitive but correct)
        ]);

        $branch3 = Branch::create([
            'company_id' => $company3->id,
            'address' => 'Branch 3 Address',
            'fantasy_name' => 'Branch 3',
            'branch_code' => 'BR3',
            'phone' => '333333333',
            'min_price_order' => 0,
        ]);

        $user3 = User::create([
            'name' => 'User 3',
            'email' => 'user3@test.com',
            'nickname' => 'user3',
            'password' => bcrypt('password'),
            'company_id' => $company3->id,
            'branch_id' => $branch3->id,
        ]);

        // ===== REPLICATING PRODUCTION SCENARIO (OPs 26, 27) =====
        // STEP 1: Create Order #1 (Company 2 - ALMA) - PROCESSED
        $order1 = $this->createOrderForUser($user2, $this->dateFA, OrderStatus::PROCESSED);
        $this->createOrderLine($order1, $this->productA, 50, false);

        // STEP 2: Create OP #1 with Order #1
        $op1 = $this->createAndExecuteOp([$order1->id]);

        // STEP 3: Create Order #2 (Company 3 - BOUNNA) - PROCESSED
        $order2 = $this->createOrderForUser($user3, $this->dateFA, OrderStatus::PROCESSED);
        $this->createOrderLine($order2, $this->productA, 90, false);

        // STEP 4: Create OP #2 with Order #1 (again) + Order #2
        $op2 = $this->createAndExecuteOp([$order1->id, $order2->id]);

        // ===== GENERATE CONSOLIDATED REPORT =====
        $opIds = [$op1->id, $op2->id];

        $filePath = $this->generateConsolidatedReport(
            $opIds,
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true
        );

        $spreadsheet = $this->loadExcelFile($filePath);

        // ===== VALIDATE REPORT WITH HARDCODED EXPECTED VALUES =====

        // Define expected values matching EXACTLY production scenario (OPs 26, 27)
        $expectedValues = [
            'PRODUCT_A' => [
                'total_pedidos' => 140,  // 50 (Company 2) + 90 (Company 3)
                'company_2' => 50,       // order1: 50 (counted once even though in OP#1 and OP#2)
                'company_3' => 90,       // order2: 90
            ],
        ];

        // Validate order quantities (TOTAL PEDIDOS and company columns)
        $this->validateReportWithHardcodedValues($spreadsheet, $expectedValues);

        // Define expected production values (ADELANTO, ELABORAR, TOTAL ELABORADO, SOBRANTES)
        $expectedProductionValues = [
            'PRODUCT_A' => [
                'adelanto_inicial' => 0,   // OP #1 has no advances
                'elaborar_1' => 50,        // OP #1 elaborates 50 (order1)
                'adelanto_2' => 0,         // OP #2 has no advances
                'elaborar_2' => 90,        // OP #2 elaborates 90 (order2, order1 was already in OP #1)
                'total_elaborado' => 140,  // Total: 50 + 90 = 140
                'sobrantes' => 0,          // No leftovers (140 ordered = 140 produced)
            ],
        ];

        // Validate production values (ADELANTO, ELABORAR, TOTAL ELABORADO, SOBRANTES)
        $this->validateProductionValues($spreadsheet, $expectedProductionValues);

        // Clean up test file
        // $this->cleanupTestFile($filePath);  // Commented to keep the file for inspection
    }

    // ==================================================================
    // MOMENT 1: Create Order A
    // ==================================================================

    private function createMoment1Order(): void
    {
        $this->orderA = $this->createOrder($this->dateFA, OrderStatus::PARTIALLY_SCHEDULED);
        $this->createOrderLine($this->orderA, $this->productA, 15, true);   // partially_scheduled = true
        $this->createOrderLine($this->orderA, $this->productB, 10, false);  // partially_scheduled = false
        $this->createOrderLine($this->orderA, $this->productC, 25, false);  // partially_scheduled = false
    }

    // ==================================================================
    // MOMENT 2: Create OP #1
    // ==================================================================

    private function createOp1(): AdvanceOrder
    {
        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op1 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // Apply advance to Product A
        $this->applyAdvance($op1, $this->productA, 30);

        return $op1;
    }

    private function validateOp1IncludesOnlyProductA(AdvanceOrder $op1): void
    {
        $products = $op1->advanceOrderProducts()->get();

        $this->assertCount(1, $products, 'OP #1: Should have exactly 1 product');

        $productA = $products->first();
        $this->assertEquals($this->productA->id, $productA->product_id, 'OP #1: The only product should be Product A');
    }

    private function validateOp1ExcludesProductsBAndC(AdvanceOrder $op1): void
    {
        $productB = $op1->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertNull($productB, 'OP #1: Product B should NOT be included (partially_scheduled = false)');

        $productC = $op1->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertNull($productC, 'OP #1: Product C should NOT be included (partially_scheduled = false)');
    }

    private function validateOp1Pivots(AdvanceOrder $op1): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op1->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(1, $orderIds, 'OP #1: Should have 1 order in pivot');
        $this->assertContains($this->orderA->id, $orderIds, 'OP #1: Should include Order A');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op1->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(1, $orderLineIds, 'OP #1: Should have 1 order_line in pivot');

        // Only Product A line should be included
        $productALine = $this->orderA->orderLines()->where('product_id', $this->productA->id)->first();
        $this->assertContains($productALine->id, $orderLineIds, 'OP #1: Should include Product A line');

        // Product B and C lines should NOT be included
        $productBLine = $this->orderA->orderLines()->where('product_id', $this->productB->id)->first();
        $this->assertNotContains($productBLine->id, $orderLineIds, 'OP #1: Should NOT include Product B line');

        $productCLine = $this->orderA->orderLines()->where('product_id', $this->productC->id)->first();
        $this->assertNotContains($productCLine->id, $orderLineIds, 'OP #1: Should NOT include Product C line');
    }

    private function validateOp1Calculations(AdvanceOrder $op1): void
    {
        // Product A
        $productA = $op1->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertNotNull($productA, 'OP #1: Product A should exist');
        $this->assertEquals(15, $productA->ordered_quantity, 'OP #1 Product A: ordered_quantity should be 15');
        $this->assertEquals(15, $productA->ordered_quantity_new, 'OP #1 Product A: ordered_quantity_new should be 15');
        $this->assertEquals(30, $productA->quantity, 'OP #1 Product A: quantity (advance) should be 30');
        $this->assertEquals(30, $productA->total_to_produce, 'OP #1 Product A: total_to_produce should be 30');
    }

    // ==================================================================
    // MOMENT 3: Update Order A - Increase Product A quantity
    // ==================================================================

    private function updateMoment3Order(): void
    {
        $lineA = $this->orderA->orderLines()->where('product_id', $this->productA->id)->first();
        $lineA->update(['quantity' => 23]);
    }

    // ==================================================================
    // MOMENT 4: Create OP #2
    // ==================================================================

    private function createOp2(): AdvanceOrder
    {
        Carbon::setTestNow('2025-11-18 12:00:00');

        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op2 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // No advances applied

        return $op2;
    }

    private function validateOp2IncludesOnlyProductA(AdvanceOrder $op2): void
    {
        $products = $op2->advanceOrderProducts()->get();

        $this->assertCount(1, $products, 'OP #2: Should have exactly 1 product');

        $productA = $products->first();
        $this->assertEquals($this->productA->id, $productA->product_id, 'OP #2: The only product should be Product A');
    }

    private function validateOp2ExcludesProductsBAndC(AdvanceOrder $op2): void
    {
        $productB = $op2->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertNull($productB, 'OP #2: Product B should NOT be included (partially_scheduled = false)');

        $productC = $op2->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertNull($productC, 'OP #2: Product C should NOT be included (partially_scheduled = false)');
    }

    private function validateOp2Pivots(AdvanceOrder $op2): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op2->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(1, $orderIds, 'OP #2: Should have 1 order in pivot');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op2->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(1, $orderLineIds, 'OP #2: Should have 1 order_line in pivot (only Product A)');
    }

    private function validateOp2Calculations(AdvanceOrder $op2): void
    {
        // Product A
        $productA = $op2->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertNotNull($productA, 'OP #2: Product A should exist');

        $this->assertEquals(23, $productA->ordered_quantity, 'OP #2 Product A: ordered_quantity should be 23');
        $this->assertEquals(8, $productA->ordered_quantity_new, 'OP #2 Product A: ordered_quantity_new should be 8 (23 - 15)');
        $this->assertEquals(0, $productA->quantity, 'OP #2 Product A: quantity (advance) should be 0');

        // total_to_produce should be 0 because there's sufficient inventory from OP #1
        // OP #1 produced 30 units and consumed 15, leaving 15 units in stock
        // We only need 8 more units, so we don't need to produce anything
        // Formula: MAX(0, ordered_quantity_new - inventory) = MAX(0, 8 - 15) = 0
        $this->assertEquals(0, $productA->total_to_produce, 'OP #2 Product A: total_to_produce should be 0 (sufficient inventory from OP #1)');
    }

    // ==================================================================
    // MOMENT 5: Update Order A - Change Product B to partially_scheduled = true
    // ==================================================================

    private function updateMoment5Order(): void
    {
        $lineB = $this->orderA->orderLines()->where('product_id', $this->productB->id)->first();
        $lineB->update([
            'partially_scheduled' => true,
            'quantity' => 18,
        ]);
    }

    // ==================================================================
    // MOMENT 6: Create OP #3
    // ==================================================================

    private function createOp3(): AdvanceOrder
    {
        Carbon::setTestNow('2025-11-18 14:00:00');

        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op3 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // No advances applied

        return $op3;
    }

    private function validateOp3IncludesProductsAAndB(AdvanceOrder $op3): void
    {
        $products = $op3->advanceOrderProducts()->get();

        $this->assertCount(2, $products, 'OP #3: Should have exactly 2 products');

        $productIds = $products->pluck('product_id')->toArray();
        $this->assertContains($this->productA->id, $productIds, 'OP #3: Should include Product A');
        $this->assertContains($this->productB->id, $productIds, 'OP #3: Should include Product B');
    }

    private function validateOp3ExcludesProductC(AdvanceOrder $op3): void
    {
        $productC = $op3->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertNull($productC, 'OP #3: Product C should NOT be included (partially_scheduled = false)');
    }

    private function validateOp3Pivots(AdvanceOrder $op3): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op3->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(1, $orderIds, 'OP #3: Should have 1 order in pivot');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op3->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(2, $orderLineIds, 'OP #3: Should have 2 order_lines in pivot (Products A and B)');

        // Product A and B lines should be included
        $productALine = $this->orderA->orderLines()->where('product_id', $this->productA->id)->first();
        $this->assertContains($productALine->id, $orderLineIds, 'OP #3: Should include Product A line');

        $productBLine = $this->orderA->orderLines()->where('product_id', $this->productB->id)->first();
        $this->assertContains($productBLine->id, $orderLineIds, 'OP #3: Should include Product B line');

        // Product C line should NOT be included
        $productCLine = $this->orderA->orderLines()->where('product_id', $this->productC->id)->first();
        $this->assertNotContains($productCLine->id, $orderLineIds, 'OP #3: Should NOT include Product C line');
    }

    private function validateOp3Calculations(AdvanceOrder $op3): void
    {
        // Product A
        $productA = $op3->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertNotNull($productA, 'OP #3: Product A should exist');
        $this->assertEquals(23, $productA->ordered_quantity, 'OP #3 Product A: ordered_quantity should be 23');
        $this->assertEquals(0, $productA->ordered_quantity_new, 'OP #3 Product A: ordered_quantity_new should be 0 (already covered)');
        $this->assertEquals(0, $productA->quantity, 'OP #3 Product A: quantity (advance) should be 0');
        $this->assertEquals(0, $productA->total_to_produce, 'OP #3 Product A: total_to_produce should be 0');

        // Product B
        $productB = $op3->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertNotNull($productB, 'OP #3: Product B should exist');
        $this->assertEquals(18, $productB->ordered_quantity, 'OP #3 Product B: ordered_quantity should be 18');
        $this->assertEquals(18, $productB->ordered_quantity_new, 'OP #3 Product B: ordered_quantity_new should be 18 (first time included)');
        $this->assertEquals(0, $productB->quantity, 'OP #3 Product B: quantity (advance) should be 0');
        $this->assertEquals(18, $productB->total_to_produce, 'OP #3 Product B: total_to_produce should be 18');
    }

    // ==================================================================
    // MOMENT 7: Change Order A to PROCESSED
    // ==================================================================

    private function updateMoment7Order(): void
    {
        $this->orderA->update(['status' => OrderStatus::PROCESSED->value]);

        // Also update Product C quantity
        $lineC = $this->orderA->orderLines()->where('product_id', $this->productC->id)->first();
        $lineC->update(['quantity' => 31]);
    }

    // ==================================================================
    // MOMENT 8: Create OP #4
    // ==================================================================

    private function createOp4(): AdvanceOrder
    {
        Carbon::setTestNow('2025-11-18 16:00:00');

        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op4 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$this->orderA->id],
            $preparationDatetime,
            [$this->productionArea->id]
        );

        // No advances applied

        return $op4;
    }

    private function validateOp4IncludesAllProducts(AdvanceOrder $op4): void
    {
        $products = $op4->advanceOrderProducts()->get();

        $this->assertCount(3, $products, 'OP #4: Should have all 3 products (order is PROCESSED)');

        $productIds = $products->pluck('product_id')->toArray();
        $this->assertContains($this->productA->id, $productIds, 'OP #4: Should include Product A');
        $this->assertContains($this->productB->id, $productIds, 'OP #4: Should include Product B');
        $this->assertContains($this->productC->id, $productIds, 'OP #4: Should include Product C');
    }

    private function validateOp4Pivots(AdvanceOrder $op4): void
    {
        // Validate advance_order_orders pivot
        $orderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $op4->id)
            ->pluck('order_id')
            ->toArray();

        $this->assertCount(1, $orderIds, 'OP #4: Should have 1 order in pivot');

        // Validate advance_order_order_lines pivot
        $orderLineIds = DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op4->id)
            ->pluck('order_line_id')
            ->toArray();

        $this->assertCount(3, $orderLineIds, 'OP #4: Should have 3 order_lines in pivot (all products)');

        // All product lines should be included
        $productALine = $this->orderA->orderLines()->where('product_id', $this->productA->id)->first();
        $this->assertContains($productALine->id, $orderLineIds, 'OP #4: Should include Product A line');

        $productBLine = $this->orderA->orderLines()->where('product_id', $this->productB->id)->first();
        $this->assertContains($productBLine->id, $orderLineIds, 'OP #4: Should include Product B line');

        $productCLine = $this->orderA->orderLines()->where('product_id', $this->productC->id)->first();
        $this->assertContains($productCLine->id, $orderLineIds, 'OP #4: Should include Product C line');
    }

    private function validateOp4Calculations(AdvanceOrder $op4): void
    {
        // Product A
        $productA = $op4->advanceOrderProducts()->where('product_id', $this->productA->id)->first();
        $this->assertNotNull($productA, 'OP #4: Product A should exist');
        $this->assertEquals(23, $productA->ordered_quantity, 'OP #4 Product A: ordered_quantity should be 23');
        $this->assertEquals(0, $productA->ordered_quantity_new, 'OP #4 Product A: ordered_quantity_new should be 0');
        $this->assertEquals(0, $productA->quantity, 'OP #4 Product A: quantity (advance) should be 0');
        $this->assertEquals(0, $productA->total_to_produce, 'OP #4 Product A: total_to_produce should be 0');

        // Product B
        $productB = $op4->advanceOrderProducts()->where('product_id', $this->productB->id)->first();
        $this->assertNotNull($productB, 'OP #4: Product B should exist');
        $this->assertEquals(18, $productB->ordered_quantity, 'OP #4 Product B: ordered_quantity should be 18');
        $this->assertEquals(0, $productB->ordered_quantity_new, 'OP #4 Product B: ordered_quantity_new should be 0');
        $this->assertEquals(0, $productB->quantity, 'OP #4 Product B: quantity (advance) should be 0');
        $this->assertEquals(0, $productB->total_to_produce, 'OP #4 Product B: total_to_produce should be 0');

        // Product C
        $productC = $op4->advanceOrderProducts()->where('product_id', $this->productC->id)->first();
        $this->assertNotNull($productC, 'OP #4: Product C should exist');
        $this->assertEquals(31, $productC->ordered_quantity, 'OP #4 Product C: ordered_quantity should be 31');
        $this->assertEquals(31, $productC->ordered_quantity_new, 'OP #4 Product C: ordered_quantity_new should be 31 (first time included)');
        $this->assertEquals(0, $productC->quantity, 'OP #4 Product C: quantity (advance) should be 0');
        $this->assertEquals(31, $productC->total_to_produce, 'OP #4 Product C: total_to_produce should be 31');
    }

    // ==================================================================
    // HELPER METHODS
    // ==================================================================

    private function createTestEnvironment(): void
    {
        // Create production area
        $this->productionArea = ProductionArea::create([
            'name' => 'Test Production Area',
            'description' => 'Production area for testing',
        ]);

        // Create category
        $this->category = Category::create([
            'name' => 'Test Category',
            'description' => 'Category for testing',
        ]);

        // Create products
        $this->productA = $this->createProduct('Product A');
        $this->productB = $this->createProduct('Product B');
        $this->productC = $this->createProduct('Product C');

        // Create user
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
            'exclude_from_consolidated_report' => false, // Consolidate (don't show as separate column)
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

        // Use default warehouse
        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();

        // Associate products with warehouse
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

    private function createOrder(Carbon $dispatchDate, OrderStatus $status): Order
    {
        return Order::create([
            'user_id' => $this->user->id,
            'dispatch_date' => $dispatchDate->toDateString(),
            'date' => $dispatchDate->toDateString(),
            'status' => $status->value,
            'total' => 10000,
            'total_with_tax' => 11900,
            'tax_amount' => 1900,
            'grand_total' => 11900,
            'dispatch_cost' => 0,
        ]);
    }

    private function createOrderLine(
        Order $order,
        Product $product,
        int $quantity,
        bool $partiallyScheduled = false
    ): OrderLine {
        return OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 1000,
            'subtotal' => $quantity * 1000,
            'partially_scheduled' => $partiallyScheduled,
        ]);
    }

    private function createOrderForUser(
        User $user,
        Carbon $dispatchDate,
        OrderStatus $status
    ): Order {
        return Order::create([
            'user_id' => $user->id,
            'date' => $dispatchDate->toDateString(),
            'dispatch_date' => $dispatchDate->toDateString(),
            'status' => $status->value,
            'total' => 10000,
            'total_with_tax' => 11900,
            'tax_amount' => 1900,
            'grand_total' => 11900,
            'dispatch_cost' => 0,
        ]);
    }

    private function createAndExecuteOp(array $orderIds): AdvanceOrder
    {
        $preparationDatetime = $this->dateFA->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op = $this->orderRepository->createAdvanceOrderFromOrders(
            $orderIds,
            $preparationDatetime,
            [$this->productionArea->id]
        );

        $this->executeOp($op);

        return $op;
    }

    private function applyAdvance(AdvanceOrder $op, Product $product, int $advanceQuantity): void
    {
        $advanceOrderProduct = $op->advanceOrderProducts()
            ->where('product_id', $product->id)
            ->first();

        if ($advanceOrderProduct) {
            $advanceOrderProduct->quantity = $advanceQuantity;
            $advanceOrderProduct->save();
            $advanceOrderProduct->refresh();
        }
    }

    /**
     * Run the complete partially scheduled order flow scenario
     *
     * This encapsulates the entire 8-moment flow:
     * - MOMENT 1: Create Order A (PARTIALLY_SCHEDULED) with 3 products
     * - MOMENT 2: Create OP #1 (only Product A has partially_scheduled = true)
     * - MOMENT 3: Update Order A - Product A quantity increases
     * - MOMENT 4: Create OP #2 (still only Product A)
     * - MOMENT 5: Update Order A - Product B becomes partially_scheduled = true
     * - MOMENT 6: Create OP #3 (Products A and B)
     * - MOMENT 7: Update Order A - Change to PROCESSED
     * - MOMENT 8: Create OP #4 (all products included)
     *
     * @return array Array of created and executed OPs [$op1, $op2, $op3, $op4]
     */
    protected function runPartiallyScheduledOrderScenario(): array
    {
        // MOMENT 1: Create Order A (PARTIALLY_SCHEDULED)
        $this->createMoment1Order();

        // MOMENT 2: Create OP #1 (only Product A has partially_scheduled = true)
        $op1 = $this->createOp1();
        $this->executeOp($op1);

        // MOMENT 3: Update Order A - Product A quantity increases
        $this->updateMoment3Order();

        // MOMENT 4: Create OP #2 (still only Product A has partially_scheduled = true)
        $op2 = $this->createOp2();
        $this->executeOp($op2);

        // MOMENT 5: Update Order A - Product B becomes partially_scheduled = true
        $this->updateMoment5Order();

        // MOMENT 6: Create OP #3 (Products A and B have partially_scheduled = true)
        $op3 = $this->createOp3();
        $this->executeOp($op3);

        // MOMENT 7: Update Order A - Change to PROCESSED
        $this->updateMoment7Order();

        // MOMENT 8: Create OP #4 (all products included because order is PROCESSED)
        $op4 = $this->createOp4();
        $this->executeOp($op4);

        return [$op1, $op2, $op3, $op4];
    }

    private function executeOp(AdvanceOrder $op): void
    {
        $this->actingAs($this->user);
        $op->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op));

        // Execute the job to mark orders as needing update
        // In tests, jobs run synchronously so we need to manually process them
        \Illuminate\Support\Facades\Queue::fake();

        // Trigger the observers which will dispatch the job
        // The job marks orders with production_status_needs_update = true
        $relatedOrderIds = \DB::table('advance_order_orders')
            ->where('advance_order_id', $op->id)
            ->pluck('order_id')
            ->toArray();

        if (!empty($relatedOrderIds)) {
            \DB::table('orders')
                ->whereIn('id', $relatedOrderIds)
                ->update(['production_status_needs_update' => true]);
        }

        // Execute the command to update production status
        // This mimics the real-world scenario where the command runs periodically
        $this->artisan('orders:update-production-status');
    }

    /**
     * Create report groupers and associate them with companies
     *
     * Creates exactly 10 report groupers with display_order 1-10,
     * and associates exactly one company to each grouper.
     *
     * @param array $companies Array of exactly 10 Company instances
     * @return array Array of created ReportGrouper instances
     */
    private function createReportGroupersWithCompanies(array $companies): array
    {
        $groupers = [];

        for ($i = 1; $i <= 10; $i++) {
            $grouper = \App\Models\ReportGrouper::create([
                'report_configuration_id' => $this->reportConfig->id,
                'name' => "GROUPER {$i}",
                'code' => "GRP{$i}",
                'display_order' => $i,
                'is_active' => true,
            ]);

            // Attach exactly one company to this grouper (pivot table)
            $grouper->companies()->attach($companies[$i - 1]->id);

            $groupers[] = $grouper;
        }

        return $groupers;
    }

    /**
     * Test with 5 groupers and both CAFETERIAS and CONVENIOS columns showing values
     *
     * Expected values:
     * - TOTAL PEDIDOS: 3,250
     * - GROUPER CAFE NORTE: 60 (companies 1-3: 10+20+30)
     * - GROUPER CAFE SUR: 150 (companies 4-6: 40+50+60)
     * - GROUPER CONVENIO CONSOLIDADO ZONA A: 420 (companies 13-15: 130+140+150)
     * - GROUPER CONVENIO INDIVIDUAL ZONA B: 630 (companies 20-22: 200+210+220)
     * - GROUPER CONVENIO INDIVIDUAL ZONA C: 720 (companies 23-25: 230+240+250)
     * - CAFETERIAS: 570 (companies 7-12: 70+80+90+100+110+120)
     * - CONVENIOS: 700 (companies 16-19: 160+170+180+190)
     */
    public function test_five_groupers_with_cafeterias_and_convenios_values(): void
    {
        // Override service binding for this test
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\ReportGrouperColumnProvider::class
        );

        // Define quantities for each company (incremental 10, 20, 30, ..., 250)
        $companyQuantities = [];
        for ($i = 1; $i <= 25; $i++) {
            $companyQuantities[] = $i * 10;
        }
        $expectedTotalPedidos = array_sum($companyQuantities); // 3250

        // Create roles and permissions
        $roleCafe = \App\Models\Role::firstOrCreate(['name' => \App\Enums\RoleName::CAFE->value]);
        $roleConvenio = \App\Models\Role::firstOrCreate(['name' => \App\Enums\RoleName::AGREEMENT->value]);
        $permissionConsolidado = \App\Models\Permission::firstOrCreate(['name' => \App\Enums\PermissionName::CONSOLIDADO->value]);
        $permissionIndividual = \App\Models\Permission::firstOrCreate(['name' => \App\Enums\PermissionName::INDIVIDUAL->value]);

        $companies = [];
        $users = [];
        $orders = [];

        // Create 25 companies with specific roles and permissions
        for ($i = 1; $i <= 25; $i++) {
            // Determine role and permission
            if ($i <= 12) {
                // Companies 1-12: Café + Consolidado
                $role = $roleCafe;
                $permission = $permissionConsolidado;
                $typeLabel = 'CAFE-CONSOLIDADO';
            } elseif ($i <= 19) {
                // Companies 13-19: Convenio + Consolidado
                $role = $roleConvenio;
                $permission = $permissionConsolidado;
                $typeLabel = 'CONVENIO-CONSOLIDADO';
            } else {
                // Companies 20-25: Convenio + Individual
                $role = $roleConvenio;
                $permission = $permissionIndividual;
                $typeLabel = 'CONVENIO-INDIVIDUAL';
            }

            $company = Company::create([
                'name' => "Test Five Groupers Company {$i} S.A.",
                'fantasy_name' => "FiveGr Company {$i} - {$typeLabel}",
                'tax_id' => str_pad($i + 500, 8, '0', STR_PAD_LEFT) . "-{$i}",
                'company_code' => "FGC{$i}",
                'email' => "fgcompany{$i}@test.com",
                'phone_number' => str_repeat((string)($i % 10), 9),
                'address' => "Five Groupers Address {$i}",
                'price_list_id' => $this->company->price_list_id,
                'exclude_from_consolidated_report' => false,
            ]);

            $branch = Branch::create([
                'company_id' => $company->id,
                'address' => "FG Branch {$i} Address",
                'fantasy_name' => "FG Branch {$i}",
                'branch_code' => "FGB{$i}",
                'phone' => str_repeat((string)($i % 10), 9),
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'name' => "FG User {$i}",
                'email' => "fguser{$i}@test.com",
                'nickname' => "fguser{$i}",
                'password' => bcrypt('password'),
                'company_id' => $company->id,
                'branch_id' => $branch->id,
            ]);

            // Attach role and permission
            $user->roles()->attach($role->id);
            $user->permissions()->attach($permission->id);

            // Create PROCESSED order with unique quantity
            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $companyQuantities[$i - 1], false);

            $companies[] = $company;
            $users[] = $user;
            $orders[] = $order;
        }

        // Create 5 report groupers
        // GROUPER 1: CAFE NORTE - Companies 1-3
        $grouperCafeNorte = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $this->reportConfig->id,
            'name' => 'GROUPER CAFE NORTE',
            'code' => 'GRP_CAFE_NORTE',
            'display_order' => 1,
            'is_active' => true,
        ]);
        $grouperCafeNorte->companies()->attach([
            $companies[0]->id,  // Company 1
            $companies[1]->id,  // Company 2
            $companies[2]->id,  // Company 3
        ]);

        // GROUPER 2: CAFE SUR - Companies 4-6
        $grouperCafeSur = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $this->reportConfig->id,
            'name' => 'GROUPER CAFE SUR',
            'code' => 'GRP_CAFE_SUR',
            'display_order' => 2,
            'is_active' => true,
        ]);
        $grouperCafeSur->companies()->attach([
            $companies[3]->id,  // Company 4
            $companies[4]->id,  // Company 5
            $companies[5]->id,  // Company 6
        ]);

        // GROUPER 3: CONVENIO CONSOLIDADO ZONA A - Companies 13-15
        $grouperConvenioConsolidadoA = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $this->reportConfig->id,
            'name' => 'GROUPER CONVENIO CONSOLIDADO ZONA A',
            'code' => 'GRP_CONV_CONS_A',
            'display_order' => 3,
            'is_active' => true,
        ]);
        $grouperConvenioConsolidadoA->companies()->attach([
            $companies[12]->id,  // Company 13
            $companies[13]->id,  // Company 14
            $companies[14]->id,  // Company 15
        ]);

        // GROUPER 4: CONVENIO INDIVIDUAL ZONA B - Companies 20-22
        $grouperConvenioIndividualB = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $this->reportConfig->id,
            'name' => 'GROUPER CONVENIO INDIVIDUAL ZONA B',
            'code' => 'GRP_CONV_IND_B',
            'display_order' => 4,
            'is_active' => true,
        ]);
        $grouperConvenioIndividualB->companies()->attach([
            $companies[19]->id,  // Company 20
            $companies[20]->id,  // Company 21
            $companies[21]->id,  // Company 22
        ]);

        // GROUPER 5: CONVENIO INDIVIDUAL ZONA C - Companies 23-25
        $grouperConvenioIndividualC = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $this->reportConfig->id,
            'name' => 'GROUPER CONVENIO INDIVIDUAL ZONA C',
            'code' => 'GRP_CONV_IND_C',
            'display_order' => 5,
            'is_active' => true,
        ]);
        $grouperConvenioIndividualC->companies()->attach([
            $companies[22]->id,  // Company 23
            $companies[23]->id,  // Company 24
            $companies[24]->id,  // Company 25
        ]);

        // Create report configuration with exclude flags
        $reportConfig = \App\Models\ReportConfiguration::create([
            'name' => 'test_five_groupers_config',
            'description' => 'Test with 5 groupers and exclude columns',
            'use_groupers' => true,
            'exclude_cafeterias' => true,
            'exclude_agreements' => true,
            'is_active' => true,
        ]);

        // Create single OP with all 25 orders
        $orderIds = array_map(fn($order) => $order->id, $orders);
        $op1 = $this->createAndExecuteOp($orderIds);

        // Generate consolidated report
        $filePath = $this->generateConsolidatedReport(
            [$op1->id],
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true,
            customFileName: 'five-groupers-cafeterias-convenios'
        );

        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Find header row
        $headerRow = $this->findHeaderRow($spreadsheet);
        $this->assertNotNull($headerRow, 'Header row not found');

        // Find columns
        $totalPedidosCol = null;
        $productCodeCol = null;
        $grouperColumns = [];
        $cafeteriasCol = null;
        $conveniosCol = null;

        $col = 'A';
        while (true) {
            $headerValue = $sheet->getCell($col . $headerRow)->getValue();

            if ($headerValue === null || $headerValue === '') {
                break;
            }

            if ($headerValue === 'TOTAL PEDIDOS') {
                $totalPedidosCol = $col;
            }
            if ($headerValue === 'Código de Producto') {
                $productCodeCol = $col;
            }
            if (str_contains($headerValue, 'GROUPER CAFE NORTE')) {
                $grouperColumns['cafe_norte'] = $col;
            }
            if (str_contains($headerValue, 'GROUPER CAFE SUR')) {
                $grouperColumns['cafe_sur'] = $col;
            }
            if (str_contains($headerValue, 'GROUPER CONVENIO CONSOLIDADO ZONA A')) {
                $grouperColumns['convenio_consolidado_a'] = $col;
            }
            if (str_contains($headerValue, 'GROUPER CONVENIO INDIVIDUAL ZONA B')) {
                $grouperColumns['convenio_individual_b'] = $col;
            }
            if (str_contains($headerValue, 'GROUPER CONVENIO INDIVIDUAL ZONA C')) {
                $grouperColumns['convenio_individual_c'] = $col;
            }
            if ($headerValue === 'CAFETERIAS') {
                $cafeteriasCol = $col;
            }
            if ($headerValue === 'CONVENIOS') {
                $conveniosCol = $col;
            }

            $col++;
        }

        // Validate columns exist
        $this->assertNotNull($totalPedidosCol, 'TOTAL PEDIDOS column not found');
        $this->assertNotNull($productCodeCol, 'Product code column not found');
        $this->assertCount(5, $grouperColumns, 'Should have 5 grouper columns');
        $this->assertNotNull($cafeteriasCol, 'CAFETERIAS column not found');
        $this->assertNotNull($conveniosCol, 'CONVENIOS column not found');

        // Find Product A row
        $productARow = null;
        for ($rowIndex = $headerRow + 1; $rowIndex <= $sheet->getHighestRow(); $rowIndex++) {
            $productCode = $sheet->getCell($productCodeCol . $rowIndex)->getValue();
            if ($productCode === 'PRODUCT_A') {
                $productARow = $rowIndex;
                break;
            }
        }

        $this->assertNotNull($productARow, 'Product A row not found');

        // Validate TOTAL PEDIDOS
        $actualTotalPedidos = $sheet->getCell($totalPedidosCol . $productARow)->getValue();
        $this->assertEquals(
            $expectedTotalPedidos,
            $actualTotalPedidos,
            "TOTAL PEDIDOS should be {$expectedTotalPedidos}, but got {$actualTotalPedidos}"
        );

        // Validate grouper columns
        // GROUPER CAFE NORTE: Companies 1-3 with quantities [10, 20, 30] = 60
        $expectedCafeNorte = 10 + 20 + 30; // 60
        $actualCafeNorte = $sheet->getCell($grouperColumns['cafe_norte'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedCafeNorte,
            $actualCafeNorte,
            "GROUPER CAFE NORTE should have {$expectedCafeNorte} units (10+20+30), but got {$actualCafeNorte}"
        );

        // GROUPER CAFE SUR: Companies 4-6 with [40, 50, 60] = 150
        $expectedCafeSur = 40 + 50 + 60; // 150
        $actualCafeSur = $sheet->getCell($grouperColumns['cafe_sur'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedCafeSur,
            $actualCafeSur,
            "GROUPER CAFE SUR should have {$expectedCafeSur} units (40+50+60), but got {$actualCafeSur}"
        );

        // GROUPER CONVENIO CONSOLIDADO ZONA A: Companies 13-15 with [130, 140, 150] = 420
        $expectedConvenioConsolidadoA = 130 + 140 + 150; // 420
        $actualConvenioConsolidadoA = $sheet->getCell($grouperColumns['convenio_consolidado_a'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedConvenioConsolidadoA,
            $actualConvenioConsolidadoA,
            "GROUPER CONVENIO CONSOLIDADO ZONA A should have {$expectedConvenioConsolidadoA} units, but got {$actualConvenioConsolidadoA}"
        );

        // GROUPER CONVENIO INDIVIDUAL ZONA B: Companies 20-22 with [200, 210, 220] = 630
        $expectedConvenioIndividualB = 200 + 210 + 220; // 630
        $actualConvenioIndividualB = $sheet->getCell($grouperColumns['convenio_individual_b'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedConvenioIndividualB,
            $actualConvenioIndividualB,
            "GROUPER CONVENIO INDIVIDUAL ZONA B should have {$expectedConvenioIndividualB} units, but got {$actualConvenioIndividualB}"
        );

        // GROUPER CONVENIO INDIVIDUAL ZONA C: Companies 23-25 with [230, 240, 250] = 720
        $expectedConvenioIndividualC = 230 + 240 + 250; // 720
        $actualConvenioIndividualC = $sheet->getCell($grouperColumns['convenio_individual_c'] . $productARow)->getValue();
        $this->assertEquals(
            $expectedConvenioIndividualC,
            $actualConvenioIndividualC,
            "GROUPER CONVENIO INDIVIDUAL ZONA C should have {$expectedConvenioIndividualC} units, but got {$actualConvenioIndividualC}"
        );

        // Validate CAFETERIAS column (Café companies NOT in groupers: 7-12)
        // Companies 7-12 have quantities: [70, 80, 90, 100, 110, 120] = 570
        $expectedCafeterias = 70 + 80 + 90 + 100 + 110 + 120; // 570
        $actualCafeterias = $sheet->getCell($cafeteriasCol . $productARow)->getValue();
        $this->assertEquals(
            $expectedCafeterias,
            $actualCafeterias,
            "CAFETERIAS should have {$expectedCafeterias} units (companies 7-12 not in groupers), but got {$actualCafeterias}"
        );

        // Validate CONVENIOS column (Convenio companies NOT in groupers: 16-19)
        // Companies 16-19 have quantities: [160, 170, 180, 190] = 700
        $expectedConvenios = 160 + 170 + 180 + 190; // 700
        $actualConvenios = $sheet->getCell($conveniosCol . $productARow)->getValue();
        $this->assertEquals(
            $expectedConvenios,
            $actualConvenios,
            "CONVENIOS should have {$expectedConvenios} units (companies 16-19 not in groupers), but got {$actualConvenios}"
        );

        // Validate production values
        $expectedProductionValues = [
            'PRODUCT_A' => [
                'adelanto_inicial' => 0,
                'elaborar_1' => $expectedTotalPedidos,
                'total_elaborado' => $expectedTotalPedidos,
                'sobrantes' => 0,
            ],
        ];

        $this->validateProductionValues($spreadsheet, $expectedProductionValues);

        // Cleanup config
        $reportConfig->delete();
    }

    /**
     * Test 1: Empty Grouper (grouper with companies but no orders)
     *
     * SCENARIO:
     * - 6 companies total
     * - Companies 1-3: Café + Consolidado (GROUPER CAFE NORTE) - WITH orders (10, 20, 30)
     * - Companies 4-6: Convenio + Consolidado (GROUPER CONVENIO SUR) - WITHOUT orders
     *
     * CONFIGURATION:
     * - use_groupers = true
     * - exclude_cafeterias = false
     * - exclude_agreements = false
     *
     * EXPECTED BEHAVIOR:
     * - TOTAL PEDIDOS: 60
     * - GROUPER CAFE NORTE: 60 (companies 1-3 with orders) - APPEARS
     * - GROUPER CONVENIO SUR: Does NOT appear (no orders for this grouper)
     * - NO CAFETERIAS column (exclude_cafeterias = false)
     * - NO CONVENIOS column (exclude_agreements = false)
     *
     * This test validates that groupers WITHOUT any orders do NOT appear in the report.
     */
    public function test_grouper_with_zero_orders(): void
    {
        // Override service binding
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\ReportGrouperColumnProvider::class
        );

        // Create roles and permissions
        $roleCafe = \App\Models\Role::firstOrCreate(['name' => \App\Enums\RoleName::CAFE->value]);
        $roleConvenio = \App\Models\Role::firstOrCreate(['name' => \App\Enums\RoleName::AGREEMENT->value]);
        $permissionConsolidado = \App\Models\Permission::firstOrCreate(['name' => \App\Enums\PermissionName::CONSOLIDADO->value]);

        $companies = [];
        $users = [];
        $orders = [];

        // Create 6 companies
        for ($i = 1; $i <= 6; $i++) {
            // Determine role
            if ($i <= 3) {
                // Companies 1-3: Café + Consolidado (WITH orders)
                $role = $roleCafe;
                $typeLabel = 'CAFE-CONSOLIDADO';
                $hasOrders = true;
                $quantity = $i * 10; // 10, 20, 30
            } else {
                // Companies 4-6: Convenio + Consolidado (WITHOUT orders)
                $role = $roleConvenio;
                $typeLabel = 'CONVENIO-CONSOLIDADO';
                $hasOrders = false;
                $quantity = 0;
            }

            $company = Company::create([
                'name' => "Test Empty Grouper Company {$i} S.A.",
                'fantasy_name' => "Empty Grouper Company {$i} - {$typeLabel}",
                'tax_id' => str_pad($i + 500, 8, '0', STR_PAD_LEFT) . "-{$i}",
                'company_code' => "EGCOMP{$i}",
                'email' => "egcompany{$i}@test.com",
                'phone_number' => str_repeat((string)($i % 10), 9),
                'address' => "Empty Grouper Address {$i}",
                'price_list_id' => $this->company->price_list_id,
                'exclude_from_consolidated_report' => false,
            ]);

            $branch = Branch::create([
                'company_id' => $company->id,
                'address' => "Empty Grouper Branch {$i} Address",
                'fantasy_name' => "Empty Grouper Branch {$i}",
                'branch_code' => "EGBR{$i}",
                'phone' => str_repeat((string)($i % 10), 9),
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'name' => "Empty Grouper User {$i}",
                'email' => "eguser{$i}@test.com",
                'nickname' => "eguser{$i}",
                'password' => bcrypt('password'),
                'company_id' => $company->id,
                'branch_id' => $branch->id,
            ]);

            $user->roles()->attach($role->id);
            $user->permissions()->attach($permissionConsolidado->id);

            $companies[] = $company;
            $users[] = $user;

            // Only create orders for companies 1-3
            if ($hasOrders) {
                $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
                $this->createOrderLine($order, $this->productA, $quantity, false);
                $orders[] = $order;
            }
        }

        // Deactivate ALL configs and create test-specific config
        \App\Models\ReportConfiguration::query()->update(['is_active' => false]);

        $reportConfig = \App\Models\ReportConfiguration::create([
            'name' => 'test_empty_grouper_config',
            'description' => 'Test configuration for empty grouper',
            'use_groupers' => true,
            'exclude_cafeterias' => false,
            'exclude_agreements' => false,
            'is_active' => true,
        ]);

        // Create 2 groupers
        // GROUPER 1: CAFE NORTE (companies 1-3 WITH orders)
        $grouperCafeNorte = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER CAFE NORTE',
            'code' => 'GRP_CAFE_NORTE',
            'display_order' => 1,
            'is_active' => true,
        ]);
        $grouperCafeNorte->companies()->attach([
            $companies[0]->id,  // Company 1
            $companies[1]->id,  // Company 2
            $companies[2]->id,  // Company 3
        ]);

        // GROUPER 2: CONVENIO SUR (companies 4-6 WITHOUT orders)
        $grouperConvenioSur = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER CONVENIO SUR',
            'code' => 'GRP_CONVENIO_SUR',
            'display_order' => 2,
            'is_active' => true,
        ]);
        $grouperConvenioSur->companies()->attach([
            $companies[3]->id,  // Company 4
            $companies[4]->id,  // Company 5
            $companies[5]->id,  // Company 6
        ]);

        // Create OP and generate report
        $orderIds = array_map(fn($order) => $order->id, $orders);
        $op1 = $this->createAndExecuteOp($orderIds);

        $filePath = $this->generateConsolidatedReport(
            [$op1->id],
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true
        );

        $this->assertFileExists($filePath);

        // Read and validate Excel
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Find header row
        $headerRow = $this->findHeaderRow($spreadsheet);
        $this->assertNotNull($headerRow, 'Header row not found');

        // Find columns
        $grouperCafeNorteCol = null;
        $grouperConvenioSurCol = null;
        $totalPedidosCol = null;
        $cafeteriasCol = null;
        $conveniosCol = null;

        $col = 'A';
        while (true) {
            $headerValue = $sheet->getCell($col . $headerRow)->getValue();

            if ($headerValue === null || $headerValue === '') {
                break;
            }

            if ($headerValue === 'GROUPER CAFE NORTE') {
                $grouperCafeNorteCol = $col;
            }
            if ($headerValue === 'GROUPER CONVENIO SUR') {
                $grouperConvenioSurCol = $col;
            }
            if ($headerValue === 'TOTAL PEDIDOS') {
                $totalPedidosCol = $col;
            }
            if ($headerValue === 'CAFETERIAS') {
                $cafeteriasCol = $col;
            }
            if ($headerValue === 'CONVENIOS') {
                $conveniosCol = $col;
            }

            $col++;
        }

        // Validate that ONLY GROUPER CAFE NORTE appears (has orders)
        $this->assertNotNull($grouperCafeNorteCol, 'GROUPER CAFE NORTE column should exist (has orders)');
        $this->assertNotNull($totalPedidosCol, 'TOTAL PEDIDOS column should exist');

        // Validate that GROUPER CONVENIO SUR does NOT appear (no orders)
        $this->assertNull($grouperConvenioSurCol, 'GROUPER CONVENIO SUR column should NOT exist (no orders)');

        // Validate exclude columns do NOT exist
        $this->assertNull($cafeteriasCol, 'CAFETERIAS column should NOT exist (exclude_cafeterias = false)');
        $this->assertNull($conveniosCol, 'CONVENIOS column should NOT exist (exclude_agreements = false)');

        // Find the product data row (search for first row with numeric value in TOTAL PEDIDOS column)
        $productRow = null;
        for ($row = $headerRow + 1; $row <= $headerRow + 20; $row++) {
            $testValue = $sheet->getCell($totalPedidosCol . $row)->getValue();
            if (is_numeric($testValue) && $testValue > 0) {
                $productRow = $row;
                break;
            }
        }

        $this->assertNotNull($productRow, 'Could not find product data row');

        // Validate values
        $grouperCafeNorteValue = $sheet->getCell($grouperCafeNorteCol . $productRow)->getValue();
        $totalPedidosValue = $sheet->getCell($totalPedidosCol . $productRow)->getValue();

        $this->assertEquals(60, $grouperCafeNorteValue, 'GROUPER CAFE NORTE should have 60 units');
        $this->assertEquals(60, $totalPedidosValue, 'TOTAL PEDIDOS should be 60');

        // Validate production values
        $expectedProductionValues = [
            'PRODUCT_A' => [
                'adelanto_inicial' => 0,  // No previous orders
                'elaborar_1' => 60,       // All orders go to ELABORAR 1
                'total_elaborado' => 60,
                'sobrantes' => 0,
            ],
        ];

        $this->validateProductionValues($spreadsheet, $expectedProductionValues);

        // Cleanup config
        $reportConfig->delete();
    }

    /**
     * Test 2: CAFETERIAS and CONVENIOS with zero values
     *
     * SCENARIO:
     * - 6 companies total
     * - Companies 1-3: Café + Consolidado → ALL in GROUPER CAFE (10, 20, 30)
     * - Companies 4-6: Convenio + Consolidado → ALL in GROUPER CONVENIO (40, 50, 60)
     * - All companies have orders
     * - NO companies outside groupers
     *
     * CONFIGURATION:
     * - use_groupers = true
     * - exclude_cafeterias = true
     * - exclude_agreements = true
     *
     * EXPECTED BEHAVIOR:
     * - TOTAL PEDIDOS: 210
     * - GROUPER CAFE: 60 (companies 1-3) - APPEARS
     * - GROUPER CONVENIO: 150 (companies 4-6) - APPEARS
     * - CAFETERIAS: Does NOT appear (no cafés outside groupers)
     * - CONVENIOS: APPEARS with 0 (no convenios outside groupers, but column shown)
     *
     * This test validates that:
     * - CAFETERIAS column does NOT appear when there are no cafés outside groupers
     * - CONVENIOS column DOES appear with value 0 when there are no convenios outside groupers
     */
    public function test_cafeterias_and_convenios_with_zero_values(): void
    {
        // Override service binding
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\ReportGrouperColumnProvider::class
        );

        // Define quantities for each company (10, 20, 30, 40, 50, 60)
        $companyQuantities = [];
        for ($i = 1; $i <= 6; $i++) {
            $companyQuantities[] = $i * 10;
        }
        $expectedTotalPedidos = array_sum($companyQuantities); // 210

        // Create roles and permissions
        $roleCafe = \App\Models\Role::firstOrCreate(['name' => \App\Enums\RoleName::CAFE->value]);
        $roleConvenio = \App\Models\Role::firstOrCreate(['name' => \App\Enums\RoleName::AGREEMENT->value]);
        $permissionConsolidado = \App\Models\Permission::firstOrCreate(['name' => \App\Enums\PermissionName::CONSOLIDADO->value]);

        $companies = [];
        $users = [];
        $orders = [];

        // Create 6 companies
        for ($i = 1; $i <= 6; $i++) {
            // Determine role
            if ($i <= 3) {
                // Companies 1-3: Café + Consolidado
                $role = $roleCafe;
                $typeLabel = 'CAFE-CONSOLIDADO';
            } else {
                // Companies 4-6: Convenio + Consolidado
                $role = $roleConvenio;
                $typeLabel = 'CONVENIO-CONSOLIDADO';
            }

            $company = Company::create([
                'name' => "Test Zero Exclude Company {$i} S.A.",
                'fantasy_name' => "Zero Exclude Company {$i} - {$typeLabel}",
                'tax_id' => str_pad($i + 600, 8, '0', STR_PAD_LEFT) . "-{$i}",
                'company_code' => "ZECOMP{$i}",
                'email' => "zecompany{$i}@test.com",
                'phone_number' => str_repeat((string)($i % 10), 9),
                'address' => "Zero Exclude Address {$i}",
                'price_list_id' => $this->company->price_list_id,
                'exclude_from_consolidated_report' => false,
            ]);

            $branch = Branch::create([
                'company_id' => $company->id,
                'address' => "Zero Exclude Branch {$i} Address",
                'fantasy_name' => "Zero Exclude Branch {$i}",
                'branch_code' => "ZEBR{$i}",
                'phone' => str_repeat((string)($i % 10), 9),
                'min_price_order' => 0,
            ]);

            $user = User::create([
                'name' => "Zero Exclude User {$i}",
                'email' => "zeuser{$i}@test.com",
                'nickname' => "zeuser{$i}",
                'password' => bcrypt('password'),
                'company_id' => $company->id,
                'branch_id' => $branch->id,
            ]);

            $user->roles()->attach($role->id);
            $user->permissions()->attach($permissionConsolidado->id);

            // Create order for ALL companies
            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $companyQuantities[$i - 1], false);

            $companies[] = $company;
            $users[] = $user;
            $orders[] = $order;
        }

        // Deactivate ALL configs and create test-specific config
        \App\Models\ReportConfiguration::query()->update(['is_active' => false]);

        $reportConfig = \App\Models\ReportConfiguration::create([
            'name' => 'test_zero_exclude_config',
            'description' => 'Test configuration for zero exclude columns',
            'use_groupers' => true,
            'exclude_cafeterias' => true,
            'exclude_agreements' => true,
            'is_active' => true,
        ]);

        // Create 2 groupers - ALL companies are in groupers
        // GROUPER 1: CAFE (companies 1-3)
        $grouperCafe = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER CAFE',
            'code' => 'GRP_CAFE',
            'display_order' => 1,
            'is_active' => true,
        ]);
        $grouperCafe->companies()->attach([
            $companies[0]->id,  // Company 1
            $companies[1]->id,  // Company 2
            $companies[2]->id,  // Company 3
        ]);

        // GROUPER 2: CONVENIO (companies 4-6)
        $grouperConvenio = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER CONVENIO',
            'code' => 'GRP_CONVENIO',
            'display_order' => 2,
            'is_active' => true,
        ]);
        $grouperConvenio->companies()->attach([
            $companies[3]->id,  // Company 4
            $companies[4]->id,  // Company 5
            $companies[5]->id,  // Company 6
        ]);

        // Create OP and generate report
        $orderIds = array_map(fn($order) => $order->id, $orders);
        $op1 = $this->createAndExecuteOp($orderIds);

        $filePath = $this->generateConsolidatedReport(
            [$op1->id],
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true
        );

        $this->assertFileExists($filePath);

        // Read and validate Excel
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Find header row
        $headerRow = $this->findHeaderRow($spreadsheet);
        $this->assertNotNull($headerRow, 'Header row not found');

        // Find columns
        $grouperCafeCol = null;
        $grouperConvenioCol = null;
        $cafeteriasCol = null;
        $conveniosCol = null;
        $totalPedidosCol = null;

        $col = 'A';
        while (true) {
            $headerValue = $sheet->getCell($col . $headerRow)->getValue();

            if ($headerValue === null || $headerValue === '') {
                break;
            }

            if ($headerValue === 'GROUPER CAFE') {
                $grouperCafeCol = $col;
            }
            if ($headerValue === 'GROUPER CONVENIO') {
                $grouperConvenioCol = $col;
            }
            if ($headerValue === 'CAFETERIAS') {
                $cafeteriasCol = $col;
            }
            if ($headerValue === 'CONVENIOS') {
                $conveniosCol = $col;
            }
            if ($headerValue === 'TOTAL PEDIDOS') {
                $totalPedidosCol = $col;
            }

            $col++;
        }

        // Validate that grouper columns appear (have orders)
        $this->assertNotNull($grouperCafeCol, 'GROUPER CAFE column should exist (has orders)');
        $this->assertNotNull($grouperConvenioCol, 'GROUPER CONVENIO column should exist (has orders)');
        $this->assertNotNull($totalPedidosCol, 'TOTAL PEDIDOS column should exist');

        // Validate CAFETERIAS does NOT appear (no cafés outside groupers)
        $this->assertNull($cafeteriasCol, 'CAFETERIAS column should NOT exist (no cafés outside groupers)');

        // Validate CONVENIOS DOES appear with 0 (special behavior: always shows column when enabled)
        $this->assertNotNull($conveniosCol, 'CONVENIOS column should exist (even with 0 values)');

        // Find the product data row (search for first row with numeric value in TOTAL PEDIDOS column)
        $productRow = null;
        for ($row = $headerRow + 1; $row <= $headerRow + 20; $row++) {
            $testValue = $sheet->getCell($totalPedidosCol . $row)->getValue();
            if (is_numeric($testValue) && $testValue > 0) {
                $productRow = $row;
                break;
            }
        }

        $this->assertNotNull($productRow, 'Could not find product data row');

        // Validate values
        $grouperCafeValue = $sheet->getCell($grouperCafeCol . $productRow)->getValue();
        $grouperConvenioValue = $sheet->getCell($grouperConvenioCol . $productRow)->getValue();
        $conveniosValue = $sheet->getCell($conveniosCol . $productRow)->getValue();
        $totalPedidosValue = $sheet->getCell($totalPedidosCol . $productRow)->getValue();

        $this->assertEquals(60, $grouperCafeValue, 'GROUPER CAFE should have 60 units (10+20+30)');
        $this->assertEquals(150, $grouperConvenioValue, 'GROUPER CONVENIO should have 150 units (40+50+60)');
        $this->assertEquals(0, $conveniosValue, 'CONVENIOS should be 0 (no convenios outside groupers)');
        $this->assertEquals($expectedTotalPedidos, $totalPedidosValue, 'TOTAL PEDIDOS should be 210');

        // Validate production values
        $expectedProductionValues = [
            'PRODUCT_A' => [
                'adelanto_inicial' => 0,                     // No previous orders
                'elaborar_1' => $expectedTotalPedidos,       // All orders go to ELABORAR 1
                'total_elaborado' => $expectedTotalPedidos,
                'sobrantes' => 0,
            ],
        ];

        $this->validateProductionValues($spreadsheet, $expectedProductionValues);

        // Cleanup config
        $reportConfig->delete();
    }

    /**
     * TDD Test: Groupers with Branch Filtering
     *
     * Tests the new functionality for filtering groupers by branches.
     * This test creates 10 orders from the same company but different branches,
     * and validates that the report shows separate columns for each branch-specific grouper.
     *
     * SCENARIO:
     * - 1 Company (TEST COMPANY)
     * - 2 Branches (Branch 1, Branch 2)
     * - 10 Orders total:
     *   - 5 orders from users in Branch 1 (quantities: 10, 20, 30, 40, 50) = 150 total
     *   - 5 orders from users in Branch 2 (quantities: 15, 25, 35, 45, 55) = 175 total
     * - 2 Groupers:
     *   - Grouper 1: Same company + Branch 1 only
     *   - Grouper 2: Same company + Branch 2 only
     *
     * EXPECTED RESULT:
     * - Report should have 2 separate grouper columns
     * - Grouper 1 column should show 150 total units (Branch 1 orders only)
     * - Grouper 2 column should show 175 total units (Branch 2 orders only)
     * - TOTAL PEDIDOS should be 325 units (150 + 175)
     */
    public function test_tdd_groupers_filtered_by_branches(): void
    {
        // Override service binding
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\ReportGrouperColumnProvider::class
        );

        // Create roles and permissions
        $roleConvenio = \App\Models\Role::firstOrCreate(['name' => \App\Enums\RoleName::AGREEMENT->value]);
        $permissionConsolidado = \App\Models\Permission::firstOrCreate(['name' => \App\Enums\PermissionName::CONSOLIDADO->value]);

        // Create single company
        $company = Company::create([
            'name' => 'Test Multi-Branch Company S.A.',
            'fantasy_name' => 'Multi-Branch Company',
            'tax_id' => '77.777.777-7',
            'company_code' => 'MBCOMP1',
            'email' => 'multibranch@test.com',
            'phone_number' => '999999999',
            'address' => 'Multi-Branch Address 1',
            'price_list_id' => $this->company->price_list_id,
            'exclude_from_consolidated_report' => false,
        ]);

        // Create 2 branches
        $branch1 = Branch::create([
            'company_id' => $company->id,
            'address' => 'Branch 1 Address',
            'fantasy_name' => 'Branch 1',
            'branch_code' => 'BR1',
            'phone' => '111111111',
            'min_price_order' => 0,
        ]);

        $branch2 = Branch::create([
            'company_id' => $company->id,
            'address' => 'Branch 2 Address',
            'fantasy_name' => 'Branch 2',
            'branch_code' => 'BR2',
            'phone' => '222222222',
            'min_price_order' => 0,
        ]);

        // Branch 1: quantities = 10, 20, 30, 40, 50 (total = 150)
        $branch1Quantities = [10, 20, 30, 40, 50];
        $branch1Total = array_sum($branch1Quantities); // 150

        // Branch 2: quantities = 15, 25, 35, 45, 55 (total = 175)
        $branch2Quantities = [15, 25, 35, 45, 55];
        $branch2Total = array_sum($branch2Quantities); // 175

        $expectedTotalPedidos = $branch1Total + $branch2Total; // 325

        $users = [];
        $orders = [];

        // Create 5 users and orders for Branch 1
        for ($i = 1; $i <= 5; $i++) {
            $user = User::create([
                'name' => "Branch 1 User {$i}",
                'email' => "branch1user{$i}@test.com",
                'nickname' => "br1user{$i}",
                'password' => bcrypt('password'),
                'company_id' => $company->id,
                'branch_id' => $branch1->id,
            ]);

            $user->roles()->attach($roleConvenio->id);
            $user->permissions()->attach($permissionConsolidado->id);

            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $branch1Quantities[$i - 1], false);

            $users[] = $user;
            $orders[] = $order;
        }

        // Create 5 users and orders for Branch 2
        for ($i = 1; $i <= 5; $i++) {
            $user = User::create([
                'name' => "Branch 2 User {$i}",
                'email' => "branch2user{$i}@test.com",
                'nickname' => "br2user{$i}",
                'password' => bcrypt('password'),
                'company_id' => $company->id,
                'branch_id' => $branch2->id,
            ]);

            $user->roles()->attach($roleConvenio->id);
            $user->permissions()->attach($permissionConsolidado->id);

            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $branch2Quantities[$i - 1], false);

            $users[] = $user;
            $orders[] = $order;
        }

        // Deactivate ALL configs and create test-specific config
        \App\Models\ReportConfiguration::query()->update(['is_active' => false]);

        $reportConfig = \App\Models\ReportConfiguration::create([
            'name' => 'test_branch_grouper_config',
            'description' => 'Test configuration for branch-specific groupers',
            'use_groupers' => true,
            'exclude_cafeterias' => false,
            'exclude_agreements' => false,
            'is_active' => true,
        ]);

        // Create 2 groupers with same company but different branches
        // GROUPER 1: Company + Branch 1
        $grouperBranch1 = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'BRANCH 1 GROUPER',
            'code' => 'GRP_BR1',
            'display_order' => 1,
            'is_active' => true,
        ]);
        // Attach company with use_all_branches = false (we want specific branches only)
        $grouperBranch1->companies()->attach($company->id, ['use_all_branches' => false]);
        $grouperBranch1->branches()->attach($branch1->id);

        // GROUPER 2: Company + Branch 2
        $grouperBranch2 = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'BRANCH 2 GROUPER',
            'code' => 'GRP_BR2',
            'display_order' => 2,
            'is_active' => true,
        ]);
        // Attach company with use_all_branches = false (we want specific branches only)
        $grouperBranch2->companies()->attach($company->id, ['use_all_branches' => false]);
        $grouperBranch2->branches()->attach($branch2->id);

        // Create OP and generate report
        $orderIds = array_map(fn($order) => $order->id, $orders);
        $op1 = $this->createAndExecuteOp($orderIds);

        $filePath = $this->generateConsolidatedReport(
            [$op1->id],
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true
        );

        $this->assertFileExists($filePath);

        // Read and validate Excel
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Find header row
        $headerRow = $this->findHeaderRow($spreadsheet);
        $this->assertNotNull($headerRow, 'Header row not found');

        // Find columns
        $grouperBranch1Col = null;
        $grouperBranch2Col = null;
        $totalPedidosCol = null;

        $col = 'A';
        while (true) {
            $headerValue = $sheet->getCell($col . $headerRow)->getValue();

            if ($headerValue === null || $headerValue === '') {
                break;
            }

            if ($headerValue === 'BRANCH 1 GROUPER') {
                $grouperBranch1Col = $col;
            }
            if ($headerValue === 'BRANCH 2 GROUPER') {
                $grouperBranch2Col = $col;
            }
            if ($headerValue === 'TOTAL PEDIDOS') {
                $totalPedidosCol = $col;
            }

            $col++;
        }

        // Validate that both grouper columns exist
        $this->assertNotNull($grouperBranch1Col, 'BRANCH 1 GROUPER column should exist');
        $this->assertNotNull($grouperBranch2Col, 'BRANCH 2 GROUPER column should exist');
        $this->assertNotNull($totalPedidosCol, 'TOTAL PEDIDOS column should exist');

        // Find the product data row
        $productRow = null;
        for ($row = $headerRow + 1; $row <= $headerRow + 20; $row++) {
            $testValue = $sheet->getCell($totalPedidosCol . $row)->getValue();
            if (is_numeric($testValue) && $testValue > 0) {
                $productRow = $row;
                break;
            }
        }

        $this->assertNotNull($productRow, 'Could not find product data row');

        // Validate values
        $grouperBranch1Value = $sheet->getCell($grouperBranch1Col . $productRow)->getValue();
        $grouperBranch2Value = $sheet->getCell($grouperBranch2Col . $productRow)->getValue();
        $totalPedidosValue = $sheet->getCell($totalPedidosCol . $productRow)->getValue();

        // TDD: These assertions will FAIL until we implement branch filtering in the report logic
        $this->assertEquals(
            $branch1Total,
            $grouperBranch1Value,
            "BRANCH 1 GROUPER should have {$branch1Total} units (10+20+30+40+50)"
        );

        $this->assertEquals(
            $branch2Total,
            $grouperBranch2Value,
            "BRANCH 2 GROUPER should have {$branch2Total} units (15+25+35+45+55)"
        );

        $this->assertEquals(
            $expectedTotalPedidos,
            $totalPedidosValue,
            "TOTAL PEDIDOS should be {$expectedTotalPedidos}"
        );

        // Validate production values
        $expectedProductionValues = [
            'PRODUCT_A' => [
                'adelanto_inicial' => 0,
                'elaborar_1' => $expectedTotalPedidos,
                'total_elaborado' => $expectedTotalPedidos,
                'sobrantes' => 0,
            ],
        ];

        $this->validateProductionValues($spreadsheet, $expectedProductionValues);

        // Cleanup config
        $reportConfig->delete();
    }

    /**
     * Complex Test: Mixed Groupers with Multiple Companies and Branch Configurations
     *
     * Tests complex scenarios mixing companies with all branches and companies with specific branches.
     *
     * SCENARIO:
     * - 4 Companies, each with multiple branches
     * - 20 Orders distributed across companies and branches
     * - 4 Groupers with mixed configurations:
     *
     * COMPANY 1 (6 branches):
     *   - Branch 1A: 1 order (10 units)
     *   - Branch 1B: 1 order (20 units)
     *   - Branch 1C: 1 order (30 units)
     *   - Branch 1D: 1 order (40 units)
     *   - Branch 1E: 1 order (50 units)
     *   - Branch 1F: 1 order (60 units)
     *   Total Company 1: 210 units (10+20+30+40+50+60)
     *
     * COMPANY 2 (6 branches):
     *   - Branch 2A: 1 order (15 units)
     *   - Branch 2B: 1 order (25 units)
     *   - Branch 2C: 1 order (35 units)
     *   - Branch 2D: 1 order (45 units)
     *   - Branch 2E: 1 order (55 units)
     *   - Branch 2F: 1 order (65 units)
     *   Total Company 2: 240 units (15+25+35+45+55+65)
     *   Group A (2A, 2B, 2C): 75 units (15+25+35)
     *   Group B (2D, 2E, 2F): 165 units (45+55+65)
     *
     * COMPANY 3 (4 branches):
     *   - Branch 3A: 1 order (100 units)
     *   - Branch 3B: 1 order (200 units)
     *   - Branch 3C: 1 order (300 units)
     *   - Branch 3D: 1 order (400 units)
     *   Total Company 3: 1000 units (100+200+300+400)
     *
     * COMPANY 4 (4 branches):
     *   - Branch 4A: 1 order (11 units)
     *   - Branch 4B: 1 order (22 units)
     *   - Branch 4C: 1 order (33 units)
     *   - Branch 4D: 1 order (44 units)
     *   Total Company 4: 110 units (11+22+33+44)
     *
     * GROUPER CONFIGURATION:
     * - Grouper 1: Company 1 (ALL branches) + Company 2 (branches 2A, 2B, 2C only)
     *   Expected: 210 + 75 = 285 units
     *
     * - Grouper 2: Company 2 (branches 2D, 2E, 2F only)
     *   Expected: 165 units
     *
     * - Grouper 3: Company 3 (ALL branches)
     *   Expected: 1000 units
     *
     * - Grouper 4: Company 4 (ALL branches)
     *   Expected: 110 units
     *
     * TOTAL PEDIDOS: 285 + 165 + 1000 + 110 = 1560 units
     */
    public function test_complex_mixed_groupers_with_multiple_companies(): void
    {
        // Override service binding
        $this->app->bind(
            \App\Contracts\ReportColumnDataProviderInterface::class,
            \App\Services\Reports\ReportGrouperColumnProvider::class
        );

        // Create roles and permissions
        $roleConvenio = \App\Models\Role::firstOrCreate(['name' => \App\Enums\RoleName::AGREEMENT->value]);
        $permissionConsolidado = \App\Models\Permission::firstOrCreate(['name' => \App\Enums\PermissionName::CONSOLIDADO->value]);

        // ========================================
        // COMPANY 1: 6 branches, ALL branches in Grouper 1
        // ========================================
        $company1 = Company::create([
            'name' => 'Test Company 1 S.A.',
            'fantasy_name' => 'Company 1',
            'tax_id' => '11.111.111-1',
            'company_code' => 'COMP1',
            'email' => 'company1@test.com',
            'phone_number' => '111111111',
            'address' => 'Company 1 Address',
            'price_list_id' => $this->company->price_list_id,
            'exclude_from_consolidated_report' => false,
        ]);

        $company1Branches = [];
        $company1Quantities = [10, 20, 30, 40, 50, 60]; // Total: 210
        $company1Total = array_sum($company1Quantities);

        for ($i = 0; $i < 6; $i++) {
            $branchLetter = chr(65 + $i); // A, B, C, D, E, F
            $branch = Branch::create([
                'company_id' => $company1->id,
                'address' => "Branch 1{$branchLetter} Address",
                'fantasy_name' => "Branch 1{$branchLetter}",
                'branch_code' => "BR1{$branchLetter}",
                'phone' => '111111' . ($i + 1),
                'min_price_order' => 0,
            ]);
            $company1Branches[] = $branch;

            // Create user and order for this branch
            $user = User::create([
                'name' => "Company 1 Branch {$branchLetter} User",
                'email' => "c1branch{$branchLetter}@test.com",
                'nickname' => "c1br{$branchLetter}",
                'password' => bcrypt('password'),
                'company_id' => $company1->id,
                'branch_id' => $branch->id,
            ]);
            $user->roles()->attach($roleConvenio->id);
            $user->permissions()->attach($permissionConsolidado->id);

            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $company1Quantities[$i], false);
        }

        // ========================================
        // COMPANY 2: 6 branches, split between Grouper 1 (A,B,C) and Grouper 2 (D,E,F)
        // ========================================
        $company2 = Company::create([
            'name' => 'Test Company 2 S.A.',
            'fantasy_name' => 'Company 2',
            'tax_id' => '22.222.222-2',
            'company_code' => 'COMP2',
            'email' => 'company2@test.com',
            'phone_number' => '222222222',
            'address' => 'Company 2 Address',
            'price_list_id' => $this->company->price_list_id,
            'exclude_from_consolidated_report' => false,
        ]);

        $company2Branches = [];
        $company2Quantities = [15, 25, 35, 45, 55, 65]; // Total: 240
        $company2GroupA = array_sum(array_slice($company2Quantities, 0, 3)); // 15+25+35 = 75
        $company2GroupB = array_sum(array_slice($company2Quantities, 3, 3)); // 45+55+65 = 165

        for ($i = 0; $i < 6; $i++) {
            $branchLetter = chr(65 + $i); // A, B, C, D, E, F
            $branch = Branch::create([
                'company_id' => $company2->id,
                'address' => "Branch 2{$branchLetter} Address",
                'fantasy_name' => "Branch 2{$branchLetter}",
                'branch_code' => "BR2{$branchLetter}",
                'phone' => '222222' . ($i + 1),
                'min_price_order' => 0,
            ]);
            $company2Branches[] = $branch;

            // Create user and order for this branch
            $user = User::create([
                'name' => "Company 2 Branch {$branchLetter} User",
                'email' => "c2branch{$branchLetter}@test.com",
                'nickname' => "c2br{$branchLetter}",
                'password' => bcrypt('password'),
                'company_id' => $company2->id,
                'branch_id' => $branch->id,
            ]);
            $user->roles()->attach($roleConvenio->id);
            $user->permissions()->attach($permissionConsolidado->id);

            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $company2Quantities[$i], false);
        }

        // ========================================
        // COMPANY 3: 4 branches, ALL branches in Grouper 3
        // ========================================
        $company3 = Company::create([
            'name' => 'Test Company 3 S.A.',
            'fantasy_name' => 'Company 3',
            'tax_id' => '33.333.333-3',
            'company_code' => 'COMP3',
            'email' => 'company3@test.com',
            'phone_number' => '333333333',
            'address' => 'Company 3 Address',
            'price_list_id' => $this->company->price_list_id,
            'exclude_from_consolidated_report' => false,
        ]);

        $company3Branches = [];
        $company3Quantities = [100, 200, 300, 400]; // Total: 1000
        $company3Total = array_sum($company3Quantities);

        for ($i = 0; $i < 4; $i++) {
            $branchLetter = chr(65 + $i); // A, B, C, D
            $branch = Branch::create([
                'company_id' => $company3->id,
                'address' => "Branch 3{$branchLetter} Address",
                'fantasy_name' => "Branch 3{$branchLetter}",
                'branch_code' => "BR3{$branchLetter}",
                'phone' => '333333' . ($i + 1),
                'min_price_order' => 0,
            ]);
            $company3Branches[] = $branch;

            // Create user and order for this branch
            $user = User::create([
                'name' => "Company 3 Branch {$branchLetter} User",
                'email' => "c3branch{$branchLetter}@test.com",
                'nickname' => "c3br{$branchLetter}",
                'password' => bcrypt('password'),
                'company_id' => $company3->id,
                'branch_id' => $branch->id,
            ]);
            $user->roles()->attach($roleConvenio->id);
            $user->permissions()->attach($permissionConsolidado->id);

            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $company3Quantities[$i], false);
        }

        // ========================================
        // COMPANY 4: 4 branches, ALL branches in Grouper 4
        // ========================================
        $company4 = Company::create([
            'name' => 'Test Company 4 S.A.',
            'fantasy_name' => 'Company 4',
            'tax_id' => '44.444.444-4',
            'company_code' => 'COMP4',
            'email' => 'company4@test.com',
            'phone_number' => '444444444',
            'address' => 'Company 4 Address',
            'price_list_id' => $this->company->price_list_id,
            'exclude_from_consolidated_report' => false,
        ]);

        $company4Branches = [];
        $company4Quantities = [11, 22, 33, 44]; // Total: 110
        $company4Total = array_sum($company4Quantities);

        for ($i = 0; $i < 4; $i++) {
            $branchLetter = chr(65 + $i); // A, B, C, D
            $branch = Branch::create([
                'company_id' => $company4->id,
                'address' => "Branch 4{$branchLetter} Address",
                'fantasy_name' => "Branch 4{$branchLetter}",
                'branch_code' => "BR4{$branchLetter}",
                'phone' => '444444' . ($i + 1),
                'min_price_order' => 0,
            ]);
            $company4Branches[] = $branch;

            // Create user and order for this branch
            $user = User::create([
                'name' => "Company 4 Branch {$branchLetter} User",
                'email' => "c4branch{$branchLetter}@test.com",
                'nickname' => "c4br{$branchLetter}",
                'password' => bcrypt('password'),
                'company_id' => $company4->id,
                'branch_id' => $branch->id,
            ]);
            $user->roles()->attach($roleConvenio->id);
            $user->permissions()->attach($permissionConsolidado->id);

            $order = $this->createOrderForUser($user, $this->dateFA, OrderStatus::PROCESSED);
            $this->createOrderLine($order, $this->productA, $company4Quantities[$i], false);
        }

        // ========================================
        // CREATE REPORT CONFIGURATION
        // ========================================
        \App\Models\ReportConfiguration::query()->update(['is_active' => false]);

        $reportConfig = \App\Models\ReportConfiguration::create([
            'name' => 'test_complex_mixed_groupers',
            'description' => 'Test configuration for complex mixed grouper scenarios',
            'use_groupers' => true,
            'exclude_cafeterias' => false,
            'exclude_agreements' => false,
            'is_active' => true,
        ]);

        // ========================================
        // GROUPER 1: Company 1 (ALL branches) + Company 2 (branches A, B, C only)
        // Expected: 210 + 75 = 285 units
        // ========================================
        $grouper1 = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER 1',
            'code' => 'GRP1',
            'display_order' => 1,
            'is_active' => true,
        ]);
        // Company 1: ALL branches
        $grouper1->companies()->attach($company1->id, ['use_all_branches' => true]);
        // Company 2: ONLY branches A, B, C
        $grouper1->companies()->attach($company2->id, ['use_all_branches' => false]);
        $grouper1->branches()->attach([
            $company2Branches[0]->id, // 2A
            $company2Branches[1]->id, // 2B
            $company2Branches[2]->id, // 2C
        ]);

        $grouper1Expected = $company1Total + $company2GroupA; // 210 + 75 = 285

        // ========================================
        // GROUPER 2: Company 2 (branches D, E, F only)
        // Expected: 165 units
        // ========================================
        $grouper2 = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER 2',
            'code' => 'GRP2',
            'display_order' => 2,
            'is_active' => true,
        ]);
        // Company 2: ONLY branches D, E, F
        $grouper2->companies()->attach($company2->id, ['use_all_branches' => false]);
        $grouper2->branches()->attach([
            $company2Branches[3]->id, // 2D
            $company2Branches[4]->id, // 2E
            $company2Branches[5]->id, // 2F
        ]);

        $grouper2Expected = $company2GroupB; // 165

        // ========================================
        // GROUPER 3: Company 3 (ALL branches)
        // Expected: 1000 units
        // ========================================
        $grouper3 = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER 3',
            'code' => 'GRP3',
            'display_order' => 3,
            'is_active' => true,
        ]);
        // Company 3: ALL branches
        $grouper3->companies()->attach($company3->id, ['use_all_branches' => true]);

        $grouper3Expected = $company3Total; // 1000

        // ========================================
        // GROUPER 4: Company 4 (ALL branches)
        // Expected: 110 units
        // ========================================
        $grouper4 = \App\Models\ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'GROUPER 4',
            'code' => 'GRP4',
            'display_order' => 4,
            'is_active' => true,
        ]);
        // Company 4: ALL branches
        $grouper4->companies()->attach($company4->id, ['use_all_branches' => true]);

        $grouper4Expected = $company4Total; // 110

        // ========================================
        // GENERATE REPORT
        // ========================================
        $expectedTotalPedidos = $grouper1Expected + $grouper2Expected + $grouper3Expected + $grouper4Expected; // 1560

        $allOrders = Order::whereIn('user_id', User::whereIn('company_id', [
            $company1->id,
            $company2->id,
            $company3->id,
            $company4->id,
        ])->pluck('id'))->get();

        $orderIds = $allOrders->pluck('id')->toArray();
        $op1 = $this->createAndExecuteOp($orderIds);

        $filePath = $this->generateConsolidatedReport(
            [$op1->id],
            showExcludedCompanies: true,
            showAllAdelantos: true,
            showTotalElaborado: true,
            showSobrantes: true
        );

        $this->assertFileExists($filePath);

        // ========================================
        // VALIDATE EXCEL REPORT
        // ========================================
        $spreadsheet = $this->loadExcelFile($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Find header row and columns
        $headerRow = 1;
        $productRow = null;
        $grouperColumns = [];

        // Find grouper columns and product row
        foreach ($sheet->getRowIterator() as $row) {
            $rowIndex = $row->getRowIndex();
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();

                // Find header columns
                if ($rowIndex === $headerRow) {
                    if ($value === 'GROUPER 1') {
                        $grouperColumns['GRP1'] = $cell->getColumn();
                    } elseif ($value === 'GROUPER 2') {
                        $grouperColumns['GRP2'] = $cell->getColumn();
                    } elseif ($value === 'GROUPER 3') {
                        $grouperColumns['GRP3'] = $cell->getColumn();
                    } elseif ($value === 'GROUPER 4') {
                        $grouperColumns['GRP4'] = $cell->getColumn();
                    } elseif ($value === 'TOTAL PEDIDOS') {
                        $grouperColumns['TOTAL'] = $cell->getColumn();
                    }
                }

                // Find product row
                if ($cell->getColumn() === 'B' && $value === 'PRODUCT_A') {
                    $productRow = $rowIndex;
                }
            }
        }

        $this->assertNotNull($productRow, 'Product row not found');
        $this->assertArrayHasKey('GRP1', $grouperColumns, 'GROUPER 1 column not found');
        $this->assertArrayHasKey('GRP2', $grouperColumns, 'GROUPER 2 column not found');
        $this->assertArrayHasKey('GRP3', $grouperColumns, 'GROUPER 3 column not found');
        $this->assertArrayHasKey('GRP4', $grouperColumns, 'GROUPER 4 column not found');
        $this->assertArrayHasKey('TOTAL', $grouperColumns, 'TOTAL PEDIDOS column not found');

        // Get values from report
        $grouper1Value = $sheet->getCell($grouperColumns['GRP1'] . $productRow)->getValue();
        $grouper2Value = $sheet->getCell($grouperColumns['GRP2'] . $productRow)->getValue();
        $grouper3Value = $sheet->getCell($grouperColumns['GRP3'] . $productRow)->getValue();
        $grouper4Value = $sheet->getCell($grouperColumns['GRP4'] . $productRow)->getValue();
        $totalPedidosValue = $sheet->getCell($grouperColumns['TOTAL'] . $productRow)->getValue();

        // VALIDATE ALL GROUPER VALUES
        $this->assertEquals(
            $grouper1Expected,
            $grouper1Value,
            "GROUPER 1 should have {$grouper1Expected} units (Company 1: 210 + Company 2 branches A,B,C: 75)"
        );

        $this->assertEquals(
            $grouper2Expected,
            $grouper2Value,
            "GROUPER 2 should have {$grouper2Expected} units (Company 2 branches D,E,F: 165)"
        );

        $this->assertEquals(
            $grouper3Expected,
            $grouper3Value,
            "GROUPER 3 should have {$grouper3Expected} units (Company 3 all branches: 1000)"
        );

        $this->assertEquals(
            $grouper4Expected,
            $grouper4Value,
            "GROUPER 4 should have {$grouper4Expected} units (Company 4 all branches: 110)"
        );

        $this->assertEquals(
            $expectedTotalPedidos,
            $totalPedidosValue,
            "TOTAL PEDIDOS should be {$expectedTotalPedidos} (285 + 165 + 1000 + 110)"
        );

        // Validate production values
        $expectedProductionValues = [
            'PRODUCT_A' => [
                'adelanto_inicial' => 0,
                'elaborar_1' => $expectedTotalPedidos,
                'total_elaborado' => $expectedTotalPedidos,
                'sobrantes' => 0,
            ],
        ];

        $this->validateProductionValues($spreadsheet, $expectedProductionValues);

        // Cleanup config
        $reportConfig->delete();
    }
}
