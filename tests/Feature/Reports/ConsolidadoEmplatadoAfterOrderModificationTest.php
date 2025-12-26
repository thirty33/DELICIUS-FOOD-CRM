<?php

namespace Tests\Feature\Reports;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderStatus;
use App\Imports\OrderLinesImport;
use App\Models\AdvanceOrder;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\ImportProcess;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PlatedDish;
use App\Models\PlatedDishIngredient;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\ProductionArea;
use App\Models\User;
use App\Models\ReportConfiguration;
use App\Models\ReportGrouper;
use App\Models\Warehouse;
use App\Repositories\ConsolidadoEmplatadoRepository;
use App\Repositories\OrderRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;
use Tests\Traits\ConfiguresImportTests;

/**
 * Test: Consolidado Emplatado Report AFTER Order Modifications via Excel Import
 *
 * TDD RED PHASE - Este test documenta el comportamiento ESPERADO (correcto).
 * Actualmente FALLARA porque el reporte usa `quantity_covered` de
 * `advance_order_order_lines` que es un SNAPSHOT congelado y nunca se actualiza.
 *
 * ESCENARIO:
 * 1. Crear 2 ordenes con 10 unidades cada una de producto HORECA
 * 2. Crear y ejecutar OP
 * 3. Verificar Excel inicial: TOTAL HORECA = 20, Cliente A = 10, Cliente B = 10
 * 4. IMPORTAR Excel modificado:
 *    - Orden 1: Reducir de 10 a 9
 *    - Orden 2: ELIMINAR (no incluir en archivo)
 * 5. Verificar Excel final: TOTAL HORECA = 9, Cliente A = 9, Cliente B = 0
 *
 * BUG: El reporte sigue mostrando TOTAL HORECA = 20 porque usa datos congelados.
 */
class ConsolidadoEmplatadoAfterOrderModificationTest extends TestCase
{
    use RefreshDatabase;
    use ConfiguresImportTests;

    private OrderRepository $orderRepository;
    private ConsolidadoEmplatadoRepository $consolidadoRepository;
    private ProductionArea $productionArea;
    private Category $category;
    private Product $productHoreca;
    private Product $productNonHoreca;
    private PlatedDish $platedDish;
    private Company $companyA;
    private Company $companyB;
    private Branch $branchA;
    private Branch $branchB;
    private User $userA;
    private User $userB;
    private User $importUser;
    private PriceList $priceList;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-12-23 10:00:00');

        // Configure test environment for imports (from trait)
        $this->configureImportTest();

        $this->orderRepository = app(OrderRepository::class);
        $this->consolidadoRepository = app(ConsolidadoEmplatadoRepository::class);

        $this->createTestEnvironment();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test: El reporte consolidado emplatado debe reflejar cambios despues de modificar ordenes via import
     *
     * FLUJO:
     * 1. Crear ordenes iniciales (Cliente A: 10, Cliente B: 10)
     * 2. Crear y ejecutar OP
     * 3. Verificar Excel ANTES de modificaciones
     * 4. Importar Excel con modificaciones (Cliente A: 9, Cliente B: eliminado)
     * 5. Verificar Excel DESPUES de modificaciones
     */
    public function test_consolidado_emplatado_reflects_changes_after_order_modification_via_import(): void
    {
        $dispatchDate = '2026-01-10';

        // =====================================================
        // FASE 1: CREAR ORDENES INICIALES
        // Cada orden tiene 2 productos: 1 HORECA y 1 NON-HORECA
        // =====================================================
        $order1 = $this->createOrder($this->userA, $dispatchDate, OrderStatus::PROCESSED);
        $orderLine1Horeca = OrderLine::create([
            'order_id' => $order1->id,
            'product_id' => $this->productHoreca->id,
            'quantity' => 10,
            'unit_price' => 5000,
            'subtotal' => 50000,
        ]);
        $orderLine1NonHoreca = OrderLine::create([
            'order_id' => $order1->id,
            'product_id' => $this->productNonHoreca->id,
            'quantity' => 5,
            'unit_price' => 1000,
            'subtotal' => 5000,
        ]);

        $order2 = $this->createOrder($this->userB, $dispatchDate, OrderStatus::PROCESSED);
        $orderLine2Horeca = OrderLine::create([
            'order_id' => $order2->id,
            'product_id' => $this->productHoreca->id,
            'quantity' => 10,
            'unit_price' => 5000,
            'subtotal' => 50000,
        ]);
        $orderLine2NonHoreca = OrderLine::create([
            'order_id' => $order2->id,
            'product_id' => $this->productNonHoreca->id,
            'quantity' => 5,
            'unit_price' => 1000,
            'subtotal' => 5000,
        ]);

        // =====================================================
        // FASE 2: CREAR Y EJECUTAR OP
        // =====================================================
        $advanceOrder = $this->orderRepository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id],
            Carbon::parse($dispatchDate)->subDay()->setTime(14, 0, 0)->toDateTimeString(),
            [$this->productionArea->id]
        );

        // Ejecutar OP
        $this->executeAdvanceOrder($advanceOrder);

        // =====================================================
        // FASE 3: VERIFICAR EXCEL ANTES DE MODIFICACIONES
        // =====================================================
        $reportDataBefore = $this->consolidadoRepository->getConsolidatedPlatedDishData(
            [$advanceOrder->id],
            false // nested format
        );

        // Debe haber 1 producto
        $this->assertCount(1, $reportDataBefore, 'Debe haber 1 producto HORECA en el reporte');

        $productDataBefore = $reportDataBefore[0];

        // Verificar totales ANTES
        $this->assertEquals(20, $productDataBefore['total_horeca'],
            'TOTAL HORECA debe ser 20 (10+10) antes de modificaciones');

        // Verificar que hay 2 ingredientes
        $this->assertCount(2, $productDataBefore['ingredients'],
            'Debe haber 2 ingredientes');

        // Verificar clientes en ingrediente 1
        $ingredient1Before = $productDataBefore['ingredients'][0];
        $clientesBefore = collect($ingredient1Before['clientes'])->keyBy('column_name');

        $this->assertEquals(10, $clientesBefore['CLIENTE A']['porciones'] ?? 0,
            'Cliente A debe tener 10 porciones antes de modificaciones');
        $this->assertEquals(10, $clientesBefore['CLIENTE B']['porciones'] ?? 0,
            'Cliente B debe tener 10 porciones antes de modificaciones');

        // =====================================================
        // FASE 4: IMPORTAR EXCEL CON MODIFICACIONES
        // =====================================================
        Storage::fake('s3');

        // Importar archivo Excel con las modificaciones:
        // - Orden 1: HORECA reducido de 10 a 9, NON-HORECA igual (5)
        // - Orden 2: Solo NON-HORECA (5), HORECA NO INCLUIDO = SE ELIMINA
        $this->importOrdersViaExcel([
            $order1->id => [
                $this->productHoreca->id => 9,      // 10 -> 9
                $this->productNonHoreca->id => 5,   // igual
            ],
            $order2->id => [
                $this->productNonHoreca->id => 5,   // igual
                // HORECA NO INCLUIDO = ELIMINADO
            ],
        ], $dispatchDate);

        // Verificar que order_line 1 HORECA se actualizo
        $orderLine1Horeca->refresh();
        $this->assertEquals(9, $orderLine1Horeca->quantity,
            'Order line 1 HORECA debe tener cantidad 9 despues de import');

        // Verificar que order_line 2 HORECA fue eliminada
        $orderLine2HorecaExists = OrderLine::where('order_id', $order2->id)
            ->where('product_id', $this->productHoreca->id)
            ->exists();
        $this->assertFalse($orderLine2HorecaExists,
            'Order line 2 HORECA debe estar eliminada despues de import');

        // Verificar que order_line 2 NON-HORECA sigue existiendo
        $orderLine2NonHoreca->refresh();
        $this->assertEquals(5, $orderLine2NonHoreca->quantity,
            'Order line 2 NON-HORECA debe seguir existiendo');

        // =====================================================
        // FASE 5: VERIFICAR EXCEL DESPUES DE MODIFICACIONES
        // =====================================================
        $reportDataAfter = $this->consolidadoRepository->getConsolidatedPlatedDishData(
            [$advanceOrder->id],
            false // nested format
        );

        // Debe seguir habiendo 1 producto
        $this->assertCount(1, $reportDataAfter,
            'Debe seguir habiendo 1 producto HORECA en el reporte');

        $productDataAfter = $reportDataAfter[0];

        // =====================================================
        // ASSERTIONS QUE DEBEN FALLAR (TDD RED)
        // =====================================================

        // BUG: TOTAL HORECA debe ser 9 (solo orden 1), no 20
        $this->assertEquals(9, $productDataAfter['total_horeca'],
            'BUG: TOTAL HORECA debe ser 9 despues de modificaciones. ' .
            'Actualmente muestra ' . $productDataAfter['total_horeca'] . ' porque usa quantity_covered congelado.');

        // Verificar clientes en ingrediente 1 DESPUES
        $ingredient1After = $productDataAfter['ingredients'][0];
        $clientesAfter = collect($ingredient1After['clientes'])->keyBy('column_name');

        // BUG: Cliente A debe mostrar 9, no 10
        $clienteAPorciones = $clientesAfter['CLIENTE A']['porciones'] ?? 0;
        $this->assertEquals(9, $clienteAPorciones,
            'BUG: Cliente A debe tener 9 porciones despues de modificaciones. ' .
            'Actualmente muestra ' . $clienteAPorciones);

        // BUG: Cliente B debe mostrar 0 (orden eliminada), no 10
        $clienteBPorciones = $clientesAfter['CLIENTE B']['porciones'] ?? 0;
        $this->assertEquals(0, $clienteBPorciones,
            'BUG: Cliente B debe tener 0 porciones (orden eliminada). ' .
            'Actualmente muestra ' . $clienteBPorciones);

        // Verificar calculo de bolsas para ingrediente 1 (Tomatican 200g/PAX)
        // 9 porciones x 200g = 1800g -> [1000, 800]
        $totalBolsasAfter = $ingredient1After['total_bolsas'];
        $this->assertContains('1 BOLSA DE 1000 GRAMOS', $totalBolsasAfter,
            'BUG: Total bolsas debe incluir 1 bolsa de 1000g');
        $this->assertContains('1 BOLSA DE 800 GRAMOS', $totalBolsasAfter,
            'BUG: Total bolsas debe incluir 1 bolsa de 800g');
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function createTestEnvironment(): void
    {
        // Create warehouse
        $this->warehouse = Warehouse::create([
            'name' => 'Bodega Principal',
            'code' => 'BOD-PRINCIPAL',
            'is_default' => true,
        ]);

        // Create production area
        $this->productionArea = ProductionArea::create([
            'name' => 'Cocina HORECA Test',
            'description' => 'Area de produccion HORECA para tests',
        ]);

        // Create category
        $this->category = Category::create([
            'name' => 'Platos HORECA',
            'code' => 'PCH',
            'description' => 'Platos Caseros HORECA',
            'active' => true,
        ]);

        // Create price list
        $this->priceList = PriceList::create([
            'name' => 'Lista HORECA Test',
            'description' => 'Precios HORECA para tests',
        ]);

        // Create HORECA product
        $this->productHoreca = Product::create([
            'name' => 'HORECA TOMATICAN DE VACUNO CON ARROZ',
            'code' => 'PCH-TOMATICAN',
            'description' => 'Plato HORECA Tomatican con Arroz',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        // Attach product to production area
        $this->productHoreca->productionAreas()->attach($this->productionArea->id);

        // Create price list line for HORECA
        PriceListLine::create([
            'price_list_id' => $this->priceList->id,
            'product_id' => $this->productHoreca->id,
            'unit_price' => 5000,
        ]);

        // Create NON-HORECA product (to test deletion of HORECA line when order still has other products)
        $this->productNonHoreca = Product::create([
            'name' => 'BEBIDA COCA COLA 350ML',
            'code' => 'BEB-COCA',
            'description' => 'Bebida no HORECA',
            'category_id' => $this->category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        // Create price list line for NON-HORECA
        PriceListLine::create([
            'price_list_id' => $this->priceList->id,
            'product_id' => $this->productNonHoreca->id,
            'unit_price' => 1000,
        ]);

        // Create PlatedDish
        $this->platedDish = PlatedDish::create([
            'product_id' => $this->productHoreca->id,
            'is_active' => true,
            'is_horeca' => true,
        ]);

        // Create 2 ingredients
        PlatedDishIngredient::create([
            'plated_dish_id' => $this->platedDish->id,
            'ingredient_name' => 'MZC - TOMATICAN DE VACUNO GRANEL',
            'measure_unit' => 'GR',
            'quantity' => 200,
            'max_quantity_horeca' => 1000,
            'order_index' => 1,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        PlatedDishIngredient::create([
            'plated_dish_id' => $this->platedDish->id,
            'ingredient_name' => 'MZC - ARROZ CASERO',
            'measure_unit' => 'GR',
            'quantity' => 220,
            'max_quantity_horeca' => 1000,
            'order_index' => 2,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);

        // Create Company A and Branch A
        $this->companyA = Company::create([
            'name' => 'CLIENTE A HORECA S.A.',
            'email' => 'clientea@horeca-test.com',
            'tax_id' => '11111111-1',
            'company_code' => 'CLIENTEA',
            'fantasy_name' => 'Cliente A',
            'price_list_id' => $this->priceList->id,
        ]);

        $this->branchA = Branch::create([
            'company_id' => $this->companyA->id,
            'address' => 'Direccion Cliente A',
            'fantasy_name' => 'CLIENTE A',
            'min_price_order' => 0,
        ]);

        // Create Company B and Branch B
        $this->companyB = Company::create([
            'name' => 'CLIENTE B HORECA S.A.',
            'email' => 'clienteb@horeca-test.com',
            'tax_id' => '22222222-2',
            'company_code' => 'CLIENTEB',
            'fantasy_name' => 'Cliente B',
            'price_list_id' => $this->priceList->id,
        ]);

        $this->branchB = Branch::create([
            'company_id' => $this->companyB->id,
            'address' => 'Direccion Cliente B',
            'fantasy_name' => 'CLIENTE B',
            'min_price_order' => 0,
        ]);

        // Create ReportConfiguration and ReportGroupers
        $reportConfig = ReportConfiguration::create([
            'name' => 'Test Config Consolidado Emplatado',
            'code' => 'TEST-CE',
            'is_active' => true,
        ]);

        $grouperA = ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'CLIENTE A',
            'code' => 'CLIA',
            'display_order' => 1,
            'is_active' => true,
        ]);
        $grouperA->companies()->attach($this->companyA->id);

        $grouperB = ReportGrouper::create([
            'report_configuration_id' => $reportConfig->id,
            'name' => 'CLIENTE B',
            'code' => 'CLIB',
            'display_order' => 2,
            'is_active' => true,
        ]);
        $grouperB->companies()->attach($this->companyB->id);

        // Create User A
        $this->userA = User::factory()->create([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA->id,
            'nickname' => 'USER.CLIENTEA',
            'email' => 'usera@clientea.com',
        ]);

        // Create User B
        $this->userB = User::factory()->create([
            'company_id' => $this->companyB->id,
            'branch_id' => $this->branchB->id,
            'nickname' => 'USER.CLIENTEB',
            'email' => 'userb@clienteb.com',
        ]);

        // Create Import User (admin)
        $this->importUser = User::factory()->create([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA->id,
            'nickname' => 'ADMIN.IMPORT',
            'email' => 'admin@import.com',
        ]);
    }

    private function createOrder(User $user, string $dispatchDate, OrderStatus $status): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'dispatch_date' => $dispatchDate,
            'status' => $status->value,
            'total' => 0,
            'order_number' => 'ORD-' . uniqid(),
        ]);
    }

    private function executeAdvanceOrder(AdvanceOrder $advanceOrder): void
    {
        $advanceOrder->update([
            'status' => AdvanceOrderStatus::EXECUTED->value,
            'executed_at' => now(),
        ]);

        // Simulate production: set produced_quantity = ordered_quantity for all products
        foreach ($advanceOrder->advanceOrderProducts as $aop) {
            $aop->update([
                'produced_quantity' => $aop->ordered_quantity,
            ]);
        }
    }

    /**
     * Import orders via Excel file (real import, not direct model modification)
     */
    private function importOrdersViaExcel(array $orderQuantities, string $dispatchDate): void
    {
        $importProcess = ImportProcess::create([
            'user_id' => $this->importUser->id,
            'type' => ImportProcess::TYPE_ORDERS,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => '-',
        ]);

        $excelFile = $this->createExcelFileForImport($orderQuantities, $dispatchDate);

        Excel::import(
            new OrderLinesImport($importProcess->id),
            $excelFile
        );

        $importProcess->refresh();
        $this->assertEquals(
            ImportProcess::STATUS_PROCESSED,
            $importProcess->status,
            'Import should complete successfully. Errors: ' . json_encode($importProcess->error_log)
        );

        if (file_exists($excelFile)) {
            unlink($excelFile);
        }
    }

    /**
     * Create Excel file with specified quantities for each order/product.
     */
    private function createExcelFileForImport(array $orderQuantities, string $dispatchDate): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers - must match OrderLinesImport expectations
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

        // Data rows
        $rowIndex = 2;
        $dispatchDateFormatted = Carbon::parse($dispatchDate)->format('d/m/Y');
        $orderDateFormatted = Carbon::now()->format('d/m/Y');

        foreach ($orderQuantities as $orderId => $products) {
            $order = Order::find($orderId);
            if (!$order) {
                continue;
            }

            $user = $order->user;
            $company = $user->company;

            foreach ($products as $productId => $quantity) {
                $product = Product::find($productId);
                if (!$product) {
                    continue;
                }

                $rowData = [
                    $order->id,
                    $order->order_number ?? '',
                    'Procesado',
                    $orderDateFormatted,
                    $dispatchDateFormatted,
                    $company->tax_id,
                    $company->name,
                    $company->tax_id,
                    $user->branch->fantasy_name ?? 'N/A',
                    $user->nickname,
                    $product->category->name ?? 'N/A',
                    $product->code,
                    $product->name,
                    $quantity,
                    5000,
                    5950,
                    $quantity * 5000,
                    $quantity * 5950,
                    '0',
                ];

                $colIndex = 1;
                foreach ($rowData as $value) {
                    $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $value);
                    $colIndex++;
                }
                $rowIndex++;
            }
        }

        // Save to temp file
        $tempFile = sys_get_temp_dir() . '/test_import_' . uniqid() . '.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempFile);

        return $tempFile;
    }
}
