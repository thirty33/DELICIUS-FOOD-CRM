<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Category;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\ProductionArea;
use App\Models\AdvanceOrder;
use App\Models\Warehouse;
use App\Models\WarehouseTransaction;
use App\Enums\OrderStatus;
use App\Enums\OrderProductionStatus;
use App\Enums\AdvanceOrderStatus;
use App\Events\AdvanceOrderExecuted;
use App\Repositories\OrderRepository;
use App\Repositories\AdvanceOrderRepository;
use App\Repositories\WarehouseRepository;
use App\Jobs\DeleteOrders;
use App\Jobs\RecalculateAdvanceOrderProductsJob;
use App\Imports\OrderLinesImport;
use App\Models\ImportProcess;
use App\Models\PriceListLine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Helpers\ReportGrouperTestHelper;
use Tests\Traits\ConfiguresImportTests;

/**
 * Test: Validar Discrepancia TOTAL PEDIDOS después de Modificar/Eliminar Order Lines
 *
 * TDD RED PHASE - Este test documenta el comportamiento ESPERADO (correcto).
 * Actualmente FALLARÁ porque existen los siguientes bugs:
 *
 * BUG 1: TOTAL PEDIDOS no se actualiza cuando se modifican order_lines
 *        - ordered_quantity_new en advance_order_products NO se recalcula
 *        - Esperado: 18, 20, 20, 9 después de modificaciones
 *        - Actual: 20, 22, 22, 20 (valores históricos)
 *
 * BUG 2: SOBRANTES no incluye líneas eliminadas, solo reducciones
 *        - Esperado ENS: 11 (1 reducción + 10 eliminación)
 *        - Actual ENS: 1 (solo cuenta reducción)
 *
 * BUG 3: No se crea transacción surplus para líneas eliminadas completamente
 *        - Esperado: 8 transacciones (4 orden1 + 4 orden2)
 *        - Actual: 7 transacciones (falta ENS eliminado de orden2)
 *
 * ESCENARIO REAL DE REFERENCIA (OP 184, 2025-12-23):
 * - Order 8844 + Order 8845 con 4 productos cada una
 * - Se importó archivo con modificaciones (-1 en cada producto)
 * - Se eliminó ENS de order 8845
 * - Resultado: TOTAL PEDIDOS no se actualizó, ENS SOBRANTES mostró 1 en vez de 11
 */
class AdvanceOrderReportTotalPedidosDiscrepancyTest extends TestCase
{
    use RefreshDatabase;
    use ReportGrouperTestHelper;
    use ConfiguresImportTests;

    private OrderRepository $orderRepository;
    private AdvanceOrderRepository $advanceOrderRepository;
    private WarehouseRepository $warehouseRepository;
    private ProductionArea $productionAreaCaliente;
    private ProductionArea $productionAreaFrio;
    private Category $categoryPPC;
    private Category $categoryPPCF;
    private Category $categoryENS;
    private Product $productPPC;
    private Product $productPPCF001;
    private Product $productPPCF002;
    private Product $productENS;
    private Company $company;
    private Branch $branch1;
    private Branch $branch2;
    private User $user1;
    private User $user2;
    private User $importUser;
    private Warehouse $warehouse;
    private PriceList $priceList;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-12-23 10:00:00');

        // Configure test environment for imports (from trait)
        $this->configureImportTest();

        $this->orderRepository = app(OrderRepository::class);
        $this->advanceOrderRepository = app(AdvanceOrderRepository::class);
        $this->warehouseRepository = app(WarehouseRepository::class);

        $this->createTestEnvironment();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test completo que valida el flujo desde creación de OP hasta modificaciones
     *
     * FLUJO:
     * 1. Crear 2 órdenes con 4 productos cada una (PPC:10, PPCF001:11, PPCF002:11, ENS:10)
     * 2. Crear OP y ejecutar
     * 3. Validar reporte inicial (consistente: TOTAL PEDIDOS == CAFETERIAS)
     * 4. Simular modificaciones:
     *    - Orden 1: Reducir 1 en cada producto
     *    - Orden 2: Reducir 1 en cada producto, ELIMINAR ENS
     * 5. Validar transacciones surplus (8 esperadas)
     * 6. Validar reporte final con valores actualizados
     */
    public function test_report_reflects_correct_values_after_order_line_modifications(): void
    {
        $dispatchDate = '2026-01-04';

        // =====================================================
        // FASE 2: CREAR ÓRDENES INICIALES
        // =====================================================
        $order1 = $this->createOrder($this->user1, $dispatchDate, OrderStatus::PROCESSED);
        $order1Lines = $this->createOrderLines($order1, [
            ['product' => $this->productPPC, 'quantity' => 10],
            ['product' => $this->productPPCF001, 'quantity' => 11],
            ['product' => $this->productPPCF002, 'quantity' => 11],
            ['product' => $this->productENS, 'quantity' => 10],
        ]);

        $order2 = $this->createOrder($this->user2, $dispatchDate, OrderStatus::PROCESSED);
        $order2Lines = $this->createOrderLines($order2, [
            ['product' => $this->productPPC, 'quantity' => 10],
            ['product' => $this->productPPCF001, 'quantity' => 11],
            ['product' => $this->productPPCF002, 'quantity' => 11],
            ['product' => $this->productENS, 'quantity' => 10],
        ]);

        // =====================================================
        // FASE 3: CREAR Y EJECUTAR OP + VALIDAR REPORTE INICIAL
        // =====================================================
        $advanceOrder = $this->orderRepository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id],
            Carbon::parse($dispatchDate)->subDay()->setTime(14, 0, 0)->toDateTimeString(),
            [$this->productionAreaCaliente->id, $this->productionAreaFrio->id]
        );

        // Verificar valores iniciales en advance_order_products
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productPPC->id, 20, 20);
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productPPCF001->id, 22, 22);
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productPPCF002->id, 22, 22);
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productENS->id, 20, 20);

        // Ejecutar OP
        $this->executeAdvanceOrder($advanceOrder);

        // Validar reporte ANTES de modificaciones (debe ser consistente)
        $reportDataBefore = $this->advanceOrderRepository->getAdvanceOrderProductsGroupedByProductionArea([$advanceOrder->id]);
        $this->assertInitialReportConsistency($reportDataBefore);

        // Guardar cantidad de transacciones antes de modificar
        $transactionCountBefore = WarehouseTransaction::count();

        // =====================================================
        // FASE 4: IMPORTAR MODIFICACIONES VIA EXCEL
        // Exactamente como se hace en producción (OrderLinesImport)
        // Esto replica el BUG: importMode=true hace que observers se salten
        // =====================================================
        Storage::fake('s3');

        // Importar archivo Excel con las modificaciones:
        // - Order 1: PPC=9, PPCF001=10, PPCF002=10, ENS=9 (reducir 1 en cada producto)
        // - Order 2: PPC=9, PPCF001=10, PPCF002=10 (reducir 1 en cada producto, ELIMINAR ENS)
        $this->importOrdersViaExcel([
            $order1->id => [
                $this->productPPC->id => 9,       // 10 → 9
                $this->productPPCF001->id => 10,  // 11 → 10
                $this->productPPCF002->id => 10,  // 11 → 10
                $this->productENS->id => 9,       // 10 → 9
            ],
            $order2->id => [
                $this->productPPC->id => 9,       // 10 → 9
                $this->productPPCF001->id => 10,  // 11 → 10
                $this->productPPCF002->id => 10,  // 11 → 10
                // ENS OMITIDO = ELIMINADO
            ],
        ], $dispatchDate);

        // Verificar que las order_lines se actualizaron correctamente
        $order1Lines['PPC']->refresh();
        $order1Lines['ENS']->refresh();
        $this->assertEquals(9, $order1Lines['PPC']->quantity, 'Order1 PPC should be 9 after import');
        $this->assertEquals(9, $order1Lines['ENS']->quantity, 'Order1 ENS should be 9 after import');

        // Verificar que ENS de order2 fue eliminado
        $order2ENS = OrderLine::where('order_id', $order2->id)
            ->where('product_id', $this->productENS->id)
            ->first();
        $this->assertNull($order2ENS, 'Order2 ENS should be deleted after import');

        // =====================================================
        // FASE 5: VALIDAR TRANSACCIONES SURPLUS
        // =====================================================

        $transactionCountAfter = WarehouseTransaction::count();
        $newTransactions = $transactionCountAfter - $transactionCountBefore;

        // BUG 3: Debe haber 8 transacciones surplus nuevas (4 por cada orden)
        // Actualmente solo se crean 7 (falta la transacción del ENS eliminado)
        $this->assertEquals(8, $newTransactions,
            'BUG 3: Debe haber 8 transacciones surplus (4 reducciones orden1 + 3 reducciones orden2 + 1 eliminación orden2). ' .
            "Actual: {$newTransactions} transacciones creadas.");

        // Verificar stock en warehouse (sobrantes acumulados)
        // PPC: 2 sobrantes (1+1)
        // PPCF001: 2 sobrantes (1+1)
        // PPCF002: 2 sobrantes (1+1)
        // ENS: 11 sobrantes (1 de reducción orden1 + 10 de eliminación orden2)
        $this->assertWarehouseStock($this->productPPC->id, 2, 'PPC debe tener 2 sobrantes');
        $this->assertWarehouseStock($this->productPPCF001->id, 2, 'PPCF001 debe tener 2 sobrantes');
        $this->assertWarehouseStock($this->productPPCF002->id, 2, 'PPCF002 debe tener 2 sobrantes');
        $this->assertWarehouseStock($this->productENS->id, 11,
            'BUG 2: ENS debe tener 11 sobrantes (1 reducción + 10 eliminación). ' .
            'Actualmente solo cuenta 1 porque no se crea transacción al eliminar.');

        // =====================================================
        // FASE 6: VALIDAR REPORTE CONSOLIDADO DESPUÉS DE MODIFICACIONES
        // =====================================================
        $reportDataAfter = $this->advanceOrderRepository->getAdvanceOrderProductsGroupedByProductionArea([$advanceOrder->id]);
        $reportProductsAfter = $this->extractProductsFromReport($reportDataAfter);

        // ASSERTIONS DE COMPORTAMIENTO ESPERADO (CORRECTO) - Estos FALLARÁN

        // PPC: Reducido de 20 a 18 (9+9), sobrantes = 2
        $this->assertReportProductTotalPedidos($reportProductsAfter, $this->productPPC->id, 18,
            'BUG 1: TOTAL PEDIDOS para PPC debe ser 18 (9+9) después de modificaciones. Actualmente muestra 20.');

        // PPCF001: Reducido de 22 a 20 (10+10), sobrantes = 2
        $this->assertReportProductTotalPedidos($reportProductsAfter, $this->productPPCF001->id, 20,
            'BUG 1: TOTAL PEDIDOS para PPCF001 debe ser 20 (10+10) después de modificaciones. Actualmente muestra 22.');

        // PPCF002: Reducido de 22 a 20 (10+10), sobrantes = 2
        $this->assertReportProductTotalPedidos($reportProductsAfter, $this->productPPCF002->id, 20,
            'BUG 1: TOTAL PEDIDOS para PPCF002 debe ser 20 (10+10) después de modificaciones. Actualmente muestra 22.');

        // ENS: Reducido de 20 a 9 (9+0), sobrantes = 11 (1 reducción + 10 eliminación)
        $this->assertReportProductTotalPedidos($reportProductsAfter, $this->productENS->id, 9,
            'BUG 1: TOTAL PEDIDOS para ENS debe ser 9 (solo queda en orden1) después de eliminación. Actualmente muestra 20.');

        // Validar consistencia: TOTAL PEDIDOS == suma de columnas grouper
        foreach ($reportProductsAfter as $productId => $productData) {
            $totalPedidos = $productData['total_ordered_quantity'];
            $columnsSum = $this->sumGrouperColumns($productData);

            $this->assertEquals($totalPedidos, $columnsSum,
                "BUG 1: TOTAL PEDIDOS ({$totalPedidos}) debe ser igual a suma de columnas grouper ({$columnsSum}) " .
                "para producto {$productId}. Esta discrepancia indica que ordered_quantity_new no se actualizó.");
        }
    }

    /**
     * Test escenario complejo: Producción repartida entre múltiples OPs
     *
     * TDD RED PHASE - Este test documenta el comportamiento ESPERADO.
     * Actualmente FALLARÁ porque ordered_quantity_new no se recalcula en múltiples OPs.
     *
     * ESCENARIO:
     * 1. Crear pedido con 10 unidades de Producto A
     * 2. Crear y ejecutar OP 1 → produce 10 unidades
     * 3. Modificar pedido: aumentar a 20 unidades
     * 4. Crear y ejecutar OP 2 → produce 10 unidades adicionales (overlap = 10)
     * 5. Modificar pedido: reducir a 12 unidades (eliminar 8)
     * 6. Validar que AMBAS OPs se recalculen correctamente
     *
     * COMPORTAMIENTO ESPERADO después de paso 5:
     * - OP 1: ordered_quantity_new = 12 (primera OP, sin overlap)
     * - OP 2: ordered_quantity_new = 0 (overlap completo de OP 1)
     * - TOTAL PEDIDOS en reporte = 12 (no 20)
     * - Surplus = 8 unidades
     */
    public function test_multiple_ops_recalculate_when_order_line_modified(): void
    {
        $dispatchDate = '2026-01-05';

        // =====================================================
        // PASO 1: Crear pedido con 10 unidades
        // =====================================================
        $order = $this->createOrder($this->user1, $dispatchDate, OrderStatus::PROCESSED);
        $orderLine = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $this->productPPC->id,
            'quantity' => 10,
            'unit_price' => 1000,
            'subtotal' => 10000,
        ]);

        // =====================================================
        // PASO 2: Crear y ejecutar OP 1
        // =====================================================
        $op1 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$order->id],
            Carbon::parse($dispatchDate)->subDay()->setTime(8, 0, 0)->toDateTimeString(),
            [$this->productionAreaCaliente->id]
        );

        // Verificar OP 1 inicial
        $this->assertAdvanceOrderProduct($op1, $this->productPPC->id, 10, 10);

        $this->executeAdvanceOrder($op1);

        // =====================================================
        // PASO 3: Modificar pedido - aumentar a 20 unidades
        // =====================================================
        // Simular: cambiar estado a PENDING, modificar, volver a PROCESSED
        $order->update(['status' => OrderStatus::PENDING->value]);
        $orderLine->update(['quantity' => 20]);
        $order->update(['status' => OrderStatus::PROCESSED->value]);

        // Refrescar el orderLine
        $orderLine->refresh();
        $this->assertEquals(20, $orderLine->quantity, 'Order line should have 20 units now');

        // =====================================================
        // PASO 4: Crear y ejecutar OP 2
        // =====================================================
        // Avanzar tiempo para que OP 2 sea "posterior" a OP 1
        Carbon::setTestNow('2025-12-23 12:00:00');

        $op2 = $this->orderRepository->createAdvanceOrderFromOrders(
            [$order->id],
            Carbon::parse($dispatchDate)->subDay()->setTime(14, 0, 0)->toDateTimeString(),
            [$this->productionAreaCaliente->id]
        );

        // Verificar OP 2 inicial:
        // ordered_quantity = 20 (valor actual)
        // ordered_quantity_new = 10 (20 - 10 overlap de OP 1)
        $this->assertAdvanceOrderProduct($op2, $this->productPPC->id, 20, 10);

        $this->executeAdvanceOrder($op2);

        // Verificar producción total: 10 (OP1) + 10 (OP2) = 20
        $totalProduced = $this->orderRepository->getTotalProducedForProduct($order->id, $this->productPPC->id);
        $this->assertEquals(20, $totalProduced, 'Total produced should be 20 (10 from OP1 + 10 from OP2)');

        // Guardar transacciones antes de modificar
        $transactionCountBefore = WarehouseTransaction::count();

        // =====================================================
        // PASO 5: Reducir cantidad a 12 (eliminar 8 unidades)
        // =====================================================
        $orderLine->update(['quantity' => 12]);

        // Verificar que se creó transacción surplus
        $transactionCountAfter = WarehouseTransaction::count();
        $this->assertEquals(
            $transactionCountBefore + 1,
            $transactionCountAfter,
            'Should create 1 surplus transaction for 8 units'
        );

        // Verificar surplus = 8 (20 producido - 12 necesario)
        $this->assertWarehouseStock($this->productPPC->id, 8, 'Should have 8 surplus units in warehouse');

        // =====================================================
        // PASO 6: VALIDAR QUE AMBAS OPs SE RECALCULARON
        // =====================================================

        // Refrescar datos de las OPs
        $op1->refresh();
        $op2->refresh();

        // ASSERTION: OP 1 debe tener ordered_quantity_new = 12
        // ordered_quantity es HISTÓRICO (valor cuando se creó la OP) - NO se actualiza
        // ordered_quantity_new es para el REPORTE (lo que la OP "reclama" ahora) - SÍ se actualiza
        $aop1 = DB::table('advance_order_products')
            ->where('advance_order_id', $op1->id)
            ->where('product_id', $this->productPPC->id)
            ->first();

        $this->assertEquals(10, $aop1->ordered_quantity,
            'ordered_quantity es HISTÓRICO y debe mantenerse en 10. Actual: ' . $aop1->ordered_quantity);
        $this->assertEquals(12, $aop1->ordered_quantity_new,
            'ordered_quantity_new debe ser 12 (sin overlap previo). Actual: ' . $aop1->ordered_quantity_new);

        // ASSERTION: OP 2 debe tener ordered_quantity_new = 0
        // (overlap completo porque OP 1 ya reclama las 12 unidades)
        $aop2 = DB::table('advance_order_products')
            ->where('advance_order_id', $op2->id)
            ->where('product_id', $this->productPPC->id)
            ->first();

        $this->assertEquals(20, $aop2->ordered_quantity,
            'ordered_quantity es HISTÓRICO y debe mantenerse en 20. Actual: ' . $aop2->ordered_quantity);
        $this->assertEquals(0, $aop2->ordered_quantity_new,
            'ordered_quantity_new debe ser 0 (overlap completo de OP 1). Actual: ' . $aop2->ordered_quantity_new);

        // =====================================================
        // VALIDAR REPORTE CONSOLIDADO
        // =====================================================
        $reportData = $this->advanceOrderRepository->getAdvanceOrderProductsGroupedByProductionArea([$op1->id, $op2->id]);
        $reportProducts = $this->extractProductsFromReport($reportData);

        // TOTAL PEDIDOS debe ser 12 (12 de OP1 + 0 de OP2)
        $this->assertReportProductTotalPedidos($reportProducts, $this->productPPC->id, 12,
            'BUG 1: TOTAL PEDIDOS debe ser 12 después de reducción. ' .
            'Actualmente suma ordered_quantity_new históricos (10 + 10 = 20) en vez de recalcular.');

        // Validar consistencia
        $totalPedidos = $reportProducts[$this->productPPC->id]['total_ordered_quantity'];
        $columnsSum = $this->sumGrouperColumns($reportProducts[$this->productPPC->id]);

        $this->assertEquals($totalPedidos, $columnsSum,
            "BUG 1: TOTAL PEDIDOS ({$totalPedidos}) debe ser igual a CAFETERIAS ({$columnsSum})");
    }

    /**
     * Test: Eliminación directa de pedidos completos (no via importador)
     *
     * TDD RED PHASE - Este test documenta el comportamiento ESPERADO.
     * Actualmente FALLARÁ porque cuando se elimina un Order completo:
     * - Los order_lines se eliminan por CASCADE en la BD (sin disparar observers)
     * - No se crean transacciones surplus de bodega
     * - No se actualiza ordered_quantity_new en la OP
     *
     * ESCENARIO (igual al primer test):
     * 1. Crear 2 órdenes con 4 productos cada una (PPC:10, PPCF001:11, PPCF002:11, ENS:10)
     * 2. Crear y ejecutar OP
     * 3. ELIMINAR orden 2 directamente (no modificar líneas)
     * 4. Validar transacciones surplus (4 productos × cantidad producida)
     * 5. Validar reporte consolidado con valores correctos
     *
     * COMPORTAMIENTO ESPERADO después de eliminar orden 2:
     * - 4 transacciones surplus (una por cada producto de orden 2)
     * - Stock: PPC +10, PPCF001 +11, PPCF002 +11, ENS +10
     * - TOTAL PEDIDOS: PPC=10, PPCF001=11, PPCF002=11, ENS=10 (solo orden 1)
     * - ordered_quantity_new actualizado en advance_order_products
     */
    public function test_order_deletion_creates_surplus_and_updates_report(): void
    {
        $dispatchDate = '2026-01-06';

        // =====================================================
        // FASE 1: CREAR ÓRDENES INICIALES
        // =====================================================
        $order1 = $this->createOrder($this->user1, $dispatchDate, OrderStatus::PROCESSED);
        $this->createOrderLines($order1, [
            ['product' => $this->productPPC, 'quantity' => 10],
            ['product' => $this->productPPCF001, 'quantity' => 11],
            ['product' => $this->productPPCF002, 'quantity' => 11],
            ['product' => $this->productENS, 'quantity' => 10],
        ]);

        $order2 = $this->createOrder($this->user2, $dispatchDate, OrderStatus::PROCESSED);
        $this->createOrderLines($order2, [
            ['product' => $this->productPPC, 'quantity' => 10],
            ['product' => $this->productPPCF001, 'quantity' => 11],
            ['product' => $this->productPPCF002, 'quantity' => 11],
            ['product' => $this->productENS, 'quantity' => 10],
        ]);

        // =====================================================
        // FASE 2: CREAR Y EJECUTAR OP
        // =====================================================
        $advanceOrder = $this->orderRepository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id],
            Carbon::parse($dispatchDate)->subDay()->setTime(14, 0, 0)->toDateTimeString(),
            [$this->productionAreaCaliente->id, $this->productionAreaFrio->id]
        );

        // Verificar valores iniciales
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productPPC->id, 20, 20);
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productPPCF001->id, 22, 22);
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productPPCF002->id, 22, 22);
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productENS->id, 20, 20);

        // Ejecutar OP
        $this->executeAdvanceOrder($advanceOrder);

        // Actualizar production_status de las órdenes
        $this->artisan('orders:update-production-status');
        $order1->refresh();
        $order2->refresh();

        // Verificar que las órdenes están marcadas como producidas
        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $order2->production_status,
            'Order 2 debe estar FULLY_PRODUCED antes de eliminar'
        );

        // Guardar cantidad de transacciones antes de eliminar
        $transactionCountBefore = WarehouseTransaction::count();

        // =====================================================
        // FASE 3: ELIMINAR ORDEN 2 DIRECTAMENTE
        // =====================================================
        $order2->delete();

        // =====================================================
        // FASE 4: VALIDAR TRANSACCIONES SURPLUS
        // =====================================================
        $transactionCountAfter = WarehouseTransaction::count();
        $newTransactions = $transactionCountAfter - $transactionCountBefore;

        // Debe haber 4 transacciones surplus (una por cada producto de orden 2)
        $this->assertEquals(4, $newTransactions,
            'BUG: Debe haber 4 transacciones surplus al eliminar orden 2 (1 por producto). ' .
            "Actual: {$newTransactions}. Los order_lines se eliminan por CASCADE sin disparar observers.");

        // Verificar stock en warehouse (sobrantes de orden 2)
        // PPC: 10 sobrantes (todo lo producido para orden 2)
        // PPCF001: 11 sobrantes
        // PPCF002: 11 sobrantes
        // ENS: 10 sobrantes
        $this->assertWarehouseStock($this->productPPC->id, 10,
            'PPC debe tener 10 sobrantes (producción de orden 2 eliminada)');
        $this->assertWarehouseStock($this->productPPCF001->id, 11,
            'PPCF001 debe tener 11 sobrantes (producción de orden 2 eliminada)');
        $this->assertWarehouseStock($this->productPPCF002->id, 11,
            'PPCF002 debe tener 11 sobrantes (producción de orden 2 eliminada)');
        $this->assertWarehouseStock($this->productENS->id, 10,
            'ENS debe tener 10 sobrantes (producción de orden 2 eliminada)');

        // =====================================================
        // FASE 5: VALIDAR REPORTE CONSOLIDADO
        // =====================================================
        $reportData = $this->advanceOrderRepository->getAdvanceOrderProductsGroupedByProductionArea([$advanceOrder->id]);
        $reportProducts = $this->extractProductsFromReport($reportData);

        // TOTAL PEDIDOS debe reflejar solo orden 1 (orden 2 fue eliminada)
        $this->assertReportProductTotalPedidos($reportProducts, $this->productPPC->id, 10,
            'BUG: TOTAL PEDIDOS para PPC debe ser 10 (solo orden 1). Orden 2 fue eliminada.');

        $this->assertReportProductTotalPedidos($reportProducts, $this->productPPCF001->id, 11,
            'BUG: TOTAL PEDIDOS para PPCF001 debe ser 11 (solo orden 1). Orden 2 fue eliminada.');

        $this->assertReportProductTotalPedidos($reportProducts, $this->productPPCF002->id, 11,
            'BUG: TOTAL PEDIDOS para PPCF002 debe ser 11 (solo orden 1). Orden 2 fue eliminada.');

        $this->assertReportProductTotalPedidos($reportProducts, $this->productENS->id, 10,
            'BUG: TOTAL PEDIDOS para ENS debe ser 10 (solo orden 1). Orden 2 fue eliminada.');

        // Validar consistencia: TOTAL PEDIDOS == suma de columnas grouper
        foreach ($reportProducts as $productId => $productData) {
            $totalPedidos = $productData['total_ordered_quantity'];
            $columnsSum = $this->sumGrouperColumns($productData);

            $this->assertEquals($totalPedidos, $columnsSum,
                "BUG: TOTAL PEDIDOS ({$totalPedidos}) debe ser igual a suma de columnas grouper ({$columnsSum}) " .
                "para producto {$productId}.");
        }

        // Validar que ordered_quantity_new se actualizó en advance_order_products
        $aopPPC = DB::table('advance_order_products')
            ->where('advance_order_id', $advanceOrder->id)
            ->where('product_id', $this->productPPC->id)
            ->first();

        $this->assertEquals(10, $aopPPC->ordered_quantity_new,
            'BUG: ordered_quantity_new para PPC debe ser 10 después de eliminar orden 2. ' .
            'Actual: ' . $aopPPC->ordered_quantity_new);
    }

    /**
     * Test: Eliminación via DeleteOrders Job (exactamente como Filament Resource)
     *
     * Este test replica el flujo EXACTO de eliminación desde Filament:
     * - DeleteOrders::dispatch([$orderId])
     * - El Job llama $order->delete()
     * - OrderDeletionObserver elimina order_lines individualmente
     * - Se crean transacciones surplus
     *
     * @see app/Filament/Resources/OrderResource.php línea 396
     */
    public function test_order_deletion_via_delete_orders_job_creates_surplus(): void
    {
        $dispatchDate = '2026-01-07';

        // =====================================================
        // FASE 1: CREAR ÓRDENES INICIALES
        // =====================================================
        $order1 = $this->createOrder($this->user1, $dispatchDate, OrderStatus::PROCESSED);
        $this->createOrderLines($order1, [
            ['product' => $this->productPPC, 'quantity' => 10],
            ['product' => $this->productPPCF001, 'quantity' => 11],
            ['product' => $this->productPPCF002, 'quantity' => 11],
            ['product' => $this->productENS, 'quantity' => 10],
        ]);

        $order2 = $this->createOrder($this->user2, $dispatchDate, OrderStatus::PROCESSED);
        $this->createOrderLines($order2, [
            ['product' => $this->productPPC, 'quantity' => 10],
            ['product' => $this->productPPCF001, 'quantity' => 11],
            ['product' => $this->productPPCF002, 'quantity' => 11],
            ['product' => $this->productENS, 'quantity' => 10],
        ]);

        // =====================================================
        // FASE 2: CREAR Y EJECUTAR OP
        // =====================================================
        $advanceOrder = $this->orderRepository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id],
            Carbon::parse($dispatchDate)->subDay()->setTime(14, 0, 0)->toDateTimeString(),
            [$this->productionAreaCaliente->id, $this->productionAreaFrio->id]
        );

        $this->executeAdvanceOrder($advanceOrder);

        // Actualizar production_status de las órdenes
        $this->artisan('orders:update-production-status');
        $order1->refresh();
        $order2->refresh();

        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $order2->production_status,
            'Order 2 debe estar FULLY_PRODUCED antes de eliminar'
        );

        // Guardar cantidad de transacciones antes de eliminar
        $transactionCountBefore = WarehouseTransaction::count();

        // =====================================================
        // FASE 3: ELIMINAR ORDEN VÍA DeleteOrders JOB
        // (Exactamente como lo hace Filament Resource)
        // =====================================================
        $orderIdToDelete = $order2->id;

        // Dispatch y ejecutar el Job sincrónicamente (como en producción con queue:work)
        // Pasamos el userId para que auth()->id() funcione en los Observers
        DeleteOrders::dispatchSync([$orderIdToDelete], $this->user1->id);

        // =====================================================
        // FASE 4: VALIDAR TRANSACCIONES SURPLUS
        // =====================================================
        $transactionCountAfter = WarehouseTransaction::count();
        $newTransactions = $transactionCountAfter - $transactionCountBefore;

        // Debe haber 4 transacciones surplus (una por cada producto de orden 2)
        $this->assertEquals(4, $newTransactions,
            'Debe haber 4 transacciones surplus al eliminar orden via DeleteOrders Job. ' .
            "Actual: {$newTransactions}.");

        // Verificar stock en warehouse
        $this->assertWarehouseStock($this->productPPC->id, 10,
            'PPC debe tener 10 sobrantes');
        $this->assertWarehouseStock($this->productPPCF001->id, 11,
            'PPCF001 debe tener 11 sobrantes');
        $this->assertWarehouseStock($this->productPPCF002->id, 11,
            'PPCF002 debe tener 11 sobrantes');
        $this->assertWarehouseStock($this->productENS->id, 10,
            'ENS debe tener 10 sobrantes');

        // Verificar que la orden fue eliminada
        $this->assertNull(Order::find($orderIdToDelete), 'Order 2 debe haber sido eliminada');
    }

    /**
     * Test: ordered_quantity_new debe actualizarse cuando se eliminan órdenes via Job
     *
     * TDD RED PHASE - Este test documenta el BUG actual:
     * Cuando se eliminan órdenes mediante DeleteOrders Job en producción (async queue):
     * 1. Las transacciones surplus SÍ se crean (ya arreglado)
     * 2. Pero ordered_quantity_new NO se actualiza porque:
     *    - El Job RecalculateAdvanceOrderProductsJob busca pivots por order_line_id
     *    - Cuando el Job se ejecuta async, los pivots ya fueron eliminados
     *    - El Job no encuentra OPs y no hace nada
     *
     * SIMULACIÓN DEL COMPORTAMIENTO ASYNC:
     * 1. Usar Queue::fake() para capturar los Jobs
     * 2. Eliminar la orden (los pivots se eliminan)
     * 3. Ejecutar los Jobs manualmente (simulando queue:work)
     * 4. En este punto, los pivots ya no existen → Job no encuentra nada
     *
     * ESCENARIO:
     * 1. Crear 2 órdenes con 4 productos cada una (PPC:10, PPCF001:11, PPCF002:11, ENS:10)
     * 2. Crear y ejecutar OP → ordered_quantity_new = 20, 22, 22, 20
     * 3. Eliminar orden 2 via DeleteOrders Job (simulando async)
     * 4. ESPERADO: ordered_quantity_new = 10, 11, 11, 10 (solo orden 1)
     * 5. ACTUAL BUG: ordered_quantity_new = 20, 22, 22, 20 (no se actualiza)
     *
     * LOG DEL BUG EN PRODUCCIÓN:
     * RecalculateAdvanceOrderProductsJob: No OPs found covering this order_line
     */
    public function test_ordered_quantity_new_updates_when_order_deleted_via_job(): void
    {
        $dispatchDate = '2026-01-08';

        // =====================================================
        // FASE 1: CREAR ÓRDENES INICIALES
        // =====================================================
        $order1 = $this->createOrder($this->user1, $dispatchDate, OrderStatus::PROCESSED);
        $order1Lines = $this->createOrderLines($order1, [
            ['product' => $this->productPPC, 'quantity' => 10],
            ['product' => $this->productPPCF001, 'quantity' => 11],
            ['product' => $this->productPPCF002, 'quantity' => 11],
            ['product' => $this->productENS, 'quantity' => 10],
        ]);

        $order2 = $this->createOrder($this->user2, $dispatchDate, OrderStatus::PROCESSED);
        $order2Lines = $this->createOrderLines($order2, [
            ['product' => $this->productPPC, 'quantity' => 10],
            ['product' => $this->productPPCF001, 'quantity' => 11],
            ['product' => $this->productPPCF002, 'quantity' => 11],
            ['product' => $this->productENS, 'quantity' => 10],
        ]);

        // Guardar los IDs de las order_lines de orden 2 ANTES de eliminar
        $order2LineIds = collect($order2Lines)->pluck('id')->toArray();

        // =====================================================
        // FASE 2: CREAR Y EJECUTAR OP
        // =====================================================
        $advanceOrder = $this->orderRepository->createAdvanceOrderFromOrders(
            [$order1->id, $order2->id],
            Carbon::parse($dispatchDate)->subDay()->setTime(14, 0, 0)->toDateTimeString(),
            [$this->productionAreaCaliente->id, $this->productionAreaFrio->id]
        );

        // Verificar valores iniciales: ordered_quantity_new = ordered_quantity
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productPPC->id, 20, 20);
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productPPCF001->id, 22, 22);
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productPPCF002->id, 22, 22);
        $this->assertAdvanceOrderProduct($advanceOrder, $this->productENS->id, 20, 20);

        $this->executeAdvanceOrder($advanceOrder);

        // Actualizar production_status
        $this->artisan('orders:update-production-status');
        $order2->refresh();

        $this->assertEquals(
            OrderProductionStatus::FULLY_PRODUCED->value,
            $order2->production_status,
            'Order 2 debe estar FULLY_PRODUCED antes de eliminar'
        );

        // =====================================================
        // FASE 3: SIMULAR ELIMINACIÓN ASYNC
        // Capturar RecalculateAdvanceOrderProductsJob con Queue::fake()
        // =====================================================
        Queue::fake([RecalculateAdvanceOrderProductsJob::class]);

        // Eliminar la orden - esto dispara el Observer que despacha Jobs a la cola fake
        // Los pivots (advance_order_order_lines) se eliminan AHORA
        $order2->delete();

        // Verificar que la orden fue eliminada
        $this->assertNull(Order::find($order2->id), 'Order 2 debe haber sido eliminada');

        // Verificar que los Jobs fueron despachados
        Queue::assertPushed(RecalculateAdvanceOrderProductsJob::class, 4); // 4 productos

        // Verificar que los pivots ya NO existen (fueron eliminados con la orden)
        $pivotCount = DB::table('advance_order_order_lines')
            ->whereIn('order_line_id', $order2LineIds)
            ->count();
        $this->assertEquals(0, $pivotCount,
            'Los pivots de order_lines de orden 2 deben estar eliminados');

        // =====================================================
        // FASE 4: EJECUTAR LOS JOBS MANUALMENTE (simulando queue:work)
        // En este punto, los pivots ya no existen
        // =====================================================
        Queue::assertPushed(RecalculateAdvanceOrderProductsJob::class, function ($job) {
            // Ejecutar el job manualmente
            $job->handle(app(\App\Repositories\AdvanceOrderRepository::class));
            return true;
        });

        // =====================================================
        // FASE 5: VALIDAR ordered_quantity_new
        // BUG: NO se actualiza porque el Job no encontró los pivots
        // =====================================================

        // PPC: ESPERADO = 10, ACTUAL BUG = 20
        $aopPPC = DB::table('advance_order_products')
            ->where('advance_order_id', $advanceOrder->id)
            ->where('product_id', $this->productPPC->id)
            ->first();

        $this->assertEquals(20, $aopPPC->ordered_quantity,
            'ordered_quantity es HISTÓRICO y debe mantenerse en 20');
        $this->assertEquals(10, $aopPPC->ordered_quantity_new,
            'BUG: ordered_quantity_new debe ser 10 (solo orden 1). ' .
            'Actual: ' . $aopPPC->ordered_quantity_new . '. ' .
            'El Job no encuentra los pivots porque ya fueron eliminados al momento de ejecutarse async.');

        // PPCF001: ESPERADO = 11, ACTUAL BUG = 22
        $aopPPCF001 = DB::table('advance_order_products')
            ->where('advance_order_id', $advanceOrder->id)
            ->where('product_id', $this->productPPCF001->id)
            ->first();

        $this->assertEquals(11, $aopPPCF001->ordered_quantity_new,
            'BUG: ordered_quantity_new debe ser 11. Actual: ' . $aopPPCF001->ordered_quantity_new);

        // PPCF002: ESPERADO = 11, ACTUAL BUG = 22
        $aopPPCF002 = DB::table('advance_order_products')
            ->where('advance_order_id', $advanceOrder->id)
            ->where('product_id', $this->productPPCF002->id)
            ->first();

        $this->assertEquals(11, $aopPPCF002->ordered_quantity_new,
            'BUG: ordered_quantity_new debe ser 11. Actual: ' . $aopPPCF002->ordered_quantity_new);

        // ENS: ESPERADO = 10, ACTUAL BUG = 20
        $aopENS = DB::table('advance_order_products')
            ->where('advance_order_id', $advanceOrder->id)
            ->where('product_id', $this->productENS->id)
            ->first();

        $this->assertEquals(10, $aopENS->ordered_quantity_new,
            'BUG: ordered_quantity_new debe ser 10. Actual: ' . $aopENS->ordered_quantity_new);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function createTestEnvironment(): void
    {
        // Crear áreas de producción
        $this->productionAreaCaliente = ProductionArea::create([
            'name' => 'CUARTO CALIENTE',
            'description' => 'Área de producción caliente',
        ]);

        $this->productionAreaFrio = ProductionArea::create([
            'name' => 'CUARTO FRIO ENSALADAS',
            'description' => 'Área de ensaladas frías',
        ]);

        // Crear categorías
        $this->categoryPPC = Category::create([
            'name' => 'PLATOS VARIABLES PARA CALENTAR',
            'description' => 'Categoría para platos variables',
        ]);

        $this->categoryPPCF = Category::create([
            'name' => 'PLATOS FIJOS PARA CALENTAR',
            'description' => 'Categoría para platos fijos',
        ]);

        $this->categoryENS = Category::create([
            'name' => 'ENSALADAS CLASICAS',
            'description' => 'Categoría para ensaladas',
        ]);

        // Crear productos
        $this->productPPC = $this->createProduct(
            'PPC - CERDO EN SALSA BBQ CON PAPAS DORADAS',
            'TEST_PPC001',
            $this->categoryPPC,
            $this->productionAreaCaliente
        );

        $this->productPPCF001 = $this->createProduct(
            'PPCF - LASANA FLORENTINA DE POLLO',
            'TEST_PPCF001',
            $this->categoryPPCF,
            $this->productionAreaCaliente
        );

        $this->productPPCF002 = $this->createProduct(
            'PPCF - LASANA GOURMET BOLONESA',
            'TEST_PPCF002',
            $this->categoryPPCF,
            $this->productionAreaCaliente
        );

        $this->productENS = $this->createProduct(
            'ENS - ENSALADA JAMON SERRANO',
            'TEST_ENS001',
            $this->categoryENS,
            $this->productionAreaFrio
        );

        // Crear price list
        $this->priceList = PriceList::create([
            'name' => 'Test Price List',
            'is_default' => true,
        ]);

        // Crear company, branches, users
        $this->company = Company::create([
            'name' => 'TEST CAFETERIA S.A.',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TESTCAFE',
            'fantasy_name' => 'Test Cafeteria',
            'email' => 'test@cafeteria.com',
            'price_list_id' => $this->priceList->id,
            'exclude_from_consolidated_report' => false,
        ]);

        $this->branch1 = Branch::create([
            'company_id' => $this->company->id,
            'shipping_address' => 'Sucursal 1 Address',
            'fantasy_name' => 'TEST SUCURSAL 1',
            'min_price_order' => 0,
        ]);

        $this->branch2 = Branch::create([
            'company_id' => $this->company->id,
            'shipping_address' => 'Sucursal 2 Address',
            'fantasy_name' => 'TEST SUCURSAL 2',
            'min_price_order' => 0,
        ]);

        $this->user1 = User::create([
            'name' => 'Test User 1',
            'nickname' => 'TEST.USER1',
            'email' => 'test.user1@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1->id,
        ]);

        $this->user2 = User::create([
            'name' => 'Test User 2',
            'nickname' => 'TEST.USER2',
            'email' => 'test.user2@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch2->id,
        ]);

        // User who performs imports (admin/operator)
        $this->importUser = User::create([
            'name' => 'Import Admin',
            'nickname' => 'IMPORT.ADMIN',
            'email' => 'import.admin@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch1->id,
        ]);

        // Create PriceListLines for import validation
        foreach ([$this->productPPC, $this->productPPCF001, $this->productPPCF002, $this->productENS] as $product) {
            PriceListLine::create([
                'price_list_id' => $this->priceList->id,
                'product_id' => $product->id,
                'unit_price' => 1000,
            ]);
        }

        // Crear warehouse y asociar productos
        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();

        foreach ([$this->productPPC, $this->productPPCF001, $this->productPPCF002, $this->productENS] as $product) {
            $this->warehouseRepository->associateProductToWarehouse($product, $this->warehouse, 0, 'UND');
        }

        // Crear ReportGrouper para CAFETERIAS
        $this->createReportGrouperWithCompanies(
            'CAFETERIAS',
            'CAFE',
            [$this->company->id],
            1
        );
    }

    private function createProduct(string $name, string $code, Category $category, ProductionArea $area): Product
    {
        $product = Product::create([
            'name' => $name,
            'description' => "Description for {$name}",
            'code' => $code,
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'stock' => 0,
        ]);

        $product->productionAreas()->attach($area->id);

        return $product;
    }

    private function createOrder(User $user, string $dispatchDate, OrderStatus $status): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'dispatch_date' => $dispatchDate,
            'date' => $dispatchDate,
            'status' => $status->value,
            'total' => 10000,
            'total_with_tax' => 11900,
            'tax_amount' => 1900,
            'grand_total' => 11900,
            'dispatch_cost' => 0,
            'production_status_needs_update' => true,
        ]);
    }

    private function createOrderLines(Order $order, array $lines): array
    {
        $createdLines = [];

        foreach ($lines as $line) {
            $orderLine = OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_price' => 1000,
                'subtotal' => $line['quantity'] * 1000,
            ]);

            // Use product code prefix as key for easy reference
            $productName = $line['product']->name;
            if (str_starts_with($productName, 'PPC - ')) {
                $key = 'PPC';
            } elseif (str_starts_with($productName, 'PPCF - LASANA FLORENTINA')) {
                $key = 'PPCF001';
            } elseif (str_starts_with($productName, 'PPCF - LASANA GOURMET')) {
                $key = 'PPCF002';
            } elseif (str_starts_with($productName, 'ENS - ')) {
                $key = 'ENS';
            } else {
                $key = $line['product']->code;
            }

            $createdLines[$key] = $orderLine;
        }

        return $createdLines;
    }

    private function executeAdvanceOrder(AdvanceOrder $advanceOrder): void
    {
        $this->actingAs($this->user1);
        $advanceOrder->update(['status' => AdvanceOrderStatus::EXECUTED]);
        event(new AdvanceOrderExecuted($advanceOrder));

        $relatedOrderIds = DB::table('advance_order_orders')
            ->where('advance_order_id', $advanceOrder->id)
            ->pluck('order_id')
            ->toArray();

        if (!empty($relatedOrderIds)) {
            DB::table('orders')
                ->whereIn('id', $relatedOrderIds)
                ->update(['production_status_needs_update' => true]);
        }

        $this->artisan('orders:update-production-status');
    }

    private function assertAdvanceOrderProduct(AdvanceOrder $advanceOrder, int $productId, int $expectedOrdered, int $expectedOrderedNew): void
    {
        $aop = DB::table('advance_order_products')
            ->where('advance_order_id', $advanceOrder->id)
            ->where('product_id', $productId)
            ->first();

        $this->assertNotNull($aop, "advance_order_products entry should exist for product {$productId}");
        $this->assertEquals($expectedOrdered, $aop->ordered_quantity,
            "ordered_quantity should be {$expectedOrdered} for product {$productId}");
        $this->assertEquals($expectedOrderedNew, $aop->ordered_quantity_new,
            "ordered_quantity_new should be {$expectedOrderedNew} for product {$productId}");
    }

    private function assertWarehouseStock(int $productId, int $expectedStock, string $message): void
    {
        $stock = DB::table('warehouse_product')
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $productId)
            ->value('stock') ?? 0;

        $this->assertEquals($expectedStock, $stock, $message);
    }

    private function assertInitialReportConsistency($reportData): void
    {
        $products = $this->extractProductsFromReport($reportData);

        // Before any modifications, TOTAL PEDIDOS should match sum of columns
        $this->assertReportProductTotalPedidos($products, $this->productPPC->id, 20,
            'Initial PPC TOTAL PEDIDOS should be 20');
        $this->assertReportProductTotalPedidos($products, $this->productPPCF001->id, 22,
            'Initial PPCF001 TOTAL PEDIDOS should be 22');
        $this->assertReportProductTotalPedidos($products, $this->productPPCF002->id, 22,
            'Initial PPCF002 TOTAL PEDIDOS should be 22');
        $this->assertReportProductTotalPedidos($products, $this->productENS->id, 20,
            'Initial ENS TOTAL PEDIDOS should be 20');
    }

    private function extractProductsFromReport($reportData): array
    {
        $products = [];

        foreach ($reportData as $area) {
            if (isset($area['products'])) {
                foreach ($area['products'] as $product) {
                    $products[$product['product_id']] = $product;
                }
            }
        }

        return $products;
    }

    private function assertReportProductTotalPedidos(array $reportProducts, int $productId, int $expectedTotalPedidos, string $message): void
    {
        $this->assertArrayHasKey($productId, $reportProducts,
            "Product {$productId} should exist in report");

        $product = $reportProducts[$productId];
        $actualTotal = $product['total_ordered_quantity'];

        $this->assertEquals($expectedTotalPedidos, $actualTotal, $message);
    }

    private function sumGrouperColumns(array $productData): int
    {
        if (!isset($productData['columns'])) {
            return 0;
        }

        $sum = 0;
        foreach ($productData['columns'] as $column) {
            if (isset($column['total_quantity'])) {
                $sum += $column['total_quantity'];
            }
        }

        return $sum;
    }

    // =========================================================================
    // EXCEL IMPORT HELPER METHODS
    // =========================================================================

    /**
     * Import orders via Excel file using OrderLinesImport.
     * This replicates EXACTLY how imports work in production.
     *
     * @param array $orderQuantities Format: [order_id => [product_id => quantity, ...], ...]
     * @param string $dispatchDate The dispatch date for the orders
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
     * The file structure matches exactly what OrderLinesImport expects.
     *
     * @param array $orderQuantities Format: [order_id => [product_id => quantity, ...], ...]
     * @param string $dispatchDate The dispatch date for the orders
     * @return string Path to the created Excel file
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

            foreach ($products as $productId => $quantity) {
                $product = Product::find($productId);
                if (!$product) {
                    continue;
                }

                $rowData = [
                    $order->id,                                    // ID Orden
                    $order->order_number ?? '',                    // Código de Pedido
                    'Procesado',                                   // Estado
                    $orderDateFormatted,                           // Fecha de Orden
                    $dispatchDateFormatted,                        // Fecha de Despacho
                    $this->company->tax_id,                        // Código de Empresa
                    $this->company->name,                          // Empresa
                    $this->company->tax_id,                        // Código Sucursal
                    $user->branch->fantasy_name ?? 'N/A',          // Nombre Fantasía Sucursal
                    $user->nickname,                               // Usuario
                    $product->category->name ?? 'N/A',             // Categoría
                    $product->code,                                // Código de Producto
                    $product->name,                                // Nombre Producto
                    $quantity,                                     // Cantidad
                    1000,                                          // Precio Neto
                    1190,                                          // Precio con Impuesto
                    $quantity * 1000,                              // Precio Total Neto
                    $quantity * 1190,                              // Precio Total con Impuesto
                    0,                                             // Parcialmente Programado
                ];

                $colIndex = 1;
                foreach ($rowData as $value) {
                    $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $value);
                    $colIndex++;
                }
                $rowIndex++;
            }
        }

        $tempFile = sys_get_temp_dir() . '/test_import_op_recalc_' . time() . '.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempFile);

        return $tempFile;
    }
}
