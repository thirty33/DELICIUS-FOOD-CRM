<?php

namespace Tests\Feature\Exports;

use App\Enums\OrderStatus;
use App\Exports\OrderLineExport;
use App\Imports\Concerns\OrderLineColumnDefinition;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\ExportProcess;
use App\Models\MasterCategory;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * OrderLineExport Test - Validates Order Line Export with All Fields
 *
 * Column positions are resolved via OrderLineColumnDefinition::cell() so that
 * adding, removing, or reordering columns only requires updating the
 * shared definition — not every assertion in this file.
 */
class OrderLineExportTest extends TestCase
{
    use RefreshDatabase;

    protected PriceList $priceList;
    protected Company $company;
    protected Branch $branch;
    protected User $user;
    protected Category $category;
    protected Product $product;
    protected Order $order;
    protected OrderLine $orderLine;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-02-12 10:00:00');

        $this->priceList = PriceList::create([
            'name' => 'Lista Estándar',
            'description' => 'Lista de precios estándar',
            'min_price_order' => 0,
        ]);

        $this->company = Company::create([
            'name' => 'TEST EXPORT COMPANY S.A.',
            'fantasy_name' => 'TEST EXPORT',
            'company_code' => '11.111.111-1',
            'email' => 'export@test.cl',
            'price_list_id' => $this->priceList->id,
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'branch_code' => '11.111.111-1MAIN',
            'fantasy_name' => 'SUCURSAL PRINCIPAL',
            'min_price_order' => 0,
        ]);

        $this->user = User::create([
            'name' => 'Test Export User',
            'email' => 'export.user@test.cl',
            'nickname' => 'TEST.EXPORT',
            'password' => Hash::make('Pass123'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->category = Category::create([
            'name' => 'MINI ENSALADAS',
            'code' => 'ENS',
            'active' => true,
        ]);

        $this->product = Product::create([
            'code' => 'PROD-001',
            'name' => 'Ensalada César',
            'description' => 'Ensalada de prueba',
            'price' => 150000,
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);

        $this->order = Order::create([
            'user_id' => $this->user->id,
            'status' => OrderStatus::PROCESSED->value,
            'dispatch_date' => '2026-02-15',
            'order_number' => 12345,
            'dispatch_cost' => 0,
        ]);

        $this->orderLine = OrderLine::create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'quantity' => 5,
            'unit_price' => 150000,
            'unit_price_with_tax' => 178500,
            'partially_scheduled' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Header Tests
    // ---------------------------------------------------------------

    public function test_export_headers_match_column_definition(): void
    {
        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(
            OrderLineColumnDefinition::headers(),
            $this->readHeaderRow($sheet),
            'Export headers should match OrderLineColumnDefinition'
        );

        $this->cleanupTestFile($filePath);
    }

    public function test_headers_are_styled(): void
    {
        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headerCell = $sheet->getCell('A1');

        $this->assertTrue(
            $headerCell->getStyle()->getFont()->getBold(),
            'Headers should be bold'
        );

        $this->assertEquals(
            'E2EFDA',
            $headerCell->getStyle()->getFill()->getStartColor()->getRGB(),
            'Headers should have green background (E2EFDA)'
        );

        $this->cleanupTestFile($filePath);
    }

    // ---------------------------------------------------------------
    // Data Export Tests
    // ---------------------------------------------------------------

    public function test_exports_order_line_data_correctly(): void
    {
        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $r = 2;

        $this->assertCellEquals($sheet, 'id_orden', $r, $this->order->id);
        $this->assertCellEquals($sheet, 'codigo_de_pedido', $r, 12345);
        $this->assertCellEquals($sheet, 'estado', $r, 'Procesado');
        $this->assertCellEquals($sheet, 'fecha_de_orden', $r, '12/02/2026');
        $this->assertCellEquals($sheet, 'fecha_de_despacho', $r, '15/02/2026');
        $this->assertCellEquals($sheet, 'codigo_de_empresa', $r, '11.111.111-1');
        $this->assertCellEquals($sheet, 'empresa', $r, 'TEST EXPORT COMPANY S.A.');
        $this->assertCellEquals($sheet, 'codigo_sucursal', $r, '11.111.111-1MAIN');
        $this->assertCellEquals($sheet, 'nombre_fantasia_sucursal', $r, 'SUCURSAL PRINCIPAL');
        $this->assertCellEquals($sheet, 'usuario', $r, 'export.user@test.cl');
        $this->assertCellEquals($sheet, 'categoria', $r, 'MINI ENSALADAS');
        $this->assertCellEquals($sheet, 'codigo_de_producto', $r, 'PROD-001');
        $this->assertCellEquals($sheet, 'nombre_producto', $r, 'Ensalada César');
        $this->assertCellEquals($sheet, 'cantidad', $r, 5);
        $this->assertCellEquals($sheet, 'precio_neto', $r, 1500);
        $this->assertCellEquals($sheet, 'precio_con_impuesto', $r, 1785);
        $this->assertCellEquals($sheet, 'precio_total_neto', $r, 7500);
        $this->assertCellEquals($sheet, 'precio_total_con_impuesto', $r, 8925);
        $this->assertCellEquals($sheet, 'parcialmente_programado', $r, '0');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_multiple_order_lines(): void
    {
        $product2 = Product::create([
            'code' => 'PROD-002',
            'name' => 'Jugo Natural',
            'description' => 'Jugo de prueba',
            'price' => 80000,
            'category_id' => $this->category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
        ]);

        $orderLine2 = OrderLine::create([
            'order_id' => $this->order->id,
            'product_id' => $product2->id,
            'quantity' => 3,
            'unit_price' => 80000,
            'unit_price_with_tax' => 95200,
            'partially_scheduled' => true,
        ]);

        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id, $orderLine2->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertGreaterThanOrEqual(3, $sheet->getHighestRow(), 'Should have at least 1 header + 2 data rows');

        $col = OrderLineColumnDefinition::columnLetter('codigo_de_producto');
        $codes = [
            $sheet->getCell("{$col}2")->getValue(),
            $sheet->getCell("{$col}3")->getValue(),
        ];
        $this->assertContains('PROD-001', $codes);
        $this->assertContains('PROD-002', $codes);

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_user_nickname_when_no_email(): void
    {
        $userNoEmail = User::create([
            'name' => 'No Email User',
            'nickname' => 'TEST.NOEMAIL',
            'password' => Hash::make('Pass123'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $order = Order::create([
            'user_id' => $userNoEmail->id,
            'status' => OrderStatus::PENDING->value,
            'dispatch_date' => '2026-02-16',
            'order_number' => 12346,
            'dispatch_cost' => 0,
        ]);

        $orderLine = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 150000,
            'unit_price_with_tax' => 178500,
            'partially_scheduled' => false,
        ]);

        $filePath = $this->generateOrderLineExport(collect([$orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertCellEquals($sheet, 'usuario', 2, 'TEST.NOEMAIL');
        $this->assertCellEquals($sheet, 'estado', 2, 'Pendiente');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_partially_scheduled_flag(): void
    {
        $this->orderLine->update(['partially_scheduled' => true]);

        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertCellEquals($sheet, 'parcialmente_programado', 2, '1');

        $this->cleanupTestFile($filePath);
    }

    public function test_adds_transport_row_when_dispatch_cost_exists(): void
    {
        $this->order->update(['dispatch_cost' => 500000]); // $5,000.00

        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(3, $sheet->getHighestRow(), 'Should have 1 header + 1 data + 1 transport row');

        $this->assertCellEquals($sheet, 'nombre_producto', 3, 'TRANSPORTE');
        $this->assertCellEquals($sheet, 'cantidad', 3, 1);
        $this->assertCellEquals($sheet, 'precio_neto', 3, 5000);

        $this->cleanupTestFile($filePath);
    }

    public function test_no_transport_row_when_dispatch_cost_is_zero(): void
    {
        $this->order->update(['dispatch_cost' => 0]);

        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $this->assertEquals(2, $sheet->getHighestRow(), 'Should have 1 header + 1 data row (no transport)');

        $this->cleanupTestFile($filePath);
    }

    public function test_export_process_status_transitions(): void
    {
        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_ORDER_LINES,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $this->assertEquals(ExportProcess::STATUS_QUEUED, $exportProcess->status);

        $export = new OrderLineExport(collect([$this->orderLine->id]), $exportProcess->id);
        Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        $exportProcess->refresh();
        $this->assertEquals(ExportProcess::STATUS_PROCESSED, $exportProcess->status);
    }

    public function test_exports_user_billing_code_when_present(): void
    {
        $this->user->update(['billing_code' => 'FACT-USER-001']);

        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Codigo de Facturacion Usuario', $headers);

        $this->assertCellEquals($sheet, 'codigo_de_facturacion_usuario', 2, 'FACT-USER-001');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_product_billing_code_when_present(): void
    {
        $this->product->update(['billing_code' => 'FACT-PROD-001']);

        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Codigo de Facturacion Producto', $headers);

        $this->assertCellEquals($sheet, 'codigo_de_facturacion_producto', 2, 'FACT-PROD-001');

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_master_category_when_present(): void
    {
        $mc1 = MasterCategory::create(['name' => 'Almuerzos']);
        $mc2 = MasterCategory::create(['name' => 'Platos Fríos']);
        $this->category->masterCategories()->sync([$mc1->id, $mc2->id]);

        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Categoria Maestra', $headers);

        // Verify "Categoria Maestra" comes before "Categoría"
        $mcIndex = array_search('Categoria Maestra', $headers);
        $catIndex = array_search('Categoría', $headers);
        $this->assertLessThan($catIndex, $mcIndex, '"Categoria Maestra" should come before "Categoría"');

        // Verify comma-separated values
        $mcValue = $sheet->getCell(OrderLineColumnDefinition::cell('categoria_maestra', 2))->getValue();
        $mcNames = array_map('trim', explode(',', $mcValue));
        sort($mcNames);
        $this->assertEquals(['Almuerzos', 'Platos Fríos'], $mcNames);

        $this->cleanupTestFile($filePath);
    }

    public function test_exports_empty_master_category_when_none_associated(): void
    {
        $filePath = $this->generateOrderLineExport(collect([$this->orderLine->id]));
        $sheet = $this->loadExcelFile($filePath)->getActiveSheet();

        $headers = $this->readHeaderRow($sheet);
        $this->assertContains('Categoria Maestra', $headers);

        $this->assertCellEmpty($sheet, 'categoria_maestra', 2, 'Master category should be empty when none associated');

        $this->cleanupTestFile($filePath);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function assertCellEquals($sheet, string $columnKey, int $row, mixed $expected, ?string $message = null): void
    {
        $cell = OrderLineColumnDefinition::cell($columnKey, $row);
        $this->assertEquals(
            $expected,
            $sheet->getCell($cell)->getValue(),
            $message ?? "Cell {$cell} ({$columnKey}) should be '{$expected}'"
        );
    }

    private function assertCellEmpty($sheet, string $columnKey, int $row, ?string $message = null): void
    {
        $cell = OrderLineColumnDefinition::cell($columnKey, $row);
        $this->assertEmpty(
            $sheet->getCell($cell)->getValue(),
            $message ?? "Cell {$cell} ({$columnKey}) should be empty"
        );
    }

    protected function readHeaderRow($sheet): array
    {
        $headers = [];
        $highestColumn = $sheet->getHighestColumn();

        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $value = $sheet->getCell($col . '1')->getValue();
            if ($value) {
                $headers[] = $value;
            }
        }

        return $headers;
    }

    protected function generateOrderLineExport(Collection $orderLineIds): string
    {
        $testExportsDir = storage_path('app/test-exports');
        if (!is_dir($testExportsDir)) {
            mkdir($testExportsDir, 0755, true);
        }

        $exportProcess = ExportProcess::create([
            'type' => ExportProcess::TYPE_ORDER_LINES,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $fileName = 'test-exports/order-lines-export-' . now()->format('Y-m-d-His') . '.xlsx';
        $fullPath = storage_path('app/' . $fileName);

        $export = new OrderLineExport($orderLineIds, $exportProcess->id);
        $content = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);
        file_put_contents($fullPath, $content);

        $this->assertFileExists($fullPath);

        return $fullPath;
    }

    protected function loadExcelFile(string $filePath): Spreadsheet
    {
        $this->assertFileExists($filePath);

        return IOFactory::load($filePath);
    }

    protected function cleanupTestFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}