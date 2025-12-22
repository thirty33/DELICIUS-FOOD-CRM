<?php

namespace Tests\Feature\Imports;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderProductionStatus;
use App\Enums\OrderStatus;
use App\Enums\WarehouseTransactionStatus;
use App\Events\AdvanceOrderExecuted;
use App\Imports\OrderLinesImport;
use App\Models\AdvanceOrder;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\ImportProcess;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\ProductionArea;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransaction;
use App\Repositories\OrderRepository;
use App\Repositories\WarehouseRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * Order Line Import Surplus Transaction Test
 *
 * Tests that when order_lines are UPDATED via Excel import and the quantity
 * is reduced below what was already produced, a warehouse transaction is
 * created to add the surplus back to inventory.
 *
 * These tests are similar to OrderLineQuantityReductionSurplusTest but
 * the modification is done via file import instead of direct model update.
 *
 * CASES:
 * 1) Fully produced order_line: qty=10, produced=10, import reduces to 8 → surplus=2
 * 2) Partially produced, no surplus: qty=5, produced=4, import reduces to 4 → surplus=0
 * 3) Partially produced, with surplus: qty=5, produced=4, import reduces to 3 → surplus=1
 * 4) Multiple products: Some with surplus, some without
 */
class OrderLineImportSurplusTransactionTest extends TestCase
{
    use RefreshDatabase;
    use ConfiguresImportTests;

    private Carbon $dispatchDate;
    private User $user;
    private User $importUser;
    private Company $company;
    private Branch $branch;
    private Category $category;
    private PriceList $priceList;
    private ProductionArea $productionArea;
    private Warehouse $warehouse;
    private Product $product;
    private Order $order;
    private OrderRepository $orderRepository;
    private WarehouseRepository $warehouseRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatchDate = Carbon::parse('2025-11-20');
        Carbon::setTestNow('2025-11-18 10:00:00');

        // Configure test environment for imports
        $this->configureImportTest();

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
     * Case 1: Fully produced order_line - Import reduces quantity, creates surplus
     *
     * - Existing order_line: qty=10, produced=10 (fully produced)
     * - Import file has qty=8 for same order/product
     * - Expected: surplus=2, warehouse transaction created, stock +2
     */
    public function test_import_reduces_fully_produced_order_line_creates_surplus_transaction(): void
    {
        Storage::fake('s3');

        // ARRANGE: Create order with qty=10
        $this->createOrder(10);

        // Create and execute OP to fully produce the order
        $this->createAndExecuteOp([$this->order->id]);

        // Verify order_line is fully produced
        $this->order->refresh();
        $orderLine = $this->order->orderLines->first();
        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $orderLine->production_status,
            'Order line should be fully produced before import'
        );

        // Get initial stock after OP execution
        $stockBefore = $this->getStock($this->product);

        // Count transactions before import
        $transactionCountBefore = WarehouseTransaction::count();

        // ACT: Import file with reduced quantity (10 → 8)
        $this->importOrderWithQuantity(8);

        // ASSERT: Surplus transaction should be created
        $transactionCountAfter = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountBefore + 1,
            $transactionCountAfter,
            'A new warehouse transaction should be created for surplus via import'
        );

        // Verify the surplus transaction
        $surplusTransaction = WarehouseTransaction::latest('id')->first();
        $this->assertStringContains('Sobrante', $surplusTransaction->reason);
        $this->assertEquals(
            WarehouseTransactionStatus::EXECUTED->value,
            $surplusTransaction->status->value,
            'Surplus transaction should have EXECUTED status'
        );

        // Verify transaction was created by the import user (not the order owner)
        $this->assertEquals(
            $this->importUser->id,
            $surplusTransaction->user_id,
            'Surplus transaction should be created by the import user'
        );

        // Verify transaction line has correct surplus (2)
        $transactionLine = $surplusTransaction->lines()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($transactionLine);
        $this->assertEquals(2, $transactionLine->difference, 'Surplus should be 2');

        // Verify stock increased by 2
        $stockAfter = $this->getStock($this->product);
        $this->assertEquals($stockBefore + 2, $stockAfter, 'Stock should increase by 2');

        // Verify order line quantity was updated
        $orderLine->refresh();
        $this->assertEquals(8, $orderLine->quantity, 'Order line quantity should be 8 after import');
    }

    /**
     * Case 2: Partially produced, no surplus - Import reduces to produced amount
     *
     * - Existing order_line: qty=5, produced=4 (partially produced)
     * - Import file has qty=4 (reducing unproduced portion only)
     * - Expected: surplus=0, NO warehouse transaction
     */
    public function test_import_reduces_partially_produced_to_exact_produced_no_surplus(): void
    {
        Storage::fake('s3');

        // ARRANGE: Create order with qty=5
        $this->createOrder(5);

        // Create OP but only produce 4 units (partial)
        $op = $this->createOpWithPartialProduction([$this->order->id], 5, 4);
        $this->executeOp($op);

        // Verify order_line is partially produced
        $this->order->refresh();
        $orderLine = $this->order->orderLines->first();
        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $orderLine->production_status
        );

        // Verify produced quantity is 4
        $produced = $this->orderRepository->getTotalProducedForProduct($this->order->id, $this->product->id);
        $this->assertEquals(4, $produced, 'Should have 4 units produced');

        // Count transactions before import
        $transactionCountBefore = WarehouseTransaction::count();
        $stockBefore = $this->getStock($this->product);

        // ACT: Import file with qty=4 (reducing 5 to 4, no surplus)
        $this->importOrderWithQuantity(4);

        // ASSERT: NO new transaction (surplus = max(0, 4-4) = 0)
        $transactionCountAfter = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountBefore,
            $transactionCountAfter,
            'No warehouse transaction should be created (no surplus)'
        );

        // Stock should remain the same
        $stockAfter = $this->getStock($this->product);
        $this->assertEquals($stockBefore, $stockAfter, 'Stock should not change');
    }

    /**
     * Case 3: Partially produced, with surplus - Import reduces below produced
     *
     * - Existing order_line: qty=5, produced=4 (partially produced)
     * - Import file has qty=3 (below produced amount)
     * - Expected: surplus=1 (4 produced - 3 new qty), warehouse transaction created
     */
    public function test_import_reduces_partially_produced_below_produced_creates_surplus(): void
    {
        Storage::fake('s3');

        // ARRANGE: Create order with qty=5
        $this->createOrder(5);

        // Create OP but only produce 4 units (partial)
        $op = $this->createOpWithPartialProduction([$this->order->id], 5, 4);
        $this->executeOp($op);

        // Verify order_line is partially produced
        $this->order->refresh();
        $orderLine = $this->order->orderLines->first();
        $this->assertEquals(
            OrderProductionStatus::PARTIALLY_PRODUCED->value,
            $orderLine->production_status
        );

        // Verify produced quantity is 4
        $produced = $this->orderRepository->getTotalProducedForProduct($this->order->id, $this->product->id);
        $this->assertEquals(4, $produced, 'Should have 4 units produced');

        // Count transactions before import
        $transactionCountBefore = WarehouseTransaction::count();
        $stockBefore = $this->getStock($this->product);

        // ACT: Import file with qty=3 (surplus = 4-3 = 1)
        $this->importOrderWithQuantity(3);

        // ASSERT: New transaction should be created for surplus of 1
        $transactionCountAfter = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountBefore + 1,
            $transactionCountAfter,
            'A new warehouse transaction should be created for surplus'
        );

        // Verify the surplus transaction
        $surplusTransaction = WarehouseTransaction::latest('id')->first();
        $this->assertStringContains('Sobrante', $surplusTransaction->reason);

        // Verify transaction was created by the import user
        $this->assertEquals(
            $this->importUser->id,
            $surplusTransaction->user_id,
            'Surplus transaction should be created by the import user'
        );

        // Verify transaction line has correct surplus (1)
        $transactionLine = $surplusTransaction->lines()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($transactionLine);
        $this->assertEquals(1, $transactionLine->difference, 'Surplus should be 1');

        // Verify stock increased by 1
        $stockAfter = $this->getStock($this->product);
        $this->assertEquals($stockBefore + 1, $stockAfter, 'Stock should increase by 1');
    }

    /**
     * Case 4: NOT produced order - Import reduces quantity, NO surplus
     *
     * - Existing order_line: qty=10, produced=0 (not produced)
     * - Import file has qty=5
     * - Expected: NO warehouse transaction (nothing was produced)
     */
    public function test_import_reduces_not_produced_order_no_surplus(): void
    {
        Storage::fake('s3');

        // ARRANGE: Create order with qty=10 but do NOT execute any OP
        $this->createOrder(10);

        // Run update command to set status
        $this->artisan('orders:update-production-status');

        // Verify order_line is NOT produced
        $this->order->refresh();
        $orderLine = $this->order->orderLines->first();
        $this->assertEquals(
            OrderProductionStatus::NOT_PRODUCED->value,
            $orderLine->production_status
        );

        $transactionCountBefore = WarehouseTransaction::count();

        // ACT: Import file with reduced quantity (10 → 5)
        $this->importOrderWithQuantity(5);

        // ASSERT: No transaction
        $transactionCountAfter = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountBefore,
            $transactionCountAfter,
            'No transaction when order is not produced'
        );

        // Verify order line quantity was updated
        $orderLine->refresh();
        $this->assertEquals(5, $orderLine->quantity, 'Order line quantity should be 5 after import');
    }

    /**
     * Case 5: Complete flow - Production, then import with reduced quantity
     *
     * Flow:
     * 1) Order created with qty=10
     * 2) OP created and executed (fully produced)
     * 3) Import file with qty=7 (-3 units)
     * 4) Verify surplus transaction created with executed status
     * 5) Verify inventory reflects the surplus (3 units added)
     */
    public function test_complete_flow_production_then_import_with_reduction(): void
    {
        Storage::fake('s3');

        // STEP 1: Create order with qty=10
        $this->createOrder(10);

        // STEP 2: Create and execute OP (produces 10 units)
        $this->createAndExecuteOp([$this->order->id]);

        // Verify fully produced
        $this->order->refresh();
        $orderLine = $this->order->orderLines->first();
        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $orderLine->production_status
        );

        $transactionCountAfterOp = WarehouseTransaction::count();
        $this->assertEquals(1, $transactionCountAfterOp, 'Should have 1 transaction after OP');

        // Get stock before import
        $stockBeforeImport = $this->getStock($this->product);

        // STEP 3: Import file with qty=7 (-3 units)
        $this->importOrderWithQuantity(7);

        // STEP 4: Verify surplus transaction created with EXECUTED status
        $transactionCountAfterImport = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountAfterOp + 1,
            $transactionCountAfterImport,
            'Surplus transaction should be created when import reduces below produced amount'
        );

        $surplusTransaction = WarehouseTransaction::latest('id')->first();
        $this->assertStringContains('Sobrante', $surplusTransaction->reason);
        $this->assertEquals(
            WarehouseTransactionStatus::EXECUTED->value,
            $surplusTransaction->status->value,
            'Surplus transaction should have EXECUTED status'
        );

        // Verify transaction was created by the import user
        $this->assertEquals(
            $this->importUser->id,
            $surplusTransaction->user_id,
            'Surplus transaction should be created by the import user'
        );

        // Verify transaction line has correct surplus (3)
        $transactionLine = $surplusTransaction->lines()->where('product_id', $this->product->id)->first();
        $this->assertNotNull($transactionLine);
        $this->assertEquals(3, $transactionLine->difference, 'Surplus should be 3 units');

        // STEP 5: Verify inventory reflects the surplus
        $stockAfterImport = $this->getStock($this->product);
        $this->assertEquals(
            $stockBeforeImport + 3,
            $stockAfterImport,
            'Stock should increase by 3 units (the surplus)'
        );

        // Verify order line quantity was updated
        $orderLine->refresh();
        $this->assertEquals(7, $orderLine->quantity, 'Order line quantity should be 7 after import');
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

        $this->product = $this->createProduct('Test Product');

        $this->priceList = PriceList::create([
            'name' => 'Test Price List',
            'is_default' => true,
        ]);

        // Create price list line for the product
        PriceListLine::create([
            'price_list_id' => $this->priceList->id,
            'product_id' => $this->product->id,
            'unit_price' => 1000,
        ]);

        $this->company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TC001',
            'fantasy_name' => 'Test Company',
            'email' => 'test.company@test.com',
            'price_list_id' => $this->priceList->id,
        ]);

        $this->branch = Branch::create([
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
            'branch_id' => $this->branch->id,
        ]);

        // User who performs imports (admin/operator)
        $this->importUser = User::create([
            'name' => 'Import Admin',
            'nickname' => 'IMPORT.ADMIN',
            'email' => 'import.admin@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();

        $this->warehouseRepository->associateProductToWarehouse($this->product, $this->warehouse, 0, 'UND');
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

    private function createOrder(int $quantity): void
    {
        $this->order = Order::create([
            'order_number' => '20251118TEST001',
            'user_id' => $this->user->id,
            'dispatch_date' => $this->dispatchDate->toDateString(),
            'date' => $this->dispatchDate->toDateString(),
            'status' => OrderStatus::PROCESSED->value,
            'total' => 10000,
            'dispatch_cost' => 0,
            'production_status_needs_update' => true,
        ]);

        OrderLine::create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 1000,
        ]);
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

    private function createOpWithPartialProduction(array $orderIds, int $originalQty, int $producedQuantity): AdvanceOrder
    {
        $preparationDatetime = $this->dispatchDate->copy()->subDay()->setTime(18, 0, 0)->toDateTimeString();

        $op = $this->orderRepository->createAdvanceOrderFromOrders(
            $orderIds,
            $preparationDatetime,
            [$this->productionArea->id]
        );

        DB::table('advance_order_products')
            ->where('advance_order_id', $op->id)
            ->where('product_id', $this->product->id)
            ->update([
                'ordered_quantity' => $originalQty,
                'ordered_quantity_new' => $producedQuantity,
                'total_to_produce' => $producedQuantity,
            ]);

        DB::table('advance_order_order_lines')
            ->where('advance_order_id', $op->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity_covered' => $originalQty]);

        return $op;
    }

    private function executeOp(AdvanceOrder $op): void
    {
        $this->actingAs($this->user);
        $op->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($op));

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

    private function importOrderWithQuantity(int $newQuantity): void
    {
        // Create import process with the user who initiated it
        $importProcess = ImportProcess::create([
            'user_id' => $this->importUser->id,
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        // Create Excel file dynamically with the new quantity
        $excelFile = $this->createExcelFile($newQuantity);

        // Import the file
        Excel::import(
            new OrderLinesImport($importProcess->id),
            $excelFile
        );

        // Verify import succeeded
        $importProcess->refresh();
        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete successfully'
        );

        // Clean up temp file
        if (file_exists($excelFile)) {
            unlink($excelFile);
        }
    }

    private function createExcelFile(int $quantity): string
    {
        // Load template file
        $templateFile = base_path('tests/Fixtures/test_single_order.xlsx');

        if (!file_exists($templateFile)) {
            // Create a simple template if it doesn't exist
            return $this->createExcelFileFromScratch($quantity);
        }

        $spreadsheet = IOFactory::load($templateFile);
        $sheet = $spreadsheet->getActiveSheet();

        // Clear existing data rows (keep headers)
        $highestRow = $sheet->getHighestRow();
        if ($highestRow > 1) {
            $sheet->removeRow(2, $highestRow - 1);
        }

        // Write our test data
        $rowData = [
            $this->order->id,                               // ID Orden
            $this->order->order_number,                     // Código de Pedido
            'Procesado',                                    // Estado
            $this->order->created_at->format('d/m/Y'),      // Fecha de Orden
            $this->dispatchDate->format('d/m/Y'),           // Fecha de Despacho
            $this->company->tax_id,                         // Código de Empresa
            $this->company->name,                           // Empresa
            $this->company->tax_id,                         // Código Sucursal
            $this->branch->fantasy_name,                    // Nombre Fantasía Sucursal
            $this->user->nickname,                          // Usuario
            $this->category->name,                          // Categoría
            $this->product->code,                           // Código de Producto
            $this->product->name,                           // Nombre Producto
            $quantity,                                      // Cantidad
            1000,                                           // Precio Neto
            1190,                                           // Precio con Impuesto
            $quantity * 1000,                               // Precio Total Neto
            $quantity * 1190,                               // Precio Total con Impuesto
            0,                                              // Parcialmente Programado
        ];

        $colIndex = 1;
        foreach ($rowData as $value) {
            $sheet->setCellValueByColumnAndRow($colIndex, 2, $value);
            $colIndex++;
        }

        // Save to temp file
        $tempFile = sys_get_temp_dir() . '/test_import_surplus_' . time() . '.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempFile);

        return $tempFile;
    }

    private function createExcelFileFromScratch(int $quantity): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $headers = [
            'ID Orden',
            'Código de Pedido',
            'Estado',
            'Fecha de Orden',
            'Fecha de Despacho',
            'Código de Empresa',
            'Empresa',
            'Código Sucursal',
            'Nombre Fantasía Sucursal',
            'Usuario',
            'Categoría',
            'Código de Producto',
            'Nombre Producto',
            'Cantidad',
            'Precio Neto',
            'Precio con Impuesto',
            'Precio Total Neto',
            'Precio Total con Impuesto',
            'Parcialmente Programado',
        ];

        $colIndex = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($colIndex, 1, $header);
            $colIndex++;
        }

        // Data row
        $rowData = [
            $this->order->id,
            $this->order->order_number,
            'Procesado',
            $this->order->created_at->format('d/m/Y'),
            $this->dispatchDate->format('d/m/Y'),
            $this->company->tax_id,
            $this->company->name,
            $this->company->tax_id,
            $this->branch->fantasy_name,
            $this->user->nickname,
            $this->category->name,
            $this->product->code,
            $this->product->name,
            $quantity,
            1000,
            1190,
            $quantity * 1000,
            $quantity * 1190,
            0,
        ];

        $colIndex = 1;
        foreach ($rowData as $value) {
            $sheet->setCellValueByColumnAndRow($colIndex, 2, $value);
            $colIndex++;
        }

        // Save to temp file
        $tempFile = sys_get_temp_dir() . '/test_import_surplus_' . time() . '.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempFile);

        return $tempFile;
    }

    private function getStock(Product $product): int
    {
        return $this->warehouseRepository->getProductStockInWarehouse(
            $product->id,
            $this->warehouse->id
        );
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '$haystack' contains '$needle'"
        );
    }
}
